<?php
/**
 * SPENCE | Deduplication & Fuzzy Matching Engine (Phase 6.8: UI Refinement)
 */
require_once '../core/db_helper.php';
require_once '../core/matching.php';
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $result = executeProductMerge($db, (int)$_POST['source_id'], (int)$_POST['target_id']);
    echo json_encode($result);
    exit;
}

$matches = findPotentialMatches($db);
$stmt = $db->query("SELECT * FROM products WHERE merges_into IS NULL AND is_dropped = 0 ORDER BY name ASC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en" data-context="settings">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPENCE | Deduplication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Inter', sans-serif; }
        .table { color: #e0e0e0; vertical-align: middle; border-color: #333; }
        .table-dark { --bs-table-bg: #1e1e1e; --bs-table-border-color: #333; }
        .fw-black { font-weight: 900; letter-spacing: -1px; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #888 !important; }
        .text-accent { color: #00A3FF !important; }
        .btn-merge { background: #6c757d; color: #fff; font-weight: bold; border: none; font-size: 0.75rem; padding: 4px 8px; transition: all 0.2s; }
        .btn-merge:hover { background: #5a6268; transform: scale(1.05); }
        .btn-primary-spence { background: #6c757d; color: #fff; font-weight: bold; border: none; }
        .btn-primary-spence:hover { background: #5a6268; }
        .form-select { background: #1a1a1a !important; border: 1px solid #333 !important; color: #fff !important; font-size: 0.9rem; }
        .card { background-color: #1e1e1e; border: 1px solid #333; }
        .nav-tabs { border-bottom: 2px solid #333; }
        .nav-tabs .nav-link { color: #888; border: none; font-weight: 700; font-size: 0.85rem; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background: none; color: #6c757d !important; border-bottom: 3px solid #6c757d; }
    </style>
</head>
<body>
    <?php include '../core/header.php'; ?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col"><h2 class="fw-black uppercase mb-0">Settings</h2></div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link" href="index.php">USER PROFILE</a></li>
            <li class="nav-item"><a class="nav-link" href="products.php">PRODUCT MASTER</a></li>
            <li class="nav-item"><a class="nav-link active" href="dedupe.php">DEDUPLICATION</a></li>
        </ul>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card p-4 mb-4">
                    <h6 class="text-muted uppercase small mb-3 fw-bold">Manual Force Merge</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="small text-muted mb-1 uppercase fw-bold">Source (Messy)</label>
                            <select id="manualSource" class="form-select">
                                <option value="">Select product...</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (#<?= $p['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 text-center pb-2">
                            <i class="bi bi-arrow-right h4 text-muted"></i>
                        </div>
                        <div class="col-md-4">
                            <label class="small text-muted mb-1 uppercase fw-bold">Target (Canonical)</label>
                            <select id="manualTarget" class="form-select">
                                <option value="">Select product...</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (#<?= $p['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary-spence w-100 fw-bold py-2" onclick="forceMerge()">MERGE PRODUCTS</button>
                        </div>
                    </div>
                </div>

                <div class="card overflow-hidden">
                    <div class="card-header bg-transparent border-secondary p-3">
                        <h6 class="text-white opacity-50 uppercase small mb-0 fw-bold">Intelligence Suggestions (Category Locked)</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover mb-0">
                            <thead>
                                <tr class="text-muted small">
                                    <th>POTENTIAL MATCHES</th>
                                    <th class="text-center" style="width: 120px;">ENGINE</th>
                                    <th class="text-end" style="width: 280px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr><td colspan="3" class="text-center p-4 text-muted">No immediate intelligence matches found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($matches as $m): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fw-bold">"<?= htmlspecialchars($m['p1']['name']) ?>"</span>
                                            <i class="bi bi-arrow-left-right text-muted px-1"></i>
                                            <span class="fw-bold">"<?= htmlspecialchars($m['p2']['name']) ?>"</span>
                                        </div>
                                    </td>
                                    <td class="text-center"><span class="badge bg-secondary opacity-50 small"><?= $m['distance'] ?></span></td>
                                    <td class="text-end">
                                        <button class="btn btn-merge me-1" onclick="merge(<?= $m['p2']['id'] ?>, <?= $m['p1']['id'] ?>, '<?= addslashes($m['p2']['name']) ?>', '<?= addslashes($m['p1']['name']) ?>')">Merge Left</button>
                                        <button class="btn btn-merge" onclick="merge(<?= $m['p1']['id'] ?>, <?= $m['p2']['id'] ?>, '<?= addslashes($m['p1']['name']) ?>', '<?= addslashes($m['p2']['name']) ?>')">Merge Right</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100 overflow-hidden">
                    <div class="card-header bg-transparent border-secondary p-3">
                        <h6 class="text-white opacity-50 uppercase small mb-0 fw-bold">Active Catalog (Canonical)</h6>
                    </div>
                    <div class="list-group list-group-flush overflow-auto" style="max-height: 800px;">
                        <?php foreach ($products as $p): ?>
                        <div class="list-group-item bg-transparent text-white border-secondary small py-2 d-flex justify-content-between">
                            <span><?= htmlspecialchars($p['name']) ?></span>
                            <span class="text-muted">#<?= $p['id'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function forceMerge() {
        const sourceId = document.getElementById('manualSource').value;
        const targetId = document.getElementById('manualTarget').value;
        if (!sourceId || !targetId) return alert('Select both products.');
        if (sourceId === targetId) return alert('Cannot merge a product into itself.');
        const sourceName = document.getElementById('manualSource').options[document.getElementById('manualSource').selectedIndex].text;
        const targetName = document.getElementById('manualTarget').options[document.getElementById('manualTarget').selectedIndex].text;
        merge(sourceId, targetId, sourceName, targetName);
    }
    function merge(sourceId, targetId, sourceName, targetName) {
        if (!confirm(`Merge "${sourceName}" into "${targetName}"?\n\nThis moves inventory, recipes, and logs, and creates a permanent ingest alias.`)) return;
        const data = new FormData();
        data.append('action', 'merge');
        data.append('source_id', sourceId);
        data.append('target_id', targetId);
        fetch('dedupe.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
            if (res.status === 'success') location.reload();
            else alert(res.message);
        });
    }
    </script>
</body>
</html>
