<?php
/**
 * SPENCE Execution Engine: Recipe Detail (Phase 11.2: Ingredients Integration)
 */
require_once '../../core/auth.php';
require_once '../../core/db_helper.php';
$db = get_db_connection();

$recipe_id = $_GET['id'] ?? null;
if (!$recipe_id) { header("Location: ../index.php"); exit; }

// --- Integration Check ---
$ingredientsEnabled = file_exists('../../../ingredients/index.html');

$stmt = $db->prepare("
    SELECT r.*, 
           SUM(p.kj_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_kj,
           SUM(p.protein_per_100 * (CASE WHEN ri.unit = 'ea' THEN (ri.amount * p.weight_per_ea) ELSE ri.amount END / 0.1)) as total_protein
    FROM recipes r
    LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
    LEFT JOIN products p ON ri.product_id = p.id
    WHERE r.id = ?
    GROUP BY r.id");
$stmt->execute([$recipe_id]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipe || !$recipe['is_active']) { header("Location: ../index.php"); exit; }

$stmt = $db->prepare("
    SELECT ri.*, p.name as product_name, p.weight_per_ea,
           p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100,
           (SELECT SUM(current_qty) FROM inventory WHERE product_id = p.id) as stock_qty,
           (SELECT unit FROM inventory WHERE product_id = p.id LIMIT 1) as stock_unit,
           (SELECT (price_paid / current_qty) FROM inventory WHERE product_id = p.id AND current_qty > 0 LIMIT 1) as unit_cost
    FROM recipe_ingredients ri
    JOIN products p ON ri.product_id = p.id
    WHERE ri.recipe_id = ?");
$stmt->execute([$recipe_id]);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cost = 0;
foreach($ingredients as $ing) {
    $total_cost += ($ing['unit_cost'] ?: 0) * $ing['amount'];
}
$page_title   = htmlspecialchars($recipe['name']);
$page_context = 'cook';
$extra_styles = '<style>
    .method-box { background: #1a1a1a; padding: 20px; border-radius: 8px; border: 1px solid #333; white-space: pre-wrap; line-height: 1.6; color: #e0e0e0; font-size: 1.1rem; }
    .ingredient-status { font-size: 0.95rem; padding: 10px 12px; border-radius: 8px; margin-bottom: 8px; background: #2b2b2b; }
    .stock-ok      { border-left: 4px solid #4caf50; }
    .stock-low     { border-left: 4px solid #ff9800; }
    .stock-missing { border-left: 4px solid #f44336; }
    .ing-name-row  { position: relative; cursor: pointer; user-select: none; }
    .sub-dropdown  { position: absolute; top: 100%; left: -12px; right: -12px; z-index: 200;
                     background: #141414; border: 1px solid #555; border-top: none;
                     border-radius: 0 0 6px 6px; box-shadow: 0 6px 16px rgba(0,0,0,0.7); }
    .sub-option    { padding: 7px 12px; cursor: pointer; border-top: 1px solid #1e1e1e; transition: background 0.1s; }
    .sub-option:hover { background: #2a2a2a; }
    .multiplier-bar { background: #1e1e1e; border-top: 1px solid #333; position: fixed; bottom: 0; left: 0; right: 0; padding: 1rem 0; z-index: 1000; }
    .btn-execute { background-color: #f44336; color: #fff; border: none; transition: 0.2s; }
    .btn-execute:hover { background-color: #d32f2f; color: #fff; }
    .btn-ingredients-app { background: #d32f2f; border: none; color: #fff; font-weight: 800; }
    .btn-ingredients-app:hover { background: #b71c1c; color: #fff; }
    .section-header { margin-top: 1rem; margin-bottom: 2rem; }
    .yielding-display { color: #f44336 !important; }
</style>';
include '../../core/page_head.php';
?>
    
    <div class="container-fluid px-4 mb-5 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-8">
                <h2 class="fw-black uppercase mb-0"><?= htmlspecialchars($recipe['name']) ?></h2>
                <div class="mt-2 d-flex gap-1 align-items-center">
                    <span class="badge badge-kj"><span id="headerKj"><?= round($recipe['total_kj'] / ($recipe['yield_serves'] ?: 1)) ?></span> kJ</span>
                    <span class="badge badge-protein"><span id="headerP"><?= number_format($recipe['total_protein'] / ($recipe['yield_serves'] ?: 1), 1) ?></span>g P</span>
                    <span class="badge badge-cost">$<span id="headerCost"><?= number_format($total_cost / ($recipe['yield_serves'] ?: 1), 2) ?></span></span>
                    <span class="text-white small ms-2 uppercase opacity-50 fw-bold" style="font-size: 0.65rem;">(per serve)</span>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <a href="../index.php" class="btn btn-outline-secondary uppercase fw-bold" style="height: 38px;">Back to Cook</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-muted small fw-bold uppercase mb-0">Ingredients</h6>
                    <?php if ($ingredientsEnabled): ?>
                    <button id="btnIngredients" class="btn btn-sm btn-ingredients-app d-none uppercase" onclick="syncIngredients()">Get Ingredients</button>
                    <?php endif; ?>
                </div>
                <div id="ingredientList">
                    <?php foreach ($ingredients as $ing):
                        $stock     = (float)$ing['stock_qty'];
                        $unit      = htmlspecialchars($ing['unit']);
                        $unitCost  = (float)$ing['unit_cost'];
                    ?>
                    <div class="ingredient-status <?= $stock >= $ing['amount'] ? 'stock-ok' : ($stock > 0 ? 'stock-low' : 'stock-missing') ?>"
                         data-id="<?= $ing['product_id'] ?>"
                         data-name="<?= htmlspecialchars($ing['product_name']) ?>"
                         data-base-qty="<?= $ing['amount'] ?>"
                         data-stock="<?= $stock ?>"
                         data-unit="<?= $unit ?>"
                         data-stock-unit="<?= htmlspecialchars($ing['stock_unit'] ?: $ing['unit']) ?>"
                         data-kj="<?= (float)$ing['kj_per_100'] ?>"
                         data-p="<?= (float)$ing['protein_per_100'] ?>"
                         data-f="<?= (float)$ing['fat_per_100'] ?>"
                         data-c="<?= (float)$ing['carb_per_100'] ?>"
                         data-wpe="<?= (float)$ing['weight_per_ea'] ?>"
                         data-unit-cost="<?= $unitCost ?>">
                        <!-- Name row: click → open sub dropdown; ↺ shown when modified -->
                        <div class="d-flex align-items-center ing-name-row mb-1" onclick="handleStateIcon(this.closest('.ingredient-status'))">
                            <span class="ing-name fw-bold text-white text-truncate" style="flex:1; min-width:0;"><?= htmlspecialchars($ing['product_name']) ?></span>
                            <span class="state-icon" style="color:#555; font-size:0.85rem; flex-shrink:0; padding-left:6px; transition:transform 0.15s;">›</span>
                            <!-- Floating substitute dropdown, populated on demand -->
                            <div class="sub-dropdown" style="display:none;"></div>
                        </div>
                        <!-- Qty row -->
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" step="0.001" class="form-control form-control-sm req-input"
                                   style="width:75px; background:#111 !important;"
                                   value="<?= number_format($ing['amount'], 3) ?>"
                                   oninput="onAmountInput(this)">
                            <span class="text-muted small"><?= $unit ?></span>
                            <span class="text-muted small px-1">/</span>
                            <button type="button" class="btn-link use-all-btn"
                                    style="color:<?= $stock > 0 ? '#4caf50' : '#555' ?>; font-size:0.85rem; padding:0; border:none; background:none; white-space:nowrap; text-decoration:none;"
                                    data-stock="<?= $stock ?>"
                                    onclick="useAll(this)"><span class="stock-display"><?= number_format($stock, 3) ?></span> <?= $unit ?></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Spice Check -->
                <div id="spiceCheckRow" class="mt-3" style="display:none;">
                    <h6 class="text-muted small fw-bold uppercase mb-2"><i class="bi bi-basket2 me-1"></i>Spice Check</h6>
                    <div id="spiceDots" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>
            <div class="col-md-8">
                <h6 class="text-muted small fw-bold uppercase mb-3">Method</h6>
                <div class="method-box"><?= htmlspecialchars(trim($recipe['instructions'] ?: 'No instructions provided.')) ?></div>
            </div>
        </div>
    </div>

    <div class="multiplier-bar">
        <div class="container">
            <div class="row align-items-end g-3">
                <div class="col-md-2">
                    <label class="small text-muted fw-bold uppercase mb-1">Batch Multiplier</label>
                    <input type="number" step="0.25" id="cookMultiplier" class="form-control fw-bold" value="1" oninput="updateUI()" onkeydown="if(event.key==='Enter') executeCook()">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted fw-bold uppercase mb-1">Eat Now (Serves)</label>
                    <input type="number" step="0.25" id="eatNow" class="form-control fw-bold" value="1" onkeydown="if(event.key==='Enter') executeCook()">
                </div>
                <div class="col-md-3 text-center">
                    <span class="text-muted small uppercase fw-bold d-block" style="font-size: 0.6rem;">Yielding</span>
                    <span class="fs-5 fw-black yielding-display"><span id="totalYield"><?= $recipe['yield_serves'] ?></span> SERVES</span>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-execute w-100 fw-black uppercase py-2" onclick="executeCook()">Execute Cook</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const baseYield = <?= (float)$recipe['yield_serves'] ?>;
        const selectedSubstitutes = {}; // originalProductId → substituteProductId

        function updateUI() {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            document.getElementById('totalYield').innerText = (baseYield * mult).toFixed(2);

            document.querySelectorAll('.ingredient-status').forEach(row => {
                if (!row.dataset.userEdited) {
                    const base = parseFloat(row.dataset.baseQty);
                    const unit = row.dataset.unit;
                    const req  = base * mult;
                    row.querySelector('.req-input').value = (unit === 'ea' && Number.isInteger(req)) ? req : req.toFixed(3);
                }
                updateRowStatus(row);
                checkModified(row);
            });

            recalcMacros();

            const btn = document.getElementById('btnIngredients');
            if (btn) {
                const anyShort = [...document.querySelectorAll('.ingredient-status')].some(r =>
                    r.classList.contains('stock-low') || r.classList.contains('stock-missing'));
                btn.classList.toggle('d-none', !anyShort);
            }
        }

        function updateRowStatus(row) {
            const req = parseFloat(row.querySelector('.req-input').value) || 0;
            const effectiveStock = parseFloat(row.dataset.effectiveStock ?? row.dataset.stock);
            row.classList.remove('stock-ok', 'stock-low', 'stock-missing');
            if (effectiveStock >= req - 0.001) row.classList.add('stock-ok');
            else if (effectiveStock > 0)       row.classList.add('stock-low');
            else                               row.classList.add('stock-missing');
        }

        // Called when the amount input is typed into
        function onAmountInput(input) {
            const row = input.closest('.ingredient-status');
            row.dataset.userEdited = '1';
            updateRowStatus(row);
            checkModified(row);
            recalcMacros();
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // ── State icon ──────────────────────────────────────────────────────────
        // › when unmodified (click = open sub dropdown)
        // ↺ when modified  (click = reset row to canonical)
        function handleStateIcon(row) {
            if (row.dataset.modified) resetRow(row);
            else openSubsInline(row);
        }

        function checkModified(row) {
            const mult     = parseFloat(document.getElementById('cookMultiplier').value) || 1;
            const canonical = parseFloat(row.dataset.baseQty) * mult;
            const current   = parseFloat(row.querySelector('.req-input').value) || 0;
            const hasSub    = !!selectedSubstitutes[row.dataset.id];
            const icon      = row.querySelector('.state-icon');
            if (hasSub || Math.abs(current - canonical) > 0.002) {
                row.dataset.modified = '1';
                icon.textContent = '↺';
                icon.style.color = '#aaa';
                icon.style.transform = '';
            } else {
                delete row.dataset.modified;
                icon.textContent = '›';
                icon.style.color = '#555';
            }
        }

        function resetRow(row) {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 1;
            const base = parseFloat(row.dataset.baseQty), unit = row.dataset.unit;
            const req  = base * mult;
            row.querySelector('.req-input').value = (unit === 'ea' && Number.isInteger(req)) ? req : req.toFixed(3);
            delete row.dataset.userEdited;

            const originalId = row.dataset.id;
            delete selectedSubstitutes[originalId];
            row.querySelector('.ing-name').textContent = row.dataset.name;
            const origStock = parseFloat(row.dataset.stock);
            row.querySelector('.stock-display').textContent = origStock.toFixed(3);
            row.dataset.effectiveStock = origStock;
            const ub = row.querySelector('.use-all-btn');
            ub.dataset.stock = origStock;
            ub.style.color   = origStock > 0 ? '#4caf50' : '#555';
            delete row.dataset.subKj; delete row.dataset.subP;
            delete row.dataset.subF;  delete row.dataset.subC; delete row.dataset.subWpe;

            closeDropdown(row);
            updateRowStatus(row);
            checkModified(row);
            recalcMacros();
        }

        // ── Substitute dropdown ──────────────────────────────────────────────────
        function openSubsInline(row) {
            const dd   = row.querySelector('.sub-dropdown');
            const icon = row.querySelector('.state-icon');
            if (dd.style.display !== 'none') { closeDropdown(row); return; }

            // Close any other open dropdowns
            document.querySelectorAll('.sub-dropdown').forEach(d => {
                if (d !== dd) closeDropdown(d.closest('.ingredient-status'));
            });

            dd.innerHTML = '<div class="px-3 py-2 text-muted small">Loading...</div>';
            dd.style.display = 'block';
            icon.style.transform = 'rotate(90deg)';

            if (!row.dataset.subsLoaded) loadSubstitutes(row);
            else rebuildDropdown(row);
        }

        function closeDropdown(row) {
            const dd = row.querySelector('.sub-dropdown');
            if (dd) dd.style.display = 'none';
            const icon = row.querySelector('.state-icon');
            if (icon) icon.style.transform = '';
        }

        function loadSubstitutes(row) {
            row.dataset.subsLoaded = '1';
            const data = new FormData();
            data.append('action', 'get_substitutes');
            data.append('name', row.dataset.name);
            fetch('../../core/raid_api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    row._subs = res.substitutes || [];
                    if (row.querySelector('.sub-dropdown').style.display !== 'none') rebuildDropdown(row);
                })
                .catch(() => {
                    const dd = row.querySelector('.sub-dropdown');
                    if (dd && dd.style.display !== 'none')
                        dd.innerHTML = '<div class="px-3 py-2 text-danger small">Error loading</div>';
                });
        }

        function rebuildDropdown(row) {
            const dd      = row.querySelector('.sub-dropdown');
            const subs    = row._subs || [];
            const origId  = row.dataset.id;
            const isSwapped = !!selectedSubstitutes[origId];
            let html = '';

            if (isSwapped) {
                html += `<div class="sub-option" onclick="event.stopPropagation(); pickSub(this, '${origId}')" data-id="">
                    <span style="color:#888; font-size:0.75rem;">↩ Use original: </span>
                    <span class="fw-bold text-white">${escHtml(row.dataset.name)}</span>
                </div>`;
            }

            if (!subs.length) {
                html += `<div class="px-3 py-2 text-muted small">${isSwapped ? '' : 'No similar products in stock'}</div>`;
            } else {
                subs.forEach(s => {
                    const sel = selectedSubstitutes[origId] == s.id;
                    html += `<div class="sub-option" onclick="event.stopPropagation(); pickSub(this, '${origId}')"
                        data-id="${s.id}" data-name="${escHtml(s.name)}" data-stock="${s.stock}"
                        data-kj="${s.kj_per_100||0}" data-p="${s.protein_per_100||0}"
                        data-f="${s.fat_per_100||0}" data-c="${s.carb_per_100||0}" data-wpe="${s.weight_per_ea||1}">
                        <span class="fw-bold" style="color:${sel?'#4caf50':'#ddd'};">${escHtml(s.name)}</span>
                        <small class="text-muted ms-2">${parseFloat(s.stock).toFixed(3)} ${s.unit}</small>
                    </div>`;
                });
            }
            dd.innerHTML = html;
        }

        function pickSub(option, origId) {
            const row    = option.closest('.ingredient-status');
            const subId  = option.dataset.id;
            const ub     = row.querySelector('.use-all-btn');

            if (subId) {
                selectedSubstitutes[origId] = subId;
                row.querySelector('.ing-name').textContent = option.dataset.name;
                const subStock = parseFloat(option.dataset.stock);
                row.querySelector('.stock-display').textContent = subStock.toFixed(3);
                row.dataset.effectiveStock = subStock;
                ub.dataset.stock = subStock; ub.style.color = subStock > 0 ? '#4caf50' : '#555';
                row.dataset.subKj = option.dataset.kj; row.dataset.subP = option.dataset.p;
                row.dataset.subF  = option.dataset.f;  row.dataset.subC = option.dataset.c;
                row.dataset.subWpe = option.dataset.wpe;
            } else {
                delete selectedSubstitutes[origId];
                row.querySelector('.ing-name').textContent = row.dataset.name;
                const os = parseFloat(row.dataset.stock);
                row.querySelector('.stock-display').textContent = os.toFixed(3);
                row.dataset.effectiveStock = os;
                ub.dataset.stock = os; ub.style.color = os > 0 ? '#4caf50' : '#555';
                delete row.dataset.subKj; delete row.dataset.subP;
                delete row.dataset.subF;  delete row.dataset.subC; delete row.dataset.subWpe;
            }
            closeDropdown(row);
            updateRowStatus(row);
            checkModified(row);
            recalcMacros();
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', e => {
            if (!e.target.closest('.ing-name-row')) {
                document.querySelectorAll('.ingredient-status').forEach(r => {
                    if (r.querySelector('.sub-dropdown')?.style.display !== 'none') closeDropdown(r);
                });
            }
        });

        // ── Qty helpers ──────────────────────────────────────────────────────────
        function useAll(btn) {
            const stock = parseFloat(btn.dataset.stock);
            if (stock <= 0) return;
            const row = btn.closest('.ingredient-status');
            row.querySelector('.req-input').value = stock.toFixed(3);
            row.dataset.userEdited = '1';
            updateRowStatus(row);
            checkModified(row);
            recalcMacros();
        }

        // ── Live macro/cost header ───────────────────────────────────────────────
        function recalcMacros() {
            const mult   = parseFloat(document.getElementById('cookMultiplier').value) || 1;
            const serves = baseYield * mult || 1;
            let totalKj = 0, totalP = 0, totalCost = 0;

            document.querySelectorAll('.ingredient-status').forEach(row => {
                const amount = parseFloat(row.querySelector('.req-input').value) || 0;
                const unit   = row.dataset.unit;
                const hasSub = !!selectedSubstitutes[row.dataset.id];
                const kj     = parseFloat(hasSub ? row.dataset.subKj  : row.dataset.kj)  || 0;
                const p      = parseFloat(hasSub ? row.dataset.subP   : row.dataset.p)   || 0;
                const wpe    = parseFloat(hasSub ? row.dataset.subWpe : row.dataset.wpe) || 1;
                const uc     = parseFloat(row.dataset.unitCost) || 0;
                const weightKg = (unit === 'ea') ? (amount * wpe) : amount;
                totalKj  += kj * (weightKg / 0.1);
                totalP   += p  * (weightKg / 0.1);
                totalCost += uc * amount;
            });

            document.getElementById('headerKj').textContent   = Math.round(totalKj / serves).toLocaleString();
            document.getElementById('headerP').textContent    = (totalP  / serves).toFixed(1);
            document.getElementById('headerCost').textContent = (totalCost / serves).toFixed(2);
        }

        function syncIngredients() {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            const toBuy = [];
            document.querySelectorAll('.ingredient-status').forEach(row => {
                const req = parseFloat(row.querySelector('.req-input').value) || 0;
                const stock = parseFloat(row.dataset.effectiveStock ?? row.dataset.stock);
                if (stock < req - 0.0001) {
                    let unit = row.dataset.unit;
                    if (unit === 'ea') unit = '';
                    if (unit === 'L') unit = 'l';
                    toBuy.push({ name: row.dataset.name, qty: req - stock, unit });
                }
            });
            if (!toBuy.length) return;
            const data = new FormData();
            data.append('action', 'sync_ingredients');
            data.append('ingredients', JSON.stringify(toBuy));
            fetch('../../core/ingredients_api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') window.open('/ingredients/', '_blank');
                    else alert(res.message);
                });
        }

        function executeCook() {
            const mult = parseFloat(document.getElementById('cookMultiplier').value) || 0;
            const eat  = parseFloat(document.getElementById('eatNow').value) || 0;

            if (mult <= 0) { alert("Batch multiplier must be greater than 0."); return; }
            if (eat < 0)   { alert("Eat Now portions cannot be negative."); return; }

            // Check no ingredient is over-requested vs effective stock
            let blocked = false;
            document.querySelectorAll('.ingredient-status').forEach(row => {
                const req = parseFloat(row.querySelector('.req-input').value) || 0;
                const stock = parseFloat(row.dataset.effectiveStock ?? row.dataset.stock);
                if (req > stock + 0.005) {
                    alert(`Not enough stock for ${row.dataset.name}. Req: ${req.toFixed(3)}, Have: ${stock.toFixed(3)}.`);
                    blocked = true;
                }
            });
            if (blocked) return;

            const totalYield = baseYield * mult;
            if (eat > totalYield + 0.001) {
                alert(`Cannot eat ${eat} servings when you only cooked ${totalYield.toFixed(2)}.`);
                return;
            }

            // Collect per-ingredient actual amounts
            const customAmounts = {};
            document.querySelectorAll('.ingredient-status').forEach(row => {
                customAmounts[row.dataset.id] = parseFloat(row.querySelector('.req-input').value) || 0;
            });

            const data = new FormData();
            data.append('action', 'cook');
            data.append('recipe_id', <?= $recipe_id ?>);
            data.append('multiplier', mult);
            data.append('eat_now', eat);
            data.append('substitutes', JSON.stringify(selectedSubstitutes));
            data.append('custom_amounts', JSON.stringify(customAmounts));

            fetch('../../core/raid_api.php', { method: 'POST', body: data })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') location.href = '../../eat/index.php';
                    else alert(res.message);
                });
        }

        // ── Spice Check ───────────────────────────────────────────────────────
        (function loadSpiceCheck() {
            const d = new FormData();
            d.append('action', 'get_recipe_spices');
            d.append('recipe_id', <?= (int)$recipe_id ?>);
            fetch('../../core/api.php', { method: 'POST', body: d })
                .then(r => r.json())
                .then(res => {
                    const ids = res.spice_ids || [];
                    if (!ids.length) return;
                    // Fetch full spice data to get is_stocked + restock_flagged
                    const d2 = new FormData(); d2.append('action', 'get_spices');
                    fetch('../../core/api.php', { method: 'POST', body: d2 })
                        .then(r => r.json())
                        .then(res2 => {
                            const spices = (res2.spices || []).filter(s => ids.includes(parseInt(s.id)));
                            if (!spices.length) return;
                            const dots = document.getElementById('spiceDots');
                            dots.innerHTML = spices.map(s => {
                                const ok = s.is_stocked == 1 && s.restock_flagged == 0;
                                const warn = s.is_stocked == 1 && s.restock_flagged == 1;
                                const color = ok ? '#4caf50' : (warn ? '#ff9800' : '#f44336');
                                const title = ok ? 'In stock' : (warn ? 'Restock soon' : 'Not stocked');
                                return `<span class="d-flex align-items-center gap-1" style="font-size:0.78rem;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;" title="${title}"></span>
                                    <span style="color:${ok ? '#ccc' : (warn ? '#ff9800' : '#f44336')};">${escHtml(s.name)}</span>
                                </span>`;
                            }).join('');
                            document.getElementById('spiceCheckRow').style.display = '';
                        });
                });
        })();

        updateUI();
    </script>
<?php include '../../core/page_foot.php'; ?>
