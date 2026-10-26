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
                            <div class="position-relative">
                                <input type="text" id="manualSourceName" class="form-control" placeholder="Search products...">
                                <div id="manualSourceList" class="product-search-list dropdown-style"></div>
                            </div>
                            <input type="hidden" id="manualSource">
                        </div>
                        <div class="col-md-1 text-center pb-2">
                            <i class="bi bi-arrow-right h4 text-muted"></i>
                        </div>
                        <div class="col-md-4">
                            <label class="small text-muted mb-1 uppercase fw-bold">Target (Canonical)</label>
                            <div class="position-relative">
                                <input type="text" id="manualTargetName" class="form-control" placeholder="Search products...">
                                <div id="manualTargetList" class="product-search-list dropdown-style"></div>
                            </div>
                            <input type="hidden" id="manualTarget">
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
                                    <th class="text-end" style="width: 160px;">ACTIONS</th>
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
                                            <span class="fw-bold"><?= htmlspecialchars($m['p1']['name']) ?></span>
                                            <i class="bi bi-arrow-left-right text-muted px-1"></i>
                                            <span class="fw-bold"><?= htmlspecialchars($m['p2']['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center"><span class="badge bg-secondary opacity-50 small"><?= $m['distance'] ?></span></td>
                                    <td class="text-end">
                                        <button class="btn btn-merge me-1" onclick="reviewMerge(<?= $m['p1']['id'] ?>, <?= $m['p2']['id'] ?>, <?= htmlspecialchars(json_encode($m['distance']), ENT_QUOTES) ?>)">Review</button>
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

    <div class="modal fade" id="mergeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase">Review Merge</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">The right-hand product survives with its id, unit and history; pick which name and macros it keeps. Swap sides to keep the other one.</p>
                    <div id="mergeList"></div>
                </div>
                <div class="modal-footer border-0 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-bold" onclick="swapMerge()"><i class="bi bi-arrow-left-right me-1"></i>SWAP SIDES</button>
                    <button type="button" class="btn btn-dismiss fw-bold flex-grow-1" id="mergeDismissBtn" onclick="dismissReviewed()">NOT THE SAME</button>
                    <button type="button" class="btn btn-primary-spence fw-bold flex-grow-1" onclick="applyReviewed()">MERGE</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    const mergeProducts = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $products)) ?>;
    ['Source', 'Target'].forEach(key => {
        const input = document.getElementById(`manual${key}Name`);
        const idField = document.getElementById(`manual${key}`);
        const list = document.getElementById(`manual${key}List`);
        attachProductSearch({
            input, list,
            products: mergeProducts,
            meta: p => `<span class="text-muted small">#${p.id}</span>`,
            onSelect: p => {
                input.value = p.name;
                idField.value = p.id;
                if (key === 'Source') document.getElementById('manualTargetName').focus();
            },
        });
        input.addEventListener('input', () => { idField.value = ''; });
        input.addEventListener('blur', () => setTimeout(() => { list.style.display = 'none'; }, 150));
    });

    const mergeModal = new bootstrap.Modal(document.getElementById('mergeModal'));
    let reviewing = null;

    async function reviewMerge(sourceId, targetId, reason = '') {
        const data = new FormData();
        data.append('action', 'merge_preview');
        data.append('ids', `${sourceId},${targetId}`);
        const res = await fetch('../core/api.php', { method: 'POST', body: data }).then(r => r.json());
        const byId = Object.fromEntries((res.products || []).map(p => [p.id, p]));
        if (!byId[sourceId] || !byId[targetId]) return alert('Could not load those products.');
        reviewing = { source: byId[sourceId], target: byId[targetId], reason };
        renderReview();
        mergeModal.show();
    }

    function renderReview() {
        const { source, target, reason } = reviewing;
        reviewing.reviewer = mergeReview(document.getElementById('mergeList'),
            [{ source_id: source.id, target_id: target.id, source, target, reason, active: true }]);
        // Dismissing only makes sense for a suggested pair
        document.getElementById('mergeDismissBtn').classList.toggle('d-none', !reason);
    }

    function swapMerge() {
        [reviewing.source, reviewing.target] = [reviewing.target, reviewing.source];
        renderReview();
    }

    async function applyReviewed() {
        const failures = await reviewing.reviewer.apply();
        if (failures.length) return alert(failures.join('\n'));
        location.reload();
    }

    function dismissReviewed() {
        dismiss(reviewing.source.id, reviewing.target.id);
    }

    function forceMerge() {
        const sourceId = document.getElementById('manualSource').value;
        const targetId = document.getElementById('manualTarget').value;
        if (!sourceId || !targetId) return alert('Select both products.');
        if (sourceId === targetId) return alert('Cannot merge a product into itself.');
        reviewMerge(+sourceId, +targetId);
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
