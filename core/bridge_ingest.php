<?php
/** Legacy job bridge retained for PDF/background receipt workflows. */
require_once __DIR__ . '/db_helper.php';
require_once __DIR__ . '/receipt_ingest.php';

$db = get_db_connection();
$job = $db->query("SELECT id, result_json FROM jobs WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$job) exit("No completed jobs to ingest.\n");

$items = json_decode($job['result_json'], true);
if (!is_array($items) || !$items) exit("Invalid JSON in job result.\n");

$result = ingestReceiptItems($db, $items, (int)$job['id']);
foreach ($result['item_results'] as $item) {
    echo strtoupper($item['status']) . ': ' . $item['product'] . "\n";
}
