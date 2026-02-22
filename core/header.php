<?php
/**
 * SPENCE Global Header (Phase 4.0: Unified Product Engine)
 */
$current_page = basename(dirname($_SERVER['PHP_SELF']));
if ($current_page == 'spence') $current_page = 'home';

$nav_items = [
    'stock' => ['label' => 'STOCK', 'url' => '/spence/stock/', 'icon' => 'bi-box-seam'],
    'eat' => ['label' => 'EAT', 'url' => '/spence/eat/', 'icon' => 'bi-apple'],
    'cook' => ['label' => 'COOK', 'url' => '/spence/cook/', 'icon' => 'bi-fire'],
    'recipedb' => ['label' => 'RECIPEDB', 'url' => '/spence/recipedb/', 'icon' => 'bi-journal-bookmark'],
    'log' => ['label' => 'LOG', 'url' => '/spence/log/', 'icon' => 'bi-calendar-check'],
    'settings' => ['label' => 'SETTINGS', 'url' => '/spence/settings/index.php', 'icon' => 'bi-gear']
];
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 sticky-top" style="border-bottom: 2px solid #333; background: #0a0a0a !important;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-black letter-spacing-tight" href="/spence/index.php" style="font-weight: 900; letter-spacing: -1.5px; font-size: 1.5rem;">
            <span style="color: #00A3FF;">SPENCE</span><span class="text-muted" style="font-size: 0.8rem; letter-spacing: 0;">_v0.9</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto gap-2">
                <?php foreach ($nav_items as $key => $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == $key || strpos($_SERVER['PHP_SELF'], $item['url']) !== false) ? 'active' : '' ?>" href="<?= $item['url'] ?>" style="font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px;">
                        <i class="bi <?= $item['icon'] ?> me-1"></i> <?= $item['label'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>

<style>
    .fw-black { font-weight: 900; }
    .letter-spacing-tight { letter-spacing: -1px; }
    .nav-link { color: #888 !important; transition: all 0.2s; border-bottom: 2px solid transparent; }
    .nav-link:hover { color: #fff !important; }
    .nav-link.active { color: #00A3FF !important; border-bottom-color: #00A3FF; }
    
    [data-context="raid"] .nav-link.active, [data-context="raid"] .navbar-brand span:first-child { color: #ff9800 !important; border-bottom-color: #ff9800; }
    [data-context="cook"] .nav-link.active, [data-context="cook"] .navbar-brand span:first-child { color: #f44336 !important; border-bottom-color: #f44336; }
    [data-context="recipedb"] .nav-link.active, [data-context="recipedb"] .navbar-brand span:first-child { color: #A349A4 !important; border-bottom-color: #A349A4; }
    [data-context="log"] .nav-link.active, [data-context="log"] .navbar-brand span:first-child { color: #4caf50 !important; border-bottom-color: #4caf50; }
    [data-context="settings"] .nav-link.active, [data-context="settings"] .navbar-brand span:first-child { color: #6c757d !important; border-bottom-color: #6c757d; }
    
    .uppercase { text-transform: uppercase; }
    .spin { animation: rotation 2s infinite linear; display: inline-block; }
    @keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } }
    .input-group .form-control { background-color: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; }
    .input-group .input-group-text { background-color: #1a1a1a !important; border: 1px solid #333 !important; color: #888 !important; }
</style>