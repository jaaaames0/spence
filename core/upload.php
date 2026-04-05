<?php
/**
 * PantryOS Upload Handler
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';

$uploadDir = __DIR__ . '/../uploads/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipt'])) {
    $file = $_FILES['receipt'];

    // Detect actual MIME type from file contents, not user-supplied header
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($file['tmp_name']);
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!array_key_exists($detected, $allowedTypes)) {
        die("Invalid file type. Only JPG, PNG, and PDF allowed.");
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $allowedTypes[$detected];
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        try {
            $db = get_db_connection();

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
