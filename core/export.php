<?php
/** Authenticated data export endpoint. */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';

$exports = [
    'inventory' => ['filename' => 'spence-inventory', 'sql' => 'SELECT i.*, p.name AS product_name, p.category FROM inventory i JOIN products p ON p.id = i.product_id ORDER BY p.name, i.location'],
    'consumption' => ['filename' => 'spence-consumption', 'sql' => 'SELECT cl.*, COALESCE(p.name, cl.name) AS product_name FROM consumption_log cl LEFT JOIN products p ON p.id = cl.product_id ORDER BY cl.consumed_at DESC'],
    'recipes' => ['filename' => 'spence-recipes', 'sql' => 'SELECT * FROM recipes ORDER BY name, version'],
    'vitals' => ['filename' => 'spence-vitals', 'sql' => 'SELECT v.*, u.name AS profile_name FROM user_vitals_history v JOIN user_profiles u ON u.id = v.user_id ORDER BY v.recorded_at DESC'],
];
$type = $_GET['type'] ?? 'backup';
$format = $_GET['format'] ?? 'json';
$db = get_db_connection();

if ($type === 'backup') {
    $data = [];
    foreach ($exports as $key => $export) $data[$key] = $db->query($export['sql'])->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="spence-backup-' . date('Y-m-d') . '.json"');
    echo json_encode(['exported_at' => date(DATE_ATOM), 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!isset($exports[$type])) http_response_code(404) && exit('Unknown export.');

$rows = $db->query($exports[$type]['sql'])->fetchAll(PDO::FETCH_ASSOC);
$filename = $exports[$type]['filename'] . '-' . date('Y-m-d');
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($format !== 'csv') http_response_code(400) && exit('Unknown format.');
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
$output = fopen('php://output', 'w');
if ($rows) {
    fputcsv($output, array_keys($rows[0]));
    foreach ($rows as $row) fputcsv($output, $row);
}
fclose($output);
