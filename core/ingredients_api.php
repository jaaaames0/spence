<?php
require_once '../core/db_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['status' => 'error', 'message' => 'Invalid request']));
}

$action = $_POST['action'] ?? '';

if ($action === 'sync_ingredients') {
    $ingredients = $_POST['ingredients'] ?? '';
    if (empty($ingredients)) {
        die(json_encode(['status' => 'error', 'message' => 'No ingredients provided']));
    }

    $newItems = json_decode($ingredients, true);
    if (!is_array($newItems)) {
        die(json_encode(['status' => 'error', 'message' => 'Invalid ingredients format']));
    }

    $jsonPath = '../../ingredients/shopping_list.json';
    
    if (!file_exists($jsonPath)) {
        // Fallback for different pathing or just creation
        $jsonPath = '/srv/jaaaames.com/ingredients/shopping_list.json';
    }

    $currentList = [];
    if (file_exists($jsonPath)) {
        $currentList = json_decode(file_get_contents($jsonPath), true) ?: [];
    }

    // Append new items
    foreach ($newItems as $item) {
        $currentList[] = [
            'name' => $item['name'],
            'qty' => (float)$item['qty'],
            'unit' => $item['unit'],
            'checked' => false
        ];
    }

    if (file_put_contents($jsonPath, json_encode($currentList, JSON_PRETTY_PRINT))) {
        echo json_encode(['status' => 'success', 'message' => 'Ingredients added to shopping list']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to write to shopping list']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
