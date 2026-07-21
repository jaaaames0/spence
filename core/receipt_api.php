<?php
/**
 * SPENCE Receipt API — Synchronous receipt scan + inventory ingest
 * Replaces the watcher.sh async pipeline for JPEG/PNG receipts.
 * PDFs still fall through to upload.php → watcher.sh.
 */
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/receipt_ingest.php';
ob_clean();
header('Content-Type: application/json');

set_time_limit(120); // Vision calls can take up to 60s on a busy receipt

$_key_file = '/srv/secrets/openrouter.env';
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
        . "Expiry protocol: Only track short-life fresh food. For fresh refrigerated meat/seafood, milk/yogurt/soft cheese, fresh bread/bakery, and fresh fruit/vegetables: if a clear date is visible on the image, return expiry_kind='label' and expiry_date as YYYY-MM-DD. Otherwise return expiry_kind='estimated' with a conservative estimated_shelf_life_days of 1–60 and expiry_date=''. Return expiry_kind='none', expiry_date='', and estimated_shelf_life_days=0 for frozen, canned, dried, jarred, shelf-stable, coffee, sugar, syrup, sauces, cereal, pasta, oil, and all products likely to last over 60 days.\n\n"
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
                            'expiry_kind'     => ['type' => 'string', 'enum' => ['label', 'estimated', 'none']],
                            'expiry_date'     => ['type' => 'string'],
                            'estimated_shelf_life_days' => ['type' => 'integer'],
                        ],
                        'required'             => ['product', 'amount', 'unit', 'price', 'weight_per_ea', 'location', 'category', 'kj_per_100', 'protein_per_100', 'fat_per_100', 'carbs_per_100', 'expiry_kind', 'expiry_date', 'estimated_shelf_life_days'],
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

    $result = ingestReceiptItems($db, $items, $job_id);

    echo json_encode([
        'status'           => 'success',
        'item_count'       => count($items),
        'potential_merges' => $result['potential_merges'],
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
