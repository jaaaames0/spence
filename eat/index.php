<?php
/**
 * SPENCE Eat (Phase 11.6: Metrics & Search)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
$db = get_db_connection();

// Sorting logic
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';
$allowedSorts = ['name', 'current_qty', 'kj_per_100', 'protein_per_100'];
if (!in_array($sort, $allowedSorts)) $sort = 'name';
$order = ($order === 'ASC') ? 'ASC' : 'DESC';

$sortMap = [
    'name' => 'LOWER(p.name)',
    'current_qty' => 'i.current_qty',
    'kj_per_100' => 'p.kj_per_100',
    'protein_per_100' => 'p.protein_per_100'
];
$orderBy = $sortMap[$sort] . " " . $order;

$query = "SELECT i.*, p.name, p.category, p.kj_per_100, p.protein_per_100, p.weight_per_ea, p.base_unit, COALESCE(r.is_active, 1) as recipe_active
          FROM inventory i
          JOIN products p ON i.product_id = p.id
          LEFT JOIN recipes r ON p.recipe_id = r.id
          WHERE i.current_qty > 0
          ORDER BY $orderBy";

// All products for Quick Eat — Existing path (no stock requirement)
$qe_products = $db->query("
    SELECT p.id, p.name, p.category, p.base_unit, p.weight_per_ea,
           p.kj_per_100, p.protein_per_100, p.fat_per_100, p.carb_per_100
    FROM products p
    LEFT JOIN recipes r ON p.recipe_id = r.id
    WHERE p.merges_into IS NULL
      AND p.is_dropped = 0
      AND (p.type = 'raw' OR (p.type = 'cooked' AND r.is_active = 1))
    ORDER BY LOWER(p.name) ASC
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query($query);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasHistorical = false;
foreach($items as $item) { 
    if ($item['recipe_active'] == 0) $hasHistorical = true; 
}

$categoryOrder = SPENCE_CATEGORIES;
$groupedItems = array_fill_keys($categoryOrder, []);

foreach ($items as $item) {
    $cat = $item['category'] ?: 'Other';
    if (!isset($groupedItems[$cat])) $groupedItems['Other'][] = $item;
    else $groupedItems[$cat][] = $item;
}
$groupedItems = array_filter($groupedItems);

function sortUrl($col, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $col && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    return "?sort=$col&order=$newOrder";
}
$page_title   = 'Eat';
$page_context = 'raid';
$extra_styles = '<style>
    .location-header { border-left: 4px solid #ff9800; padding-left: 15px; margin: 2.5rem 0 1rem 0; color: #ff9800 !important; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-size: 1rem; }
    .card-eat { background-color: #1e1e1e; border: 1px solid #333; color: #e0e0e0; cursor: pointer; transition: 0.2s; border-radius: 4px; }
    .card-eat:hover { border-color: #ff9800; background-color: #252525; }
    .qty-large { font-size: 1.25rem; font-weight: 800; color: #fff !important; }
    .unit-label { color: #666 !important; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; }
    .btn-quick { background: #333; border: 1px solid #444; color: #fff !important; font-size: 0.75rem; padding: 12px 2px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; min-width: 0; flex: 1; }
    .btn-quick:hover { background: #444; border-color: #ff9800; }
    .btn-clear { background: #1a1a1a; border: 1px solid #444; color: #f44336 !important; font-size: 0.85rem; font-weight: 800; padding: 10px; width: 100%; }
    .btn-clear:hover { background: #222; border-color: #f44336; }
    .btn-raid { background: #ff9800; color: #000; font-weight: 900; border: none; padding: 12px; }
    .btn-raid:hover { background: #e68900; color: #000; }
</style>';
include '../core/page_head.php';
?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">Raid Inventory</h2></div>
            <div class="col-md-4">
                <div class="input-group" style="max-width: 400px;">
                    <input type="text" id="tableSearch" class="form-control" placeholder="Search by name or category..." oninput="filterCards(this.value)">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                </div>
            </div>
            <div class="col-md-5 text-end d-flex align-items-center justify-content-end gap-3">
                <div class="d-inline-flex gap-3 small fw-bold text-muted uppercase" style="font-size: 0.65rem; letter-spacing: 1px;">
                    <span><span class="badge badge-kj me-1">#</span> kJ</span>
                    <span><span class="badge badge-protein me-1">#</span> Protein</span>
                    <span><span class="badge badge-cost me-1">$</span> Cost</span>
                    <span class="d-none d-md-inline text-white">(per 100g / per 100mL / each)</span>
                </div>
                <div class="dropdown">
                    <button class="btn fw-black uppercase dropdown-toggle" type="button" data-bs-toggle="dropdown" style="background:#ff9800; color:#000; border:none; height:38px;">
                        <i class="bi bi-lightning-fill me-1"></i>Quick Eat
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li><a class="dropdown-item" href="#" onclick="document.getElementById('qeNewFoodInput').click(); return false;"><i class="bi bi-camera-fill me-2" style="color:#ff9800;"></i>New Food (Photo)</a></li>
                        <li><a class="dropdown-item" href="#" onclick="openQuickEatExisting(); return false;"><i class="bi bi-list-ul me-2"></i>Existing Product</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php foreach ($groupedItems as $category => $itemsInCat): ?>
            <div class="category-block" data-category="<?= htmlspecialchars($category) ?>">
                <h4 class="location-header"><?= htmlspecialchars($category) ?></h4>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">
                    <?php foreach ($itemsInCat as $item): ?>
                    <div class="col eat-card-col">
                        <?php 
                            $is_ea = $item['base_unit'] === 'ea';
                            $weight = $is_ea ? $item['weight_per_ea'] : 0.1;
                            $factor = $weight / 0.1;
                            
                            $kj = round($item['kj_per_100'] * $factor);
                            $prot = $item['protein_per_100'] * $factor;
                            $cost = ($item['price_paid'] / ($item['current_qty'] ?: 1)) * ($is_ea ? 1 : 0.1);
                        ?>
                        <div class="card card-eat h-100 p-2" onclick="openConsumeModal(<?= htmlspecialchars(json_encode($item)) ?>)" title="Per <?= $is_ea ? 'ea' : '100g/ml' ?> | kJ: <?= $kj ?> | P: <?= number_format($prot, 1) ?>g | Cost: $<?= number_format($cost, 2) ?>">
                            <div class="product-name fw-bold mb-2"><?= htmlspecialchars($item['name']) ?><?= $item['recipe_active'] == 0 ? '*' : '' ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-auto">
                                <div class="qty-large">
                                    <?php 
                                        $qty = (float)$item['current_qty'];
                                        echo ($is_ea && floor($qty) == $qty) ? number_format($qty, 0) : number_format($qty, 3);
                                    ?> <span class="unit-label"><?= htmlspecialchars($item['base_unit']) ?></span>
                                </div>
                                <div class="d-flex gap-1">
                                    <span class="badge badge-kj x-small" title="Energy Density"><?= $kj ?></span>
                                    <span class="badge badge-protein x-small" title="Protein Density"><?= number_format($prot, 1) ?></span>
                                    <span class="badge badge-cost x-small" title="Cost Density">$<?= number_format($cost, 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($hasHistorical): ?>
            <div class="mt-3 text-muted small uppercase fw-bold historical-note">
                (*) Macros and cost are based on a historical version of this recipe.
            </div>
        <?php endif; ?>
    </div>

    <!-- Consume Modal -->
    <div class="modal fade" id="consumeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title fw-bold" id="itemName">Item Name</h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label text-muted small text-uppercase fw-bold">Amount to Consume (<span id="unitLabel">ea</span>)</label>
                        <input type="number" step="0.001" class="form-control form-control-lg fw-bold" id="consumeAmount" placeholder="0.000">
                        <div class="d-flex justify-content-between mt-2">
                            <div class="text-muted small">Available: <span id="availableQty" class="text-white">0.000</span></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex gap-1 mb-2">
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.001)">+1<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.005)">+5<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.010)">+10<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.050)">+50<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.100)">+100<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(0.500)">+500<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-gram-increment" onclick="addAmount(1.000)">+1k<span class="btn-unit-small">g</span></button>
                            <button class="btn btn-quick btn-ea-only d-none" onclick="addAmount(1)">+1 ea</button>
                        </div>
                        <div class="d-flex gap-1 mb-2">
                            <button class="btn btn-quick" onclick="setPercent(0.10)">10%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.25)">25%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.33)">33%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.50)">50%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.67)">67%</button>
                            <button class="btn btn-quick" onclick="setPercent(0.75)">75%</button>
                            <button class="btn btn-quick" onclick="setPercent(1.00)">100%</button>
                        </div>
                        <button class="btn btn-clear uppercase" onclick="clearAmount()">Clear Amount</button>
                    </div>

                    <div id="macroPreview" class="macro-box p-3 bg-black rounded border border-secondary">
                        <div class="row text-center">
                            <div class="col border-end border-secondary">
                                <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Energy</small>
                                <span id="previewKj" class="fw-bold fs-4" style="color: #ff9800;">0</span> <small style="color: #ff9800;">kJ</small>
                            </div>
                            <div class="col">
                                <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Protein</small>
                                <span id="previewP" class="fw-bold text-primary fs-4">0</span> <small class="text-primary">g</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-raid w-100 uppercase" onclick="submitConsumption()">Raid Item</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentItem = null;
        const modal = new bootstrap.Modal(document.getElementById('consumeModal'));
        const amountInput = document.getElementById('consumeAmount');

        function filterCards(query) {
            const q = query.toLowerCase();
            const blocks = document.querySelectorAll('.category-block');
            blocks.forEach(block => {
                const category = block.dataset.category.toLowerCase();
                let visibleInBlock = 0;
                const cols = block.querySelectorAll('.eat-card-col');
                
                // If query matches the category name, show everything in block
                const categoryMatch = category.includes(q);

                cols.forEach(col => {
                    const name = col.querySelector('.product-name').innerText.toLowerCase();
                    if (categoryMatch || name.includes(q)) {
                        col.style.display = '';
                        visibleInBlock++;
                    } else {
                        col.style.display = 'none';
                    }
                });
                block.style.display = visibleInBlock > 0 ? '' : 'none';
            });
        }

        function openConsumeModal(item) {
            currentItem = item;
            const isEa = item.base_unit === 'ea';
            document.getElementById('itemName').innerText = item.name;
            document.getElementById('unitLabel').innerText = item.base_unit;
            document.getElementById('availableQty').innerText = parseFloat(item.current_qty).toFixed(3);
            amountInput.value = '';
            document.getElementById('macroPreview').classList.remove('visible');
            
            const smallUnit = item.base_unit === 'L' ? 'ml' : (item.base_unit === 'kg' ? 'g' : item.base_unit);
            document.querySelectorAll('.btn-unit-small').forEach(el => el.innerText = smallUnit);
            
            // Toggle visibility based on unit
            document.querySelectorAll('.btn-gram-increment').forEach(btn => {
                if(isEa) btn.classList.add('d-none');
                else btn.classList.remove('d-none');
            });
            
            const eaBtn = document.querySelector('.btn-ea-only');
            if(isEa) eaBtn.classList.remove('d-none');
            else eaBtn.classList.add('d-none');
            
            modal.show();
            setTimeout(() => amountInput.focus(), 500);
        }

        function addAmount(val) {
            let current = parseFloat(amountInput.value) || 0;
            let next = current + val;
            let available = parseFloat(currentItem.current_qty);
            if (next > available) next = available;
            amountInput.value = next.toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        function clearAmount() {
            amountInput.value = (0).toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        function setPercent(p) {
            if(!currentItem) return;
            amountInput.value = (currentItem.current_qty * p).toFixed(3);
            amountInput.dispatchEvent(new Event('input'));
        }

        amountInput.addEventListener('input', () => {
            const amount = parseFloat(amountInput.value);
            if (!amount || !currentItem || amount <= 0) {
                document.getElementById('macroPreview').classList.remove('visible');
                return;
            }
            const weight = (currentItem.base_unit === 'ea') ? (amount * currentItem.weight_per_ea) : amount;
            const factor = weight / 0.1;
            const kj = factor * currentItem.kj_per_100;
            const p = factor * currentItem.protein_per_100;
            
            document.getElementById('previewKj').innerText = Math.round(kj).toLocaleString();
            document.getElementById('previewP').innerText = p.toFixed(1);
            document.getElementById('macroPreview').classList.add('visible');
        });

        function submitConsumption() {
            const amount = parseFloat(amountInput.value);
            if (!amount || amount <= 0) return alert('Enter a valid amount');
            if (amount > (parseFloat(currentItem.current_qty) + 0.0001)) return alert('Insufficient stock');
            
            const data = new FormData();
            data.append('action', 'consume');
            data.append('id', currentItem.id);
            data.append('amount', amount);
            
            fetch('../core/raid_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => { 
                if (res.status === 'success') location.reload(); 
                else alert('Error: ' + res.message); 
            });
        }
    </script>

    <form enctype="multipart/form-data" onsubmit="return false;" style="display:none;">
        <input type="file" id="qeNewFoodInput" accept="image/*;capture=camera" onchange="qeNewFoodSelected(this)">
    </form>

    <!-- ==================== QUICK EAT MODALS ==================== -->

    <!-- Photo: scan + review -->
    <div class="modal fade" id="qePhotoModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase"><i class="bi bi-camera-fill me-2" style="color:#ff9800;"></i>Quick Eat — New Food</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- State 1: Preview + scan button -->
                    <div id="qeScanState">
                        <div class="mb-3 text-center">
                            <img id="qePreviewImg" src="" style="max-height:220px; max-width:100%; border-radius:4px; border:1px solid #333;">
                        </div>
                        <button class="btn w-100 fw-bold uppercase py-2" onclick="doScan()" style="background:#ff9800; color:#000; border:none;">
                            <i class="bi bi-cpu me-1"></i>Analyse Food
                        </button>
                    </div>
                    <!-- State 2: Scanning spinner -->
                    <div id="qeScanningState" class="text-center py-4" style="display:none;">
                        <div class="spinner-border" style="color:#ff9800; width:2.5rem; height:2.5rem;" role="status"></div>
                        <div class="mt-3 text-muted fw-bold uppercase" style="font-size:0.8rem; letter-spacing:1px;">Analysing food...</div>
                    </div>
                    <!-- State 3: Review + edit (multi-item) -->
                    <div id="qeReviewState" style="display:none;">
                        <div id="qeItemList" class="mb-3"></div>
                        <!-- Combined totals -->
                        <div class="p-3 rounded" style="background:#0a0a0a; border:1px solid #444;">
                            <div class="text-muted uppercase fw-bold mb-2" style="font-size:0.65rem; letter-spacing:1px;">Combined Total</div>
                            <div class="row text-center g-0">
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.6rem;">Energy</small>
                                    <span id="qeTotalKj" class="fw-bold" style="color:#ff9800;">0</span> <small style="color:#ff9800;">kJ</small>
                                </div>
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.6rem;">Protein</small>
                                    <span id="qeTotalP" class="fw-bold text-primary">0</span> <small class="text-primary">g</small>
                                </div>
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.6rem;">Fat</small>
                                    <span id="qeTotalF" class="fw-bold" style="color:#f44336;">0</span> <small style="color:#f44336;">g</small>
                                </div>
                                <div class="col">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.6rem;">Carbs</small>
                                    <span id="qeTotalC" class="fw-bold" style="color:#ab47bc;">0</span> <small style="color:#ab47bc;">g</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- State 4: Success -->
                    <div id="qeSuccessState" class="text-center py-4" style="display:none;">
                        <i class="bi bi-check-circle-fill" style="color:#4caf50; font-size:2.5rem;"></i>
                        <div class="mt-2 fw-bold text-white">Logged to intake</div>
                        <div id="qeSuccessMacros" class="mt-2 text-muted small"></div>
                    </div>
                </div>
                <div class="modal-footer border-0" id="qePhotoFooter">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">CANCEL</button>
                    <button type="button" class="btn fw-black uppercase" id="qeLogBtn" onclick="logPhotoEat()" style="background:#ff9800; color:#000; border:none;" disabled>LOG INTAKE</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Existing: search + log -->
    <div class="modal fade" id="qeExistingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-black uppercase"><i class="bi bi-list-ul me-2" style="color:#ff9800;"></i>Quick Eat — Existing Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- State 1: Search list -->
                    <div id="qeSearchState">
                        <div class="input-group mb-3">
                            <input type="text" id="qeProductSearch" class="form-control" placeholder="Search products..." oninput="filterQeProducts(this.value)" autofocus>
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                        </div>
                        <div id="qeProductList" style="max-height:320px; overflow-y:auto;"></div>
                    </div>
                    <!-- State 2: Amount entry -->
                    <div id="qeAmountState" style="display:none;">
                        <div class="d-flex align-items-center gap-2 mb-4">
                            <button class="btn btn-sm btn-outline-secondary" onclick="backToSearch()"><i class="bi bi-arrow-left"></i></button>
                            <span id="qeSelectedName" class="fw-bold fs-5 text-white"></span>
                            <span id="qeSelectedUnit" class="badge bg-secondary text-uppercase"></span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted uppercase">Amount (<span id="qeAmountUnit"></span>)</label>
                            <input type="number" step="0.001" id="qeAmount" class="form-control form-control-lg fw-bold" placeholder="0.000" oninput="updateExistingPreview()">
                        </div>
                        <div id="qeExistingPreview" class="p-3 rounded mb-3" style="background:#0a0a0a; border:1px solid #333; display:none;">
                            <div class="row text-center g-0">
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.65rem;">Energy</small>
                                    <span id="qeExPrevKj" class="fw-bold" style="color:#ff9800;">—</span> <small style="color:#ff9800;">kJ</small>
                                </div>
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.65rem;">Protein</small>
                                    <span id="qeExPrevP" class="fw-bold text-primary">—</span> <small class="text-primary">g</small>
                                </div>
                                <div class="col border-end border-secondary">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.65rem;">Fat</small>
                                    <span id="qeExPrevF" class="fw-bold" style="color:#f44336;">—</span> <small style="color:#f44336;">g</small>
                                </div>
                                <div class="col">
                                    <small class="d-block text-muted uppercase fw-bold" style="font-size:0.65rem;">Carbs</small>
                                    <span id="qeExPrevC" class="fw-bold" style="color:#ab47bc;">—</span> <small style="color:#ab47bc;">g</small>
                                </div>
                            </div>
                        </div>
                        <!-- Success inline -->
                        <div id="qeExSuccessState" class="text-center py-3" style="display:none;">
                            <i class="bi bi-check-circle-fill" style="color:#4caf50; font-size:2rem;"></i>
                            <div class="mt-2 fw-bold text-white">Logged to intake</div>
                            <div id="qeExSuccessMacros" class="mt-1 text-muted small"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0" id="qeExistingFooter">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">CLOSE</button>
                    <button type="button" class="btn fw-black uppercase" id="qeExLogBtn" onclick="logExistingEat()" style="background:#ff9800; color:#000; border:none; display:none;">LOG INTAKE</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    const qePhotoModal    = new bootstrap.Modal(document.getElementById('qePhotoModal'));
    const qeExistingModal = new bootstrap.Modal(document.getElementById('qeExistingModal'));
    const qeAllProducts   = <?= json_encode($qe_products) ?>;
    let qeSelectedProduct = null;
    let qeSelectedFile    = null;

    // ---- Photo path ----
    function qeNewFoodSelected(input) {
        const file = input.files[0];
        if (!file) return;
        qeSelectedFile = file;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('qePreviewImg').src = e.target.result;
            resetPhotoModal();
            qePhotoModal.show();
        };
        reader.readAsDataURL(file);
        input.value = ''; // Reset so same file can be re-selected
    }

    function resetPhotoModal() {
        document.getElementById('qeScanState').style.display = '';
        document.getElementById('qeScanningState').style.display = 'none';
        document.getElementById('qeReviewState').style.display = 'none';
        document.getElementById('qeSuccessState').style.display = 'none';
        document.getElementById('qePhotoFooter').style.display = '';
        document.getElementById('qeLogBtn').disabled = true;
    }

    function doScan() {
        if (!qeSelectedFile) return;

        document.getElementById('qeScanState').style.display = 'none';
        document.getElementById('qeScanningState').style.display = '';

        const data = new FormData();
        data.append('action', 'scan');
        data.append('image', qeSelectedFile);

        fetch('../core/quick_eat_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                document.getElementById('qeScanningState').style.display = 'none';
                if (res.status !== 'success') {
                    document.getElementById('qeScanState').style.display = '';
                    alert('Scan failed: ' + res.message);
                    return;
                }
                const d = res.data;
                renderQeItems(res.items);
                document.getElementById('qeReviewState').style.display = '';
                document.getElementById('qeLogBtn').disabled = false;
            })
            .catch(err => {
                document.getElementById('qeScanningState').style.display = 'none';
                document.getElementById('qeScanState').style.display = '';
                alert('Request failed. Check your connection and try again.\n\n' + err);
            });
    }

    function renderQeItems(items) {
        // Normalize: model may return a single object instead of array
        if (!Array.isArray(items)) items = items ? [items] : [];
        items = items.filter(Boolean);
        if (!items.length) { alert('AI could not identify any food items. Try again.'); return; }
        const list = document.getElementById('qeItemList');
        list.innerHTML = items.map((item, i) => `
            <div class="mb-2 p-2" style="background:#1a1a1a; border:1px solid #333; border-radius:4px;">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <input type="checkbox" class="form-check-input qe-chk flex-shrink-0" id="qe-chk-${i}" checked onchange="updateQeTotals()">
                    <input type="text" class="form-control form-control-sm fw-bold" id="qe-name-${i}" value="${item.name || ''}" style="flex:1;">
                    <div class="input-group input-group-sm" style="width:100px; flex-shrink:0;">
                        <input type="number" class="form-control form-control-sm text-end qe-weight" id="qe-weight-${i}" value="${Math.round(item.estimated_weight_g) || ''}" min="1" oninput="updateQeTotals()">
                        <span class="input-group-text" style="background:#1a1a1a; border-color:#444; color:#666; font-size:0.7rem;">g</span>
                    </div>
                </div>
                <div class="d-flex gap-1 align-items-center">
                    <input type="number" class="form-control form-control-sm text-center qe-kj" id="qe-kj-${i}" value="${Math.round(item.kj_per_100) || 0}" oninput="updateQeTotals()" title="kJ/100g" style="width:60px;" placeholder="kJ">
                    <input type="number" step="0.1" class="form-control form-control-sm text-center qe-p" id="qe-p-${i}" value="${item.protein_per_100 || 0}" oninput="updateQeTotals()" title="Protein/100g" style="width:55px;" placeholder="P">
                    <input type="number" step="0.1" class="form-control form-control-sm text-center qe-f" id="qe-f-${i}" value="${item.fat_per_100 || 0}" oninput="updateQeTotals()" title="Fat/100g" style="width:55px;" placeholder="F">
                    <input type="number" step="0.1" class="form-control form-control-sm text-center qe-c" id="qe-c-${i}" value="${item.carbs_per_100 || 0}" oninput="updateQeTotals()" title="Carbs/100g" style="width:55px;" placeholder="C">
                    <span class="text-muted ms-1" style="font-size:0.6rem; white-space:nowrap;">per 100g</span>
                    <div class="form-check ms-auto mb-0 d-flex align-items-center gap-1" style="white-space:nowrap;">
                        <input class="form-check-input mt-0 qe-master" type="checkbox" id="qe-master-${i}">
                        <label class="form-check-label text-muted" style="font-size:0.65rem;" for="qe-master-${i}">Add to Master</label>
                    </div>
                    <input type="hidden" id="qe-cat-${i}" value="${item.category || 'Other'}">
                </div>
            </div>
        `).join('');
        updateQeTotals();
    }

    function updateQeTotals() {
        let totalKj = 0, totalP = 0, totalF = 0, totalC = 0;
        const n = document.querySelectorAll('.qe-chk').length;
        for (let i = 0; i < n; i++) {
            if (!document.getElementById('qe-chk-' + i)?.checked) continue;
            const w = parseFloat(document.getElementById('qe-weight-' + i)?.value) || 0;
            const f = w / 100;
            totalKj += (parseFloat(document.getElementById('qe-kj-' + i)?.value) || 0) * f;
            totalP  += (parseFloat(document.getElementById('qe-p-' + i)?.value) || 0) * f;
            totalF  += (parseFloat(document.getElementById('qe-f-' + i)?.value) || 0) * f;
            totalC  += (parseFloat(document.getElementById('qe-c-' + i)?.value) || 0) * f;
        }
        document.getElementById('qeTotalKj').innerText = Math.round(totalKj);
        document.getElementById('qeTotalP').innerText  = totalP.toFixed(1);
        document.getElementById('qeTotalF').innerText  = totalF.toFixed(1);
        document.getElementById('qeTotalC').innerText  = totalC.toFixed(1);
    }

    function logPhotoEat() {
        const n = document.querySelectorAll('.qe-chk').length;
        const items = [];
        for (let i = 0; i < n; i++) {
            if (!document.getElementById('qe-chk-' + i)?.checked) continue;
            items.push({
                name:           document.getElementById('qe-name-' + i).value,
                weight_g:       parseFloat(document.getElementById('qe-weight-' + i).value) || 0,
                kj_per_100:     parseFloat(document.getElementById('qe-kj-' + i).value) || 0,
                protein_per_100: parseFloat(document.getElementById('qe-p-' + i).value) || 0,
                fat_per_100:    parseFloat(document.getElementById('qe-f-' + i).value) || 0,
                carbs_per_100:  parseFloat(document.getElementById('qe-c-' + i).value) || 0,
                category:       document.getElementById('qe-cat-' + i).value,
                add_to_master:  document.getElementById('qe-master-' + i).checked
            });
        }
        if (!items.length) { alert('No items selected.'); return; }

        const data = new FormData();
        data.append('action', 'log_photo');
        data.append('items', JSON.stringify(items));

        fetch('../core/quick_eat_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'success') { alert('Error: ' + res.message); return; }
                document.getElementById('qeReviewState').style.display = 'none';
                document.getElementById('qePhotoFooter').style.display = 'none';
                const m = res.macros;
                document.getElementById('qeSuccessMacros').innerText =
                    `${Math.round(m.kj)} kJ · ${parseFloat(m.protein).toFixed(1)}g P · ${parseFloat(m.fat).toFixed(1)}g F · ${parseFloat(m.carb).toFixed(1)}g C`;
                document.getElementById('qeSuccessState').style.display = '';
            });
    }

    // ---- Existing path ----
    function openQuickEatExisting() {
        resetExistingModal();
        qeExistingModal.show();
    }

    function resetExistingModal() {
        qeSelectedProduct = null;
        document.getElementById('qeProductSearch').value = '';
        document.getElementById('qeSearchState').style.display = '';
        document.getElementById('qeAmountState').style.display = 'none';
        document.getElementById('qeExLogBtn').style.display = 'none';
        document.getElementById('qeExSuccessState').style.display = 'none';
        document.getElementById('qeExistingPreview').style.display = 'none';
        filterQeProducts('');
    }

    function filterQeProducts(q) {
        const query = q.toLowerCase();
        const list = document.getElementById('qeProductList');
        const filtered = query
            ? qeAllProducts.filter(p => p.name.toLowerCase().includes(query) || p.category.toLowerCase().includes(query))
            : qeAllProducts;

        list.innerHTML = filtered.slice(0, 50).map(p => `
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom border-secondary"
                 style="cursor:pointer; transition:0.15s;"
                 onmouseover="this.style.background='#252525'" onmouseout="this.style.background=''"
                 onclick="selectQeProduct(${p.id})">
                <div>
                    <span class="fw-bold text-white">${p.name}</span>
                    <span class="text-muted small ms-2 uppercase">${p.category}</span>
                </div>
                <span class="badge bg-secondary opacity-50 text-uppercase" style="font-size:0.65rem;">${p.base_unit}</span>
            </div>
        `).join('');

        if (!filtered.length) {
            list.innerHTML = '<div class="text-center text-muted p-3 small">No products found.</div>';
        }
    }

    function selectQeProduct(id) {
        qeSelectedProduct = qeAllProducts.find(p => p.id === id);
        if (!qeSelectedProduct) return;

        document.getElementById('qeSearchState').style.display = 'none';
        document.getElementById('qeSelectedName').innerText = qeSelectedProduct.name;
        document.getElementById('qeSelectedUnit').innerText  = qeSelectedProduct.base_unit;
        document.getElementById('qeAmountUnit').innerText    = qeSelectedProduct.base_unit;
        document.getElementById('qeAmount').value = '';
        document.getElementById('qeExistingPreview').style.display = 'none';
        document.getElementById('qeExSuccessState').style.display = 'none';
        document.getElementById('qeAmountState').style.display = '';
        document.getElementById('qeExLogBtn').style.display = '';
        document.getElementById('qeExLogBtn').disabled = true;
        setTimeout(() => document.getElementById('qeAmount').focus(), 100);
    }

    function backToSearch() {
        document.getElementById('qeAmountState').style.display = 'none';
        document.getElementById('qeExLogBtn').style.display = 'none';
        document.getElementById('qeSearchState').style.display = '';
    }

    function updateExistingPreview() {
        const amount = parseFloat(document.getElementById('qeAmount').value) || 0;
        const p = qeSelectedProduct;
        if (!p || amount <= 0) {
            document.getElementById('qeExistingPreview').style.display = 'none';
            document.getElementById('qeExLogBtn').disabled = true;
            return;
        }

        const weight = (p.base_unit === 'ea') ? (amount * p.weight_per_ea) : amount;
        const f = weight / 0.1; // weight in kg → factor for per-100g macros
        document.getElementById('qeExPrevKj').innerText = Math.round(p.kj_per_100 * f);
        document.getElementById('qeExPrevP').innerText  = (p.protein_per_100 * f).toFixed(1);
        document.getElementById('qeExPrevF').innerText  = (p.fat_per_100 * f).toFixed(1);
        document.getElementById('qeExPrevC').innerText  = (p.carb_per_100 * f).toFixed(1);
        document.getElementById('qeExistingPreview').style.display = '';
        document.getElementById('qeExLogBtn').disabled = false;
    }

    function logExistingEat() {
        const amount = parseFloat(document.getElementById('qeAmount').value) || 0;
        if (!qeSelectedProduct || amount <= 0) return;

        const data = new FormData();
        data.append('action', 'log_existing');
        data.append('product_id', qeSelectedProduct.id);
        data.append('amount', amount);

        fetch('../core/quick_eat_api.php', { method: 'POST', body: data })
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'success') { alert('Error: ' + res.message); return; }
                document.getElementById('qeAmountState').style.display = 'none';
                document.getElementById('qeExLogBtn').style.display = 'none';
                const m = res.macros;
                document.getElementById('qeExSuccessMacros').innerText =
                    `${m.kj} kJ · ${m.protein}g P · ${m.fat}g F · ${m.carb}g C`;
                document.getElementById('qeExSuccessState').style.display = '';
            });
    }
    </script>
<?php include '../core/page_foot.php'; ?>
