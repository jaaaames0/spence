<?php
/**
 * SPENCE Quick Eat API — Synchronous vision scan + direct intake logging
 * Bypasses inventory entirely. Food goes straight to consumption_log.
 */
ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
ob_clean();
header('Content-Type: application/json');

$_key_file = __DIR__ . '/openrouter.env';
$api_key = file_exists($_key_file)
    ? trim(file_get_contents($_key_file))
    : (getenv('OPENROUTER_API_KEY') ?: '');

$action = $_POST['action'] ?? '';

try {
    if ($action === 'scan') {
        if (!$api_key) throw new Exception("OpenRouter API key not configured.");
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("No image received.");
        }

        $file = $_FILES['image'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'image/jpeg', 'image/png' => 'image/png'];
        if (!array_key_exists($detected, $allowed)) {
            throw new Exception("Only JPG and PNG images are supported.");
        }

        $b64 = base64_encode(file_get_contents($file['tmp_name']));

        $prompt = "Task: Identify ALL distinct food items visible in this image and estimate nutritional macros for each.\n\n"
            . "You may see prepared/cooked food (estimate from appearance and standard nutritional data) "
            . "or packaged food with a visible nutrition label (read the label directly). "
            . "If multiple distinct foods are visible (e.g. a main and a dessert, or several dishes), return a separate object for each.\n\n"
            . "Instructions:\n"
            . "- Product Name: Clean canonical name in Title Case. Strip brand names and weight/size descriptors "
            . "(e.g., 'Grilled Chicken Breast', 'Greek Yogurt', 'Beef Fried Rice').\n"
            . "- Estimated Weight: Total grams of that specific item visible, or a standard single serving if unclear.\n"
            . "- Macros: Per-100g values. For packaged items, read from the label. For prepared food, estimate.\n"
            . "- Category: Best fit from the enum.\n\n"
            . "Return ONLY a valid JSON array of objects, one per distinct food item.";

        $item_schema = [
            'type' => 'object',
            'properties' => [
                'name'               => ['type' => 'string'],
                'estimated_weight_g' => ['type' => 'number'],
                'kj_per_100'         => ['type' => 'number'],
                'protein_per_100'    => ['type' => 'number'],
                'fat_per_100'        => ['type' => 'number'],
                'carbs_per_100'      => ['type' => 'number'],
                'category'           => ['type' => 'string', 'enum' => [
                    'Meal Prep', 'Proteins', 'Dairy', 'Bread',
                    'Fruit and Veg', 'Cereals/Grains', 'Snacks/Confectionary', 'Drinks', 'Other'
                ]]
            ],
            'required' => ['name', 'estimated_weight_g', 'kj_per_100', 'protein_per_100', 'fat_per_100', 'carbs_per_100', 'category'],
            'additionalProperties' => false
        ];

        $payload = json_encode([
            'model' => 'google/gemini-3-flash-preview',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$detected};base64,{$b64}"]]
                ]
            ]],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => 'quick_eat_items',
                    'strict' => true,
                    'schema' => [
                        'type'  => 'array',
                        'items' => $item_schema
                    ]
                ]
            ]
        ]);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ]
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) throw new Exception("Network error: " . curl_error($ch));
        curl_close($ch);

        $decoded = json_decode($response, true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!$content) throw new Exception("AI returned no content. Try again.");

        $items = json_decode($content, true);
        if (!$items) throw new Exception("Could not parse AI response.");

        // Normalize: model may return a single object instead of an array
        if (isset($items['name'])) $items = [$items];
        if (!is_array($items) || empty($items)) throw new Exception("AI returned no food items.");

        echo json_encode(['status' => 'success', 'items' => array_values($items)]);

    } elseif ($action === 'log_photo') {
        $db = get_db_connection();
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || empty($items)) throw new Exception("No items to log.");

        $db->beginTransaction();
        $total = ['kj' => 0, 'protein' => 0, 'fat' => 0, 'carb' => 0];

        foreach ($items as $item) {
            $name          = trim($item['name'] ?? '');
            $weight_g      = (float)($item['weight_g'] ?? 0);
            $kj_per_100    = (float)($item['kj_per_100'] ?? 0);
            $prot_per_100  = (float)($item['protein_per_100'] ?? 0);
            $fat_per_100   = (float)($item['fat_per_100'] ?? 0);
            $carb_per_100  = (float)($item['carbs_per_100'] ?? 0);
            $category      = $item['category'] ?? 'Other';
            $add_to_master = !empty($item['add_to_master']);

            if ($weight_g <= 0) continue;

            $factor  = $weight_g / 100.0;
            $kj      = (int)round($kj_per_100 * $factor);
            $protein = round($prot_per_100 * $factor, 1);
            $fat     = round($fat_per_100 * $factor, 1);
            $carb    = round($carb_per_100 * $factor, 1);

            $product_id = null;
            if ($add_to_master && $name) {
                $stmt = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND merges_into IS NULL AND is_dropped = 0 LIMIT 1");
                $stmt->execute([$name]);
                $existing_id = $stmt->fetchColumn();
                if ($existing_id) {
                    $product_id = $existing_id;
                } else {
                    $db->prepare("INSERT INTO products (name, category, base_unit, kj_per_100, protein_per_100, fat_per_100, carb_per_100, weight_per_ea, type) VALUES (?, ?, 'kg', ?, ?, ?, ?, 1.0, 'raw')")
                       ->execute([$name, $category, $kj_per_100, $prot_per_100, $fat_per_100, $carb_per_100]);
                    $product_id = $db->lastInsertId();
                }
            }

            // Store name on the log row so it displays correctly when product_id is null
            $log_name = $product_id ? null : $name;

            $db->prepare("INSERT INTO consumption_log (product_id, name, amount, unit, kj, protein, fat, carb, unit_cost, source) VALUES (?, ?, ?, 'kg', ?, ?, ?, ?, 0, 'quick_eat')")
               ->execute([$product_id, $log_name, $weight_g / 1000.0, $kj, $protein, $fat, $carb]);

            $total['kj']      += $kj;
            $total['protein'] += $protein;
            $total['fat']     += $fat;
            $total['carb']    += $carb;
        }

        $db->commit();
        echo json_encode(['status' => 'success', 'macros' => $total]);

    } elseif ($action === 'log_existing') {
        $db = get_db_connection();
        $product_id = (int)($_POST['product_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);

        if (!$product_id) throw new Exception("No product selected.");
        if ($amount <= 0) throw new Exception("Amount must be greater than zero.");

        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new Exception("Product not found.");

        $macros = calculateMacros($product, $amount, $product['base_unit']);

        $db->prepare("INSERT INTO consumption_log (product_id, amount, unit, kj, protein, fat, carb, unit_cost, source) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'quick_eat')")
           ->execute([$product_id, $amount, $product['base_unit'], $macros['kj'], $macros['protein'], $macros['fat'], $macros['carb']]);

        echo json_encode(['status' => 'success', 'macros' => $macros]);

    } else {
        throw new Exception("Unknown action.");
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
