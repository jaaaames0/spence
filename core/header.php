<?php
/**
 * SPENCE Global Header (Phase 4.0: Unified Product Engine)
 */
$current_page = basename(dirname($_SERVER['PHP_SELF']));
if ($current_page == 'spence') $current_page = 'home';

$nav_items = [
    'stock'    => ['label' => 'STOCK',    'mlabel' => 'STOCK', 'url' => '/spence/stock/',          'icon' => 'bi-box-seam'],
    'eat'      => ['label' => 'EAT',      'mlabel' => 'EAT',   'url' => '/spence/eat/',            'icon' => 'bi-apple'],
    'cook'     => ['label' => 'COOK',     'mlabel' => 'COOK',  'url' => '/spence/cook/',           'icon' => 'bi-fire'],
    'recipedb' => ['label' => 'RECIPEDB', 'mlabel' => 'DB',    'url' => '/spence/recipedb/',       'icon' => 'bi-journal-bookmark'],
    'log'      => ['label' => 'LOG',      'mlabel' => 'LOG',   'url' => '/spence/log/',            'icon' => 'bi-calendar-check'],
    'settings' => ['label' => 'SETTINGS', 'mlabel' => 'SET',   'url' => '/spence/settings/index.php', 'icon' => 'bi-gear'],
];
?>
<nav class="navbar navbar-dark sticky-top mb-4" style="border-bottom: 2px solid #333; background: #0a0a0a !important;">
    <div class="container-fluid px-4 d-flex align-items-center">
        <a class="navbar-brand fw-black flex-shrink-0" href="/spence/index.php" style="font-weight: 900; letter-spacing: -1.5px; font-size: 1.5rem;">
            <span style="color: #00A3FF;">SPENCE</span><span class="text-muted" style="font-size: 0.8rem; letter-spacing: 0;">_v1</span>
        </a>

        <!-- Mobile nav: single line, icon above label, no toggle needed -->
        <div class="d-flex d-lg-none align-items-stretch ms-auto" style="flex: 1; justify-content: space-evenly;">
            <?php foreach ($nav_items as $key => $item):
                $active = ($current_page == $key || strpos($_SERVER['PHP_SELF'], $item['url']) !== false);
            ?>
            <a class="nav-link d-flex flex-column align-items-center justify-content-center px-1 <?= $active ? 'active' : '' ?>"
               href="<?= $item['url'] ?>" style="font-weight: 700; min-width: 36px; padding-top: 6px; padding-bottom: 6px;">
                <i class="bi <?= $item['icon'] ?>" style="font-size: 1.2rem; line-height: 1; display: block;"></i>
                <span style="font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0; font-weight: 700; line-height: 1.3; margin-top: 3px;"><?= $item['mlabel'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Desktop nav: icon + text links -->
        <div class="d-none d-lg-flex ms-auto gap-2">
            <?php foreach ($nav_items as $key => $item):
                $active = ($current_page == $key || strpos($_SERVER['PHP_SELF'], $item['url']) !== false);
            ?>
            <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= $item['url'] ?>"
               style="font-weight: 700; font-size: 0.85rem; letter-spacing: 0.5px;">
                <i class="bi <?= $item['icon'] ?> me-1"></i><?= $item['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
