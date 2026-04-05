<?php
/**
 * SPENCE User API v1.0 (Phase 10.0: Identity & Goals)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_helper.php';
header('Content-Type: application/json');
$db = get_db_connection();

$action = $_POST['action'] ?? '';

try {
    if ($action === 'init_profile') {
        $db->beginTransaction();
        
        $name = $_POST['name'];
        $dob = $_POST['dob'];
        $gender = $_POST['gender'];
        $height = (float)$_POST['height'];
        $activity = (float)$_POST['activity'];
        $budget = (float)$_POST['budget'];
        
        $weight = (float)$_POST['weight'];
        $bf = (float)$_POST['bf'];
        $goal_type = $_POST['goal'];

        // 1. Create Profile
        $stmt = $db->prepare("INSERT INTO user_profiles (name, dob, gender, height_cm, activity_rate, weekly_budget) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $dob, $gender, $height, $activity, $budget]);
        $user_id = $db->lastInsertId();

        // 2. Record First Vitals
        $stmt = $db->prepare("INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $weight, $bf]);

        // 3. Calculate Goals (Katch-McArdle)
        $lbm = $weight * (1 - ($bf / 100));
        $bmr = 370 + (21.6 * $lbm);
        $tdee_kcal = $bmr * $activity;
        $tdee_kj = $tdee_kcal * 4.184;
        
        $kj_goal = $tdee_kj;
        if ($goal_type === 'Weight Loss') $kj_goal -= 2000;
        if ($goal_type === 'Lean Gain') $kj_goal += 1000;
        if ($goal_type === 'Dirty Bulk') $kj_goal += 2500;

        // New Protein Rule: 1g per lb of LBM
        $target_p = $lbm * 2.20462;
        $protein_kj = $target_p * 16.736;

        // Split remaining kJ between Fat (40%) and Carbs (60%) for a balanced start
        $remaining_kj = max(0, $kj_goal - $protein_kj);
        $target_f = ($remaining_kj * 0.4) / 37.656;
        $target_c = ($remaining_kj * 0.6) / 16.736;

        $stmt = $db->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $goal_type, round($kj_goal), round($target_p), round($target_f), round($target_c), round($budget / 7, 2)]);

        $db->commit();
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_vitals') {
        $user_id = (int)$_POST['user_id'];
        $weight = (float)$_POST['weight'];
        $bf = (float)$_POST['bf'];

        $stmt = $db->prepare("INSERT INTO user_vitals_history (user_id, weight_kg, body_fat_pct) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $weight, $bf]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_goals') {
        $user_id = (int)$_POST['user_id'];
        $kj = (float)$_POST['kj'];
        $p = (float)$_POST['p'];
        $f = (float)$_POST['f'];
        $c = (float)$_POST['c'];
        $cost = (float)$_POST['cost'];
        $goal_type = $_POST['goal_type'] ?? 'Manual Override';

        $stmt = $db->prepare("INSERT INTO user_goals_history (user_id, goal_type, target_kj, target_protein_g, target_fat_g, target_carb_g, cost_limit_daily) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $goal_type, $kj, $p, $f, $c, $cost]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'update_activity') {
        $activity = (float)$_POST['activity'];
        // Update both profile and any future goal calculations
        $db->prepare("UPDATE user_profiles SET activity_rate = ?")->execute([$activity]);
        echo json_encode(['status' => 'success']);

    } elseif ($action === 'delete_product') {
        $id = (int)$_POST['id'];
        // Check if in use in inventory or log first? 
        // For now, industrial delete: nuke from products table
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
