<?php
/**
 * SPENCE | Deduplication & Fuzzy Matching Engine (Phase 6.8: UI Refinement)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
require_once '../core/matching.php';
$db = get_db_connection();

$matches = findPotentialMatches($db);
// Filter out recipe-linked products - they should not appear in manual merge lists or canonical catalog
$product_query = "
    SELECT p.* 
    FROM products p 
    LEFT JOIN recipes r ON p.recipe_id = r.id
    WHERE p.merges_into IS NULL 
      AND p.is_dropped = 0 
      AND p.recipe_id IS NULL
    ORDER BY p.name ASC";
$stmt = $db->query($product_query);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$page_title   = 'Deduplication';
$page_context = 'settings';
$extra_styles = '<style>
    .btn-merge { background: #6c757d; color: #fff; font-weight: bold; border: none; font-size: 0.75rem; padding: 4px 8px; transition: all 0.2s; }
    .btn-merge:hover { background: #5a6268; transform: scale(1.05); }
    .btn-dismiss { background: transparent; color: #555; border: 1px solid #444; font-size: 0.75rem; padding: 4px 8px; transition: all 0.2s; }
    .btn-dismiss:hover { background: #2a2a2a; color: #f44336; border-color: #f44336; }
    .btn-primary-spence { background: #6c757d; color: #fff; font-weight: bold; border: none; }
    .btn-primary-spence:hover { background: #5a6268; }
</style>';
include '../core/page_head.php';
?>
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
                    <div class="mt-3 pt-3 border-top border-secondary">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="blendMacros">
                            <label class="form-check-label small text-muted fw-bold uppercase" for="blendMacros">Blend Macros on Merge <span class="text-secondary fw-normal" style="text-transform: none;">(default: preserve canonical macros)</span></label>
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
                                    <th class="text-end" style="width: 340px;">ACTIONS</th>
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
                                        <button class="btn btn-merge me-1" onclick="merge(<?= $m['p1']['id'] ?>, <?= $m['p2']['id'] ?>, '<?= addslashes($m['p1']['name']) ?>', '<?= addslashes($m['p2']['name']) ?>')">Merge Right</button>
                                        <button class="btn btn-dismiss" onclick="dismiss(<?= $m['p1']['id'] ?>, <?= $m['p2']['id'] ?>)" title="Not a match — remove from suggestions"><i class="bi bi-x-lg"></i></button>
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
        const blend = document.getElementById('blendMacros').checked;
        const macroNote = blend ? 'Macros will be averaged.' : 'Canonical macros will be preserved.';
        if (!confirm(`Merge "${sourceName}" into "${targetName}"?\n\n${macroNote}\nThis moves inventory, recipes, and logs, and creates a permanent ingest alias.`)) return;
        const data = new FormData();
        data.append('action', 'merge');
        data.append('source_id', sourceId);
        data.append('target_id', targetId);
        data.append('blend_macros', blend ? '1' : '0');
        fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
            if (res.status === 'success') location.reload();
            else alert(res.message);
        });
    }
    function dismiss(idA, idB) {
        const data = new FormData();
        data.append('action', 'dismiss_dedupe_pair');
        data.append('id_a', idA);
        data.append('id_b', idB);
        fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => {
            if (res.status === 'success') location.reload();
            else alert(res.message);
        });
    }
    </script>
<?php include '../core/page_foot.php'; ?>
