<?php
/**
 * PantryOS Upload Handler
 */
$dbPath = __DIR__ . '/../database/spence.db';
$uploadDir = __DIR__ . '/../uploads/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipt'])) {
    $file = $_FILES['receipt'];
    
    // Basic validation
    $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($file['type'], $allowedTypes)) {
        die("Invalid file type. Only JPG, PNG, and PDF allowed.");
    }

    $filename = time() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        try {
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $db->prepare("INSERT INTO jobs (file_path, status, message) VALUES (?, 'pending', 'Upload successful. Processing receipt...')");
            $stmt->execute([$targetPath]);
            $jobId = $db->lastInsertId();

            header("Location: ../stock/index.php?active_job=$jobId");
            exit;
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    } else {
        $error = error_get_last();
        die("Upload failed. PHP Error: " . ($error['message'] ?? 'Unknown error') . " | File Error Code: " . $file['error']);
    }
} else {
    header("Location: ../stock/index.php");
    exit;
}
