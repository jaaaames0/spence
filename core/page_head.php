<?php
/**
 * SPENCE Page Shell — Head
 * Variables to set before including:
 *   $page_title   (string)  — e.g. "Stock"
 *   $page_context (string)  — e.g. "stock"
 *   $extra_head   (string?) — optional extra <head> content (e.g. Chart.js CDN)
 *   $extra_styles (string?) — optional <style> block for page-specific CSS
 */
?>
<!DOCTYPE html>
<html lang="en" data-context="<?= htmlspecialchars($page_context) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>SPENCE | <?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" href="/spence/core/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/spence/core/site.webmanifest">
    <meta name="theme-color" content="#121212">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/spence/core/spence.css?v=2">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?= $extra_head ?? '' ?>
    <?= $extra_styles ?? '' ?>
</head>
<body>
<?php if (empty($skip_header)) include __DIR__ . '/header.php'; ?>
