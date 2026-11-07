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
    #mergeCard { scroll-margin-top: 7rem; }
    /* Same columns as the .merge-grid comparison below, so each picker sits over its product */
    .merge-pickers { display: grid; grid-template-columns: 5.5rem 1fr 1fr; grid-template-areas: "swap source target"; gap: 4px 10px; align-items: end; padding: 0 13px; }
    .merge-pick-source { grid-area: source; } .merge-pick-target { grid-area: target; } .merge-pick-swap { grid-area: swap; }
    @media (max-width: 575px) {
        .merge-pickers { grid-template-columns: 1fr; grid-template-areas: "source" "swap" "target"; }
        .merge-pick-swap { text-align: center; }
    }
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
                <div class="card p-4 mb-4" id="mergeCard">
                    <h6 class="text-muted uppercase small mb-3 fw-bold">Merge Products</h6>
                    <div class="merge-pickers mb-3">
                        <div class="merge-pick-source">
                            <label class="small text-muted mb-1 uppercase fw-bold" for="mergeSourceName">Merge this</label>
                            <div class="position-relative">
                                <input type="text" id="mergeSourceName" class="form-control" placeholder="Search products..." autocomplete="off">
                                <div id="mergeSourceList" class="product-search-list dropdown-style"></div>
                            </div>
                        </div>
                        <div class="merge-pick-swap">
                            <button type="button" class="btn btn-outline-secondary" onclick="swapMerge()" title="Swap sides"><i class="bi bi-arrow-left-right"></i></button>
                        </div>
                        <div class="merge-pick-target">
                            <label class="small text-muted mb-1 uppercase fw-bold" for="mergeTargetName">Into this</label>
                            <div class="position-relative">
                                <input type="text" id="mergeTargetName" class="form-control" placeholder="Search products..." autocomplete="off">
                                <div id="mergeTargetList" class="product-search-list dropdown-style"></div>
                            </div>
                        </div>
                    </div>
                    <div id="mergePanel" class="d-none">
                        <p class="text-muted small mb-2">The right-hand product survives with its id, unit and history; pick which name and macros it keeps.</p>
                        <div id="mergeList"></div>
                        <div id="mergeSame" class="small mt-2 d-none" style="color:#ff9800;">Pick two different products.</div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary fw-bold" onclick="clearMerge()">CLEAR</button>
                            <button type="button" class="btn btn-dismiss fw-bold flex-grow-1 d-none" id="mergeDismissBtn" onclick="dismissReviewed()">NOT THE SAME</button>
                            <button type="button" class="btn btn-primary-spence fw-bold flex-grow-1" id="mergeApplyBtn" onclick="applyReviewed()" disabled>MERGE</button>
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

    <script>
    const mergeProducts = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name']], $products)) ?>;
    // The pair on screen: productMergePreview() rows (null until picked) and the suggestion's engine reason
    const merging = { source: null, target: null, reason: '' };
    let reviewer = null;
    const sides = {};

    ['source', 'target'].forEach(side => {
        const key = side === 'source' ? 'Source' : 'Target';
        const input = document.getElementById(`merge${key}Name`);
        const list = document.getElementById(`merge${key}List`);
        sides[side] = input;
        attachProductSearch({
            input, list,
            products: mergeProducts,
            meta: p => `<span class="text-muted small">#${p.id}</span>`,
            onSelect: async p => {
                input.value = p.name;
                merging.reason = '';
                merging[side] = await loadPreview(p.id);
                renderReview();
                if (side === 'source' && !merging.target) sides.target.focus();
            },
        });
        // Editing the name un-picks that side
        input.addEventListener('input', () => {
            if (!merging[side]) return;
            merging[side] = null;
            merging.reason = '';
            renderReview();
        });
        input.addEventListener('blur', () => setTimeout(() => { list.style.display = 'none'; }, 150));
    });

    async function loadPreview(...ids) {
        const data = new FormData();
        data.append('action', 'merge_preview');
        data.append('ids', ids.join(','));
        const res = await fetch('../core/api.php', { method: 'POST', body: data }).then(spenceJson).catch(() => ({}));
        const byId = Object.fromEntries((res.products || []).map(p => [p.id, p]));
        return ids.length === 1 ? byId[ids[0]] || null : byId;
    }

    function renderReview() {
        const { source, target, reason } = merging;
        const both = source && target, same = both && source.id === target.id;
        document.getElementById('mergePanel').classList.toggle('d-none', !source && !target);
        reviewer = mergeReview(document.getElementById('mergeList'),
            [{ source_id: source?.id, target_id: target?.id, source, target, reason, active: !same }],
            { sourceLabel: '', targetLabel: '', toggle: false });
        document.getElementById('mergeSame').classList.toggle('d-none', !same);
        document.getElementById('mergeApplyBtn').disabled = !both || same;
        // Dismissing only makes sense for a suggested pair
        document.getElementById('mergeDismissBtn').classList.toggle('d-none', !(both && reason));
    }

    // Suggestion rows load their pair into the merge card
    async function reviewMerge(sourceId, targetId, reason = '') {
        const byId = await loadPreview(sourceId, targetId);
        if (!byId[sourceId] || !byId[targetId]) return alert('Could not load those products.');
        Object.assign(merging, { source: byId[sourceId], target: byId[targetId], reason });
        sides.source.value = merging.source.name;
        sides.target.value = merging.target.name;
        renderReview();
        document.getElementById('mergeCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function swapMerge() {
        [merging.source, merging.target] = [merging.target, merging.source];
        [sides.source.value, sides.target.value] = [sides.target.value, sides.source.value];
        renderReview();
    }

    function clearMerge() {
        Object.assign(merging, { source: null, target: null, reason: '' });
        sides.source.value = sides.target.value = '';
        renderReview();
        sides.source.focus();
    }

    async function applyReviewed() {
        const btn = document.getElementById('mergeApplyBtn');
        btn.disabled = true;
        btn.innerText = 'MERGING...';
        const failures = await reviewer.apply();
        if (failures.length) {
            btn.disabled = false;
            btn.innerText = 'MERGE';
            return alert(failures.join('\n'));
        }
        location.reload();
    }

    function dismissReviewed() {
        dismiss(merging.source.id, merging.target.id);
    }

    function dismiss(idA, idB) {
        const data = new FormData();
        data.append('action', 'dismiss_dedupe_pair');
        data.append('id_a', idA);
        data.append('id_b', idB);
        fetch('../core/api.php', { method: 'POST', body: data }).then(spenceJson).then(res => {
            if (res.status === 'success') location.reload();
            else alert(res.message);
        });
    }
    </script>
<?php include '../core/page_foot.php'; ?>
