<?php
/**
 * SPENCE Receipt API — Synchronous receipt scan + inventory ingest
 * Replaces the watcher.sh async pipeline for JPEG/PNG receipts.
 * PDFs still fall through to upload.php → watcher.sh.
 */
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/matching.php';
ob_clean();
header('Content-Type: application/json');

set_time_limit(120); // Vision calls can take up to 60s on a busy receipt

$_key_file = __DIR__ . '/openrouter.env';
$api_key = file_exists($_key_file)
    ? trim(file_get_contents($_key_file))
    : (getenv('OPENROUTER_API_KEY') ?: '');

try {
    if (($_POST['action'] ?? '') !== 'scan_receipt') {
        throw new Exception("Unknown action.");
    }

    if (!$api_key) throw new Exception("OpenRouter API key not configured.");

    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No file received.");
    }

    $file    = $_FILES['receipt'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($file['tmp_name']);
    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    if (!array_key_exists($detected, $allowed)) {
        throw new Exception("Only JPEG and PNG are supported for live scan. For PDF receipts, use the legacy upload button.");
    }

    // Save file for audit trail (same as upload.php)
    $uploadDir = __DIR__ . '/../uploads/';
    $filename  = bin2hex(random_bytes(8)) . '.' . $allowed[$detected];
    $targetPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to save uploaded file.");
    }

    $b64 = base64_encode(file_get_contents($targetPath));

    // Identical prompt to watcher.sh — single source of truth for receipt parsing behaviour
    $prompt =
        "Task: High-precision receipt extraction for Australian Supermarkets (Woolworths, Coles, Aldi).\n\n"
        . "Format Logic:\n"
        . "1. Standard Line: 'PRODUCT NAME' on the left, 'TOTAL PRICE' on the far right.\n"
        . "2. Multiples Handling:\n"
        . "   - Woolworths: The total price for multiples is usually on the second line alongside quantity info (e.g., '2 @ \$3.00').\n"
        . "   - Coles/Aldi: The total price for multiples is often on the first line with the product name.\n"
        . "   - Always prioritize the value in the far-right column as the line's price.\n"
        . "3. Sticker Fusion: If product stickers (e.g. deli weight/unit-price) are visible, use them to resolve missing weight data.\n\n"
        . "Extraction Instructions:\n"
        . "- Extract EVERY line item.\n"
        . "- Product Name: Fix abbreviations and OCR noise. Expand to human-readable Title Case.\n"
        . "- Multiples: If quantity > 1 (e.g. 2 cans), return a separate JSON object for EACH unit.\n\n"
        . "Unit & Consumption Protocol (CRITICAL):\n"
        . "1. Normalization: Strip all weight, volume, and count descriptors from the 'product' name.\n"
        . "2. 'unit' Selection: Determine based on how the item is CONSUMED, not just how it is sold.\n"
        . "   - 'ea' (Each): Use ONLY for items naturally consumed as whole units (eggs, buns, avocados, multi-pack snacks).\n"
        . "     - Example: 'Milk Buns 4pk 300g' -> product='Milk Buns', unit='ea', amount=4, weight_per_ea=0.075.\n"
        . "     - Example: 'Free Range Eggs 600g' -> product='Free Range Eggs', unit='ea', amount=12, weight_per_ea=0.050.\n"
        . "   - 'kg' / 'L' (Weight/Volume): Use for items consumed in partial portions (cheese, deli meats, yogurt tubs, oil).\n"
        . "     - Example: 'Burger Slices 200g' -> product='Burger Slices', unit='kg', amount=0.200, weight_per_ea=1.0.\n"
        . "     - Example: 'Olive Oil 750ml' -> product='Olive Oil', unit='L', amount=0.750, weight_per_ea=1.0.\n\n"
        . "Category note: Use 'Spice/Herb' for any dried spices, herbs, seasoning blends, or condiment sachets.\n\n"
        . "Return ONLY a valid JSON array of objects.";

    $payload = json_encode([
        'model'    => 'google/gemini-3-flash-preview',
        'messages' => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$detected};base64,{$b64}"]]
            ]
        ]],
        'response_format' => [
            'type'        => 'json_schema',
            'json_schema' => [
                'name'   => 'receipt_items',
                'strict' => true,
                'schema' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'product'         => ['type' => 'string'],
                            'amount'          => ['type' => 'number'],
                            'unit'            => ['type' => 'string', 'enum' => ['kg', 'L', 'ea']],
                            'price'           => ['type' => 'number'],
                            'weight_per_ea'   => ['type' => 'number'],
                            'location'        => ['type' => 'string', 'enum' => ['Pantry', 'Fridge', 'Freezer']],
                            'category'        => ['type' => 'string', 'enum' => ['Proteins', 'Dairy', 'Bread', 'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Spice/Herb', 'Other']],
                            'kj_per_100'      => ['type' => 'number'],
                            'protein_per_100' => ['type' => 'number'],
                            'fat_per_100'     => ['type' => 'number'],
                            'carbs_per_100'   => ['type' => 'number'],
                        ],
                        'required'             => ['product', 'amount', 'unit', 'price', 'weight_per_ea', 'location', 'category', 'kj_per_100', 'protein_per_100', 'fat_per_100', 'carbs_per_100'],
                        'additionalProperties' => false
                    ]
                ]
            ]
        ]
    ]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception("Network error: " . curl_error($ch));
    curl_close($ch);

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$content) throw new Exception("AI returned no content.");

    $items = json_decode($content, true);
    if (!is_array($items) || empty($items)) throw new Exception("Could not parse AI response.");

    // Write job record for audit trail, then ingest inline
    $db = get_db_connection();
    $db->prepare("INSERT INTO jobs (file_path, status, message, result_json) VALUES (?, 'completed', 'Ingesting...', ?)")
       ->execute([$targetPath, json_encode($items)]);
    $job_id = (int)$db->lastInsertId();

    $result = runBridgeIngest($db, $items, $job_id);

    echo json_encode([
        'status'           => 'success',
        'item_count'       => count($items),
        'potential_merges' => $result['potential_merges'],
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Inline equivalent of bridge_ingest.php — runs inside the same HTTP request.
 * Mirrors the same unit-normalisation logic added in the Phase 2 audit fix.
 */
function runBridgeIngest(PDO $db, array $items, int $job_id): array {
    // Snapshot max product ID before we create anything new
    $maxIdBefore = (int)($db->query("SELECT MAX(id) FROM products")->fetchColumn() ?: 0);
    $ingestedIds = [];

    foreach ($items as $item) {
        try {
            $db->beginTransaction();
            $cleanName = trim($item['product']);

            // Spice/Herb items go to the spice_rack, not inventory
            if (($item['category'] ?? '') === 'Spice/Herb') {
                $db->prepare("INSERT OR IGNORE INTO spice_rack (name) VALUES (?)")->execute([$cleanName]);
                // If it was already there but unstocked, restock it and reset counters
                $db->prepare("UPDATE spice_rack SET is_stocked = 1, uses_since_restock = 0, restock_flagged = 0, last_restocked_at = CURRENT_TIMESTAMP WHERE name = ? COLLATE NOCASE")
                   ->execute([$cleanName]);
                $db->commit();
                continue;
            }

            $stmt = $db->prepare("SELECT id, merges_into, is_dropped FROM products WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$cleanName]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product && $product['is_dropped']) {
                $db->commit();
                continue;
            }

            if (!$product) {
                $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$cleanName, $item['category'], $item['unit'], $item['kj_per_100'], $item['protein_per_100'], $item['fat_per_100'], $item['carbs_per_100'], $item['weight_per_ea']]);
                $productId = (int)$db->lastInsertId();
            } else {
                $productId = (int)($product['merges_into'] ?: $product['id']);
            }

            // Normalise incoming amount to the product's canonical unit
            $stmt = $db->prepare("SELECT base_unit, weight_per_ea FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC);
            $canonicalUnit = $meta ? $meta['base_unit'] : $item['unit'];
            $wpe           = (float)($meta['weight_per_ea'] ?? 0);

            $incomingAmt = $item['amount'];
            if ($item['unit'] !== $canonicalUnit) {
                if ($item['unit'] !== 'ea' && $canonicalUnit === 'ea' && $wpe > 0) {
                    $incomingAmt = $item['amount'] / $wpe;
                } elseif ($item['unit'] === 'ea' && $canonicalUnit !== 'ea' && $wpe > 0) {
                    $incomingAmt = $item['amount'] * $wpe;
                }
            }

            // Merge into existing inventory row or create new
            $stmt = $db->prepare("SELECT id, current_qty, price_paid FROM inventory WHERE product_id = ? AND location = ? LIMIT 1");
            $stmt->execute([$productId, $item['location']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $db->prepare("UPDATE inventory SET current_qty = ?, price_paid = ?, unit = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$existing['current_qty'] + $incomingAmt, $existing['price_paid'] + $item['price'], $canonicalUnit, $existing['id']]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, current_qty, unit, price_paid, location) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$productId, $incomingAmt, $canonicalUnit, $item['price'], $item['location']]);
            }

            $db->commit();
            $ingestedIds[] = $productId;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Continue with remaining items — one bad row shouldn't abort the whole receipt
        }
    }

    // Deduplicate match detection (only against pre-existing products)
    $potentialMerges = [];
    foreach (array_unique($ingestedIds) as $pid) {
        foreach (findPotentialMatches($db, $pid) as $m) {
            $other = ($m['p1']['id'] == $pid) ? $m['p2'] : $m['p1'];
            if ((int)$other['id'] <= $maxIdBefore) {
                $potentialMerges[] = [
                    'source_id'   => $pid,
                    'source_name' => ($m['p1']['id'] == $pid) ? $m['p1']['name'] : $m['p2']['name'],
                    'target_id'   => $other['id'],
                    'target_name' => $other['name'],
                    'reason'      => $m['distance'],
                ];
            }
        }
    }

    // Mark job processed (same final state as bridge_ingest.php produces)
    $db->prepare("UPDATE jobs SET status = 'processed', result_json = ?, message = 'Successfully ingested all items.' WHERE id = ?")
       ->execute([json_encode(['items' => $items, 'potential_merges' => $potentialMerges]), $job_id]);

    return ['potential_merges' => $potentialMerges];
}
