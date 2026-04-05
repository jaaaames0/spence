<?php
/**
 * SPENCE | User Profile & Goals (Phase 10.2: Final Polish)
 */
require_once '../core/auth.php';
require_once '../core/db_helper.php';
$db = get_db_connection();

$stmt = $db->query("SELECT * FROM user_profiles LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$has_profile = ($user !== false);

$vitals = null; $goals = null; $tdee_kj = 0; $lbm_kg = 0;

if ($has_profile) {
    $stmt = $db->prepare("SELECT * FROM user_vitals_history WHERE user_id = ? ORDER BY recorded_at DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $vitals = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT * FROM user_goals_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $goals = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($vitals) {
        $lbm_kg = $vitals['weight_kg'] * (1 - ($vitals['body_fat_pct'] / 100));
        $bmr = 370 + (21.6 * $lbm_kg);
        $tdee_kj = ($bmr * $user['activity_rate']) * 4.184;
    }
}
$page_title   = 'User Profile';
$page_context = 'settings';
$extra_styles = '<style>
    .card { background-color: #1a1a1a; border: 1px solid #333; border-radius: 12px; color: #ffffff; }
    h4 { color: #ffffff !important; }
    .form-control, .form-select { background: #2b2b2b !important; border: 1px solid #444 !important; color: #fff !important; }
    .form-control:focus, .form-select:focus { border-color: #6c757d !important; box-shadow: 0 0 0 0.25rem rgba(108, 117, 125, 0.25) !important; }
    .btn-outline-secondary { border-color: #6c757d; color: #6c757d; font-weight: 700; }
    .btn-outline-secondary:hover { background: #6c757d; color: #000; }
    .btn-primary { background-color: #6c757d; border-color: #6c757d; color: #fff; }
    .btn-primary:hover { background-color: #5a6268; border-color: #545b62; }
    input[type="range"] { accent-color: #6c757d; }
</style>';
include '../core/page_head.php';
?>
    <div class="container-fluid px-4 pb-5">
        <div class="section-header row mb-4 align-items-center">
            <div class="col-md-3"><h2 class="fw-black uppercase mb-0">Settings</h2></div>
            <div class="d-none d-md-block col-md-4">
                <div class="input-group" style="max-width: 400px; visibility: hidden;">
                    <input type="text" class="form-control">
                    <span class="input-group-text bg-dark border-secondary text-muted"><i class="bi bi-search"></i></span>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link active" href="index.php">USER PROFILE</a></li>
            <li class="nav-item"><a class="nav-link" href="products.php">PRODUCT MASTER</a></li>
            <li class="nav-item"><a class="nav-link" href="dedupe.php">DEDUPLICATION</a></li>
        </ul>

        <?php if (!$has_profile): ?>
            <div class="text-center py-5">
                <i class="bi bi-person-circle display-1 text-muted"></i>
                <h3 class="fw-black uppercase mt-3">Identity Required</h3>
                <p class="text-muted">No user profile detected. Initialize your SPENCE identity to enable goal tracking.</p>
                <button class="btn btn-primary fw-bold px-4" data-bs-toggle="modal" data-bs-target="#setupModal">INITIALIZE PROFILE</button>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <!-- Vitals Card -->
                <div class="col-lg-4">
                    <div class="card p-4 h-100">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div>
                                <h4 class="fw-black uppercase mb-0"><?= htmlspecialchars($user['name']) ?></h4>
                                <div class="text-muted small uppercase fw-bold"><?= date_diff(date_create($user['dob']), date_create('today'))->y ?> Yrs • <?= htmlspecialchars($user['gender']) ?></div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary px-3 fw-bold" onclick="openWeighInModal()">WEIGH IN</button>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="stat-label uppercase">Weight</div>
                                <div class="stat-value"><?= number_format($vitals['weight_kg'], 1) ?><span class="fs-6 ms-1 text-muted">kg</span></div>
                            </div>
                            <div class="col-6">
                                <div class="stat-label uppercase">Body Fat</div>
                                <div class="stat-value"><?= number_format($vitals['body_fat_pct'], 1) ?><span class="fs-6 ms-1 text-muted">%</span></div>
                            </div>
                            <div class="col-6">
                                <div class="stat-label uppercase">LBM</div>
                                <div class="stat-value"><?= number_format($lbm_kg, 1) ?><span class="fs-6 ms-1 text-muted">kg</span></div>
                            </div>
                            <div class="col-6">
                                <div class="stat-label uppercase">TDEE</div>
                                <div class="stat-value"><?= number_format($tdee_kj) ?><span class="fs-6 ms-1 text-muted">kJ</span></div>
                            </div>
                            <div class="col-12 mt-3 pt-2 border-top border-secondary">
                                <div class="stat-label uppercase">Activity Rate</div>
                                <select class="form-select form-select-sm mt-1" onchange="updateActivity(this.value)">
                                    <option value="1.2" <?= $user['activity_rate'] == 1.2 ? 'selected' : '' ?>>Sedentary (1.2x)</option>
                                    <option value="1.375" <?= $user['activity_rate'] == 1.375 ? 'selected' : '' ?>>Lightly Active (1.375x)</option>
                                    <option value="1.55" <?= $user['activity_rate'] == 1.55 ? 'selected' : '' ?>>Moderately Active (1.55x)</option>
                                    <option value="1.725" <?= $user['activity_rate'] == 1.725 ? 'selected' : '' ?>>Very Active (1.725x)</option>
                                    <option value="1.9" <?= $user['activity_rate'] == 1.9 ? 'selected' : '' ?>>Extra Active (1.9x)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Goals Card -->
                <div class="col-lg-8">
                    <div class="card p-4">
                        <div class="d-flex justify-content-between align-items-start mb-4">
                            <div>
                                <h4 class="fw-black uppercase mb-0">Daily Performance Targets</h4>
                                <div class="text-muted small uppercase fw-bold">Active Mode: <span class="text-accent"><?= htmlspecialchars($goals['goal_type']) ?></span></div>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary px-3" onclick="openOverrideModal()">ADJUST PLAN</button>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="p-3 border border-secondary rounded">
                                    <div class="stat-label uppercase">Energy</div>
                                    <div class="stat-value" style="color: #ff9800;"><?= number_format($goals['target_kj']) ?> <small class="fs-6 text-muted">kJ</small></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 border border-secondary rounded">
                                    <div class="stat-label uppercase">Protein</div>
                                    <div class="stat-value color-p"><?= number_format($goals['target_protein_g']) ?> <small class="fs-6 text-muted">g</small></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 border border-secondary rounded">
                                    <div class="stat-label uppercase">Fat</div>
                                    <div class="stat-value color-f"><?= number_format($goals['target_fat_g']) ?> <small class="fs-6 text-muted">g</small></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 border border-secondary rounded">
                                    <div class="stat-label uppercase">Carbs</div>
                                    <div class="stat-value color-c"><?= number_format($goals['target_carb_g']) ?> <small class="fs-6 text-muted">g</small></div>
                                </div>
                            </div>
                        </div>

                        <div class="row align-items-center">
                            <div class="col">
                                <div class="stat-label uppercase">Economic Ceiling</div>
                                <div class="stat-value text-success">$<?= number_format($goals['cost_limit_daily'], 2) ?><span class="fs-6 ms-1 text-muted">Daily limit</span></div>
                            </div>
                            <div class="col text-end">
                                <div class="stat-label uppercase">Plan Variance</div>
                                <div class="stat-value <?= $goals['target_kj'] > $tdee_kj ? 'text-danger' : 'text-primary' ?>">
                                    <?= ($goals['target_kj'] > $tdee_kj ? '+' : '') . number_format($goals['target_kj'] - $tdee_kj) ?> <small class="fs-6">kJ</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Initialization Modal -->
    <div class="modal fade" id="setupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="setupForm" onsubmit="submitInit(event)">
                    <div class="modal-header border-secondary"><h5 class="modal-title fw-black uppercase">Initialize Identity</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3"><label class="stat-label uppercase">Full Name</label><input type="text" name="name" class="form-control" required></div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="stat-label uppercase">DOB</label><input type="date" name="dob" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="stat-label uppercase">Gender</label><select name="gender" class="form-select"><option>Male</option><option>Female</option><option>Other</option></select></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="stat-label uppercase">Height (cm)</label><input type="number" name="height" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="stat-label uppercase">Activity Rate</label>
                                <select name="activity" class="form-select">
                                    <option value="1.2">Sedentary</option>
                                    <option value="1.375">Lightly Active</option>
                                    <option value="1.55">Moderately Active</option>
                                    <option value="1.725">Very Active</option>
                                    <option value="1.9">Extra Active</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-2 border-top border-secondary pt-3 mt-2">
                            <div class="col-6 mb-3"><label class="stat-label uppercase">Weight (kg)</label><input type="number" step="0.1" name="weight" class="form-control" required></div>
                            <div class="col-6 mb-3"><label class="stat-label uppercase">Body Fat %</label><input type="number" step="0.1" name="bf" class="form-control" required></div>
                        </div>
                        <div class="mb-3"><label class="stat-label uppercase">Goal Mode</label>
                            <select name="goal" class="form-select"><option>Maintenance</option><option>Weight Loss</option><option>Lean Gain</option><option>Dirty Bulk</option></select>
                        </div>
                        <div class="mb-3"><label class="stat-label uppercase">Weekly Fuel Budget ($)</label><input type="number" name="budget" class="form-control" value="150" required></div>
                    </div>
                    <div class="modal-footer border-0"><button type="submit" class="btn btn-primary w-100 fw-bold uppercase">Begin Industrialization</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Adjust Plan Modal (Smart Calculator) -->
    <div class="modal fade" id="overrideModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="overrideForm" onsubmit="submitOverride(event)">
                    <div class="modal-header border-secondary"><h5 class="modal-title fw-black uppercase">Plan Adjustments</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?? '' ?>">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="mb-3"><label class="stat-label uppercase">Target Energy (kJ)</label><input type="number" name="kj" id="calcKJ" class="form-control form-control-lg" value="<?= $goals['target_kj'] ?? '' ?>" oninput="recalcMacros()" required></div>
                                <div class="mb-3">
                                    <div class="stat-label uppercase">Daily Variance</div>
                                    <div id="varianceDisplay" class="fw-bold fs-4">0 kJ</div>
                                    <div class="text-muted small uppercase fw-bold" id="weeklyWeightDisplay">Est. ±0.0kg/week</div>
                                </div>
                                <div class="mb-3"><label class="stat-label uppercase">Weekly Budget Ceiling ($)</label><input type="number" step="0.01" id="weeklyBudgetInput" class="form-control" value="<?= number_format($goals['cost_limit_daily'] * 7, 2) ?>" required></div>
                                <div class="mb-3"><label class="stat-label uppercase">Plan Mode</label>
                                    <select name="goal_type" id="goalModeSelect" class="form-select" onchange="applyMode(this.value)">
                                        <option value="Maintenance">Maintenance</option><option value="Weight Loss">Weight Loss</option><option value="Lean Gain">Lean Gain</option><option value="Dirty Bulk">Dirty Bulk</option><option value="Custom" selected>Custom Override</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6 border-start border-secondary">
                                <div class="stat-label uppercase mb-3 text-muted">Macro Ratio Split</div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between"><label class="stat-label uppercase">Protein</label><span id="pGram" class="fw-bold color-p">0g</span></div>
                                    <input type="range" class="form-range ratio-slider" id="pRatio" value="30" min="10" max="60" oninput="balanceSliders('p')">
                                    <div class="text-end small opacity-50" id="pPct">30%</div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between"><label class="stat-label uppercase">Fat</label><span id="fGram" class="fw-bold color-f">0g</span></div>
                                    <input type="range" class="form-range ratio-slider" id="fRatio" value="30" min="10" max="60" oninput="balanceSliders('f')">
                                    <div class="text-end small opacity-50" id="fPct">30%</div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between"><label class="stat-label uppercase">Carbs</label><span id="cGram" class="fw-bold color-c">0g</span></div>
                                    <input type="range" class="form-range ratio-slider" id="cRatio" value="40" min="10" max="70" oninput="balanceSliders('c')">
                                    <div class="text-end small opacity-50" id="cPct">40%</div>
                                </div>

                                <input type="hidden" name="p" id="finalP">
                                <input type="hidden" name="f" id="finalF">
                                <input type="hidden" name="c" id="finalC">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0"><button type="submit" class="btn btn-primary w-100 fw-bold uppercase">LOCK IN PLAN</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Weigh In Modal -->
    <div class="modal fade" id="weighInModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form id="weighInForm" onsubmit="submitWeighIn(event)">
                    <div class="modal-header border-secondary"><h5 class="modal-title fw-black uppercase">Weigh In</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?? '' ?>">
                        <div class="mb-3"><label class="stat-label uppercase">Weight (kg)</label><input type="number" step="0.1" name="weight" class="form-control" value="<?= $vitals['weight_kg'] ?? '' ?>" required></div>
                        <div class="mb-3"><label class="stat-label uppercase">Body Fat %</label><input type="number" step="0.1" name="bf" class="form-control" value="<?= $vitals['body_fat_pct'] ?? '' ?>" required></div>
                    </div>
                    <div class="modal-footer border-0"><button type="submit" class="btn btn-primary w-100 fw-bold uppercase">Record Vitals</button></div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const TDEE = <?= $tdee_kj ?>;
        const LBM_KG = <?= $lbm_kg ?>;
        const KJ_PROT = 16.736, KJ_FAT = 37.656, KJ_CARB = 16.736;
        const KG_FAT_KJ = 37000;

        function openOverrideModal() { 
            new bootstrap.Modal(document.getElementById('overrideModal')).show(); 
            recalcMacros();
        }
        function openWeighInModal() { new bootstrap.Modal(document.getElementById('weighInModal')).show(); }

        function applyMode(mode) {
            const kjInput = document.getElementById('calcKJ');
            let targetKJ = TDEE;
            if(mode === 'Maintenance') targetKJ = Math.round(TDEE);
            if(mode === 'Weight Loss') targetKJ = Math.round(TDEE - 2000);
            if(mode === 'Lean Gain') targetKJ = Math.round(TDEE + 1000);
            if(mode === 'Dirty Bulk') targetKJ = Math.round(TDEE + 2500);
            if(mode === 'Custom') return;
            
            kjInput.value = targetKJ;

            // Apply 1g/lb LBM rule: Protein = LBM_KG * 2.20462
            const proteinG = LBM_KG * 2.20462;
            const proteinKJ = proteinG * KJ_PROT;
            const remainingKJ = Math.max(0, targetKJ - proteinKJ);
            
            // 50/50 kJ split for remaining
            const fKJ = remainingKJ * 0.5;
            const cKJ = remainingKJ * 0.5;

            document.getElementById('pRatio').value = Math.round((proteinKJ / targetKJ) * 100);
            document.getElementById('fRatio').value = Math.round((fKJ / targetKJ) * 100);
            document.getElementById('cRatio').value = Math.round((cKJ / targetKJ) * 100);

            recalcMacros();
        }

        function balanceSliders(active) {
            document.getElementById('goalModeSelect').value = 'Custom';
            const p = document.getElementById('pRatio'), f = document.getElementById('fRatio'), c = document.getElementById('cRatio');
            let total = parseInt(p.value) + parseInt(f.value) + parseInt(c.value);
            
            while (total !== 100) {
                const diff = 100 - total;
                const step = diff > 0 ? 1 : -1;
                if (active === 'p') { f.value = parseInt(f.value) + step; if((parseInt(p.value)+parseInt(f.value)+parseInt(c.value)) === 100) break; c.value = parseInt(c.value) + step; }
                else if (active === 'f') { p.value = parseInt(p.value) + step; if((parseInt(p.value)+parseInt(f.value)+parseInt(c.value)) === 100) break; c.value = parseInt(c.value) + step; }
                else { p.value = parseInt(p.value) + step; if((parseInt(p.value)+parseInt(f.value)+parseInt(c.value)) === 100) break; f.value = parseInt(f.value) + step; }
                total = parseInt(p.value) + parseInt(f.value) + parseInt(c.value);
            }
            recalcMacros();
        }

        function recalcMacros() {
            const kj = parseFloat(document.getElementById('calcKJ').value) || 0;
            const pr = parseInt(document.getElementById('pRatio').value) / 100;
            const fr = parseInt(document.getElementById('fRatio').value) / 100;
            const cr = parseInt(document.getElementById('cRatio').value) / 100;

            const pg = (kj * pr) / KJ_PROT;
            const fg = (kj * fr) / KJ_FAT;
            const cg = (kj * cr) / KJ_CARB;

            document.getElementById('pGram').innerText = Math.round(pg) + 'g';
            document.getElementById('fGram').innerText = Math.round(fg) + 'g';
            document.getElementById('cGram').innerText = Math.round(cg) + 'g';
            document.getElementById('pPct').innerText = Math.round(pr*100) + '%';
            document.getElementById('fPct').innerText = Math.round(fr*100) + '%';
            document.getElementById('cPct').innerText = Math.round(cr*100) + '%';

            document.getElementById('finalP').value = pg;
            document.getElementById('finalF').value = fg;
            document.getElementById('finalC').value = cg;

            const varKJ = kj - TDEE;
            const varEl = document.getElementById('varianceDisplay');
            varEl.innerText = (varKJ > 0 ? '+' : '') + Math.round(varKJ) + ' kJ';
            varEl.className = 'fw-bold fs-4 ' + (varKJ > 0 ? 'text-danger' : 'text-primary');
            
            const weeklyKg = (varKJ * 7) / KG_FAT_KJ;
            document.getElementById('weeklyWeightDisplay').innerText = 'Est. ' + (weeklyKg > 0 ? '+' : '') + weeklyKg.toFixed(2) + 'kg / week';
        }

        function updateActivity(val) {
            const data = new FormData();
            data.append('action', 'update_activity');
            data.append('activity', val);
            fetch('../core/user_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if(res.status === 'success') location.reload(); });
        }

        function submitInit(e) {
            e.preventDefault();
            const data = new FormData(e.target);
            data.append('action', 'init_profile');
            fetch('../core/user_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if(res.status === 'success') location.reload(); else alert(res.message); });
        }

        function submitOverride(e) {
            e.preventDefault();
            const data = new FormData(e.target);
            data.append('action', 'update_goals');
            const weekly = parseFloat(document.getElementById('weeklyBudgetInput').value);
            data.append('cost', (weekly / 7).toFixed(2));
            fetch('../core/user_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if(res.status === 'success') location.reload(); else alert(res.message); });
        }

        function submitWeighIn(e) {
            e.preventDefault();
            const data = new FormData(e.target);
            data.append('action', 'update_vitals');
            fetch('../core/user_api.php', { method: 'POST', body: data }).then(r => r.json()).then(res => { if(res.status === 'success') location.reload(); else alert(res.message); });
        }
    </script>
<?php include '../core/page_foot.php'; ?>
