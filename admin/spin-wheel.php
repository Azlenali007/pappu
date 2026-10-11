<?php
/**
 * Admin Spin Wheel Management
 * SMM Panel - PHP 8.3+
 *
 * Full Administrative Controls:
 * 1. Global Controls: Enable/disable wheel, max reward limit, spin cooldown.
 * 2. Bet Tier Management: Add, edit, enable/disable bet tiers (e.g. ₹1, ₹5, ₹10).
 * 3. Outcome Management: For each bet amount, configure segments:
 *    - Reward Type: Jackpot, Monetary Reward, Money Back, No Reward
 *    - Display Label (e.g. "₹30 JACKPOT")
 *    - Reward Amount
 *    - Winning Probability Percentage (Strict exact 100.000% validation)
 *    - Segment Background & Text Colors
 *    - Safe Vector Icon Key
 *    - Enabled / Disabled status
 *    - Display Order
 * 4. Strict Validation:
 *    - Rejects totals other than 100.000%
 *    - Rejects negative probabilities or invalid amounts
 *    - Atomically updates snapshots and logs changes in spin_wheel_audit_logs
 * 5. Private Security: Probabilities remain strictly restricted to admin side.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spin_wheel_engine.php';

Auth::requireAdmin();
$adminUser = Auth::user();
$adminId = (int)$adminUser['id'];
$db = Database::getConnection();

SpinWheelEngine::ensureTablesExist();

$error = null;
$success = null;

// Determine current selected bet tier for outcome editing
$allBets = SpinWheelEngine::getBets(false);
$selectedBetId = (int)($_GET['bet_id'] ?? (!empty($allBets) ? $allBets[0]['id'] : 0));

// Form Submission Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. UPDATE GLOBAL SETTINGS
    if ($action === 'update_global_settings') {
        $enabled = isset($_POST['spin_wheel_enabled']) ? '1' : '0';
        $maxReward = (float)($_POST['max_reward_limit'] ?? 1000);
        $cooldown = max(1, (int)($_POST['cooldown_seconds'] ?? 2));

        set_setting('spin_wheel_enabled', $enabled);
        set_setting('spin_wheel_max_reward_limit', (string)$maxReward);
        set_setting('spin_wheel_cooldown_seconds', (string)$cooldown);

        SpinWheelEngine::logAdminAction($adminId, 'update_global_settings', [
            'enabled' => $enabled,
            'max_reward' => $maxReward,
            'cooldown' => $cooldown
        ]);

        set_flash('success', 'Global Spin Wheel settings updated successfully.');
        redirect('/admin/spin-wheel.php?bet_id=' . $selectedBetId);
    }

    // 2. ADD NEW BET TIER
    elseif ($action === 'add_bet_tier') {
        $betAmount = (float)($_POST['bet_amount'] ?? 0);
        if ($betAmount <= 0) {
            $error = "Bet amount must be greater than zero.";
        } else {
            try {
                $ins = $db->prepare("
                    INSERT INTO spin_wheel_bets (bet_amount, is_enabled, display_order)
                    VALUES (:amt, 1, (SELECT COALESCE(MAX(display_order), 0) + 1 FROM spin_wheel_bets b))
                ");
                $ins->execute(['amt' => $betAmount]);
                $newBetId = (int)$db->lastInsertId();

                // Seed default 8 standard balanced segments (Totaling 100.000%)
                $jackpotAmt = $betAmount * 30;
                $rewardAmt2 = $betAmount * 2;
                $rewardAmt5 = $betAmount * 5;

                $seedOutcomes = [
                    ['jackpot', format_currency($jackpotAmt) . ' JACKPOT', $jackpotAmt, 0.500, 'trophy', '#7c3aed', '#ffffff', 0],
                    ['no_reward', 'TRY AGAIN', 0.0000, 35.000, 'cross', '#1e293b', '#94a3b8', 1],
                    ['reward', format_currency($rewardAmt2) . ' Reward', $rewardAmt2, 15.000, 'coin', '#2563eb', '#ffffff', 2],
                    ['money_back', 'MONEY BACK', $betAmount, 25.000, 'refresh', '#059669', '#ffffff', 3],
                    ['reward', format_currency($rewardAmt5) . ' Reward', $rewardAmt5, 4.500, 'gift', '#d97706', '#ffffff', 4],
                    ['no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 5],
                    ['reward', format_currency($betAmount * 10) . ' Reward', $betAmount * 10, 2.000, 'sparkles', '#4f46e5', '#ffffff', 6],
                    ['money_back', format_currency($betAmount) . ' Return', $betAmount, 3.000, 'refresh', '#0d9488', '#ffffff', 7]
                ];

                $insOut = $db->prepare("
                    INSERT INTO spin_wheel_outcomes (bet_id, reward_type, label, reward_amount, probability, icon_key, color_bg, color_text, is_enabled, display_order)
                    VALUES (:bid, :rtype, :label, :amt, :prob, :icon, :bg, :tx, 1, :dorder)
                ");
                foreach ($seedOutcomes as $so) {
                    $insOut->execute([
                        'bid'   => $newBetId,
                        'rtype' => $so[0],
                        'label' => $so[1],
                        'amt'   => $so[2],
                        'prob'  => $so[3],
                        'icon'  => $so[4],
                        'bg'    => $so[5],
                        'tx'    => $so[6],
                        'dorder'=> $so[7]
                    ]);
                }

                SpinWheelEngine::logAdminAction($adminId, 'add_bet_tier', ['bet_amount' => $betAmount, 'id' => $newBetId]);
                set_flash('success', 'New bet tier added with default 100.000% outcome configuration.');
                redirect('/admin/spin-wheel.php?bet_id=' . $newBetId);
            } catch (\PDOException $pe) {
                $error = "A bet tier with amount " . format_currency($betAmount) . " already exists.";
            }
        }
    }

    // 3. TOGGLE OR DELETE BET TIER
    elseif ($action === 'toggle_bet_tier') {
        $betId = (int)$_POST['bet_id'];
        $status = (int)$_POST['status'];
        $db->prepare("UPDATE spin_wheel_bets SET is_enabled = :st WHERE id = :id")->execute(['st' => $status, 'id' => $betId]);
        SpinWheelEngine::logAdminAction($adminId, 'toggle_bet_tier', ['bet_id' => $betId, 'status' => $status]);
        set_flash('success', 'Bet tier status updated.');
        redirect('/admin/spin-wheel.php?bet_id=' . $betId);
    }

    // 4. ATOMIC OUTCOMES & PROBABILITIES BATCH UPDATE (With Strict 100.000% Check)
    elseif ($action === 'save_outcomes_batch') {
        $betId = (int)$_POST['bet_id'];
        $outcomesData = $_POST['outcomes'] ?? [];

        if (empty($outcomesData) || !is_array($outcomesData)) {
            $error = "No outcomes provided for update.";
        } else {
            // Verify and calculate total probability
            $totalUnits = 0;
            $hasNegative = false;
            $parsedOutcomes = [];

            foreach ($outcomesData as $oid => $row) {
                $oid = (int)$oid;
                $prob = (float)($row['probability'] ?? 0);
                $rewardAmt = (float)($row['reward_amount'] ?? 0);
                $label = trim((string)($row['label'] ?? ''));
                $rewardType = in_array($row['reward_type'] ?? '', ['jackpot', 'reward', 'money_back', 'no_reward']) ? $row['reward_type'] : 'reward';
                $isEnabled = !empty($row['is_enabled']) ? 1 : 0;
                $colorBg = trim((string)($row['color_bg'] ?? '#3b82f6'));
                $colorText = trim((string)($row['color_text'] ?? '#ffffff'));
                $iconKey = trim((string)($row['icon_key'] ?? 'trophy'));
                $displayOrder = (int)($row['display_order'] ?? 0);

                if ($prob < 0) {
                    $hasNegative = true;
                }

                // If reward is jackpot, guarantee label mentions JACKPOT and amount
                if ($rewardType === 'jackpot' && !str_contains(strtoupper($label), 'JACKPOT')) {
                    $label = format_currency($rewardAmt) . ' JACKPOT';
                }

                // Accumulate probability units only for enabled segments
                if ($isEnabled === 1) {
                    $units = (int)round($prob * SpinWheelEngine::PROBABILITY_MULTIPLIER);
                    $totalUnits += $units;
                }

                $parsedOutcomes[$oid] = [
                    'label' => $label,
                    'reward_type' => $rewardType,
                    'reward_amount' => $rewardAmt,
                    'probability' => $prob,
                    'icon_key' => $iconKey,
                    'color_bg' => $colorBg,
                    'color_text' => $colorText,
                    'is_enabled' => $isEnabled,
                    'display_order' => $displayOrder
                ];
            }

            if ($hasNegative) {
                $error = "Negative probability values are strictly forbidden.";
            } else {
                $totalPercent = $totalUnits / SpinWheelEngine::PROBABILITY_MULTIPLIER;

                // STRICT VALIDATION: Must equal exactly 100.000%
                if ($totalUnits !== SpinWheelEngine::TOTAL_PROBABILITY_UNITS) {
                    $error = sprintf(
                        "Validation Failed: Total enabled probabilities must equal exactly 100.000%%. Current sum is %.3f%% (Difference: %+.3f%%). Changes were not saved to prevent invalid wheel states.",
                        $totalPercent,
                        $totalPercent - 100.0
                    );
                } else {
                    // Save atomically in database
                    $db->beginTransaction();
                    try {
                        $upd = $db->prepare("
                            UPDATE spin_wheel_outcomes
                            SET label = :label,
                                reward_type = :rtype,
                                reward_amount = :amt,
                                probability = :prob,
                                icon_key = :icon,
                                color_bg = :bg,
                                color_text = :tx,
                                is_enabled = :en,
                                display_order = :dorder
                            WHERE id = :id AND bet_id = :bid
                        ");

                        foreach ($parsedOutcomes as $oid => $p) {
                            $upd->execute([
                                'label'  => $p['label'],
                                'rtype'  => $p['reward_type'],
                                'amt'    => $p['reward_amount'],
                                'prob'   => $p['probability'],
                                'icon'   => $p['icon_key'],
                                'bg'     => $p['color_bg'],
                                'tx'     => $p['color_text'],
                                'en'     => $p['is_enabled'],
                                'dorder' => $p['display_order'],
                                'id'     => $oid,
                                'bid'    => $betId
                            ]);
                        }

                        // Create immutable version snapshot
                        SpinWheelEngine::getOrCreateConfigVersion($betId, $adminId);

                        SpinWheelEngine::logAdminAction($adminId, 'save_outcomes_batch', [
                            'bet_id' => $betId,
                            'total_probability' => $totalPercent,
                            'outcomes_count' => count($parsedOutcomes)
                        ]);

                        $db->commit();
                        set_flash('success', 'Wheel outcomes & verified 100.000% probabilities saved successfully.');
                        redirect('/admin/spin-wheel.php?bet_id=' . $betId);

                    } catch (\Throwable $e) {
                        $db->rollBack();
                        $error = "Failed to save outcomes: " . $e->getMessage();
                    }
                }
            }
        }
    }

    // 5. ADD SINGLE OUTCOME SEGMENT
    elseif ($action === 'add_outcome_segment') {
        $betId = (int)$_POST['bet_id'];
        $rewardType = in_array($_POST['reward_type'] ?? '', ['jackpot', 'reward', 'money_back', 'no_reward']) ? $_POST['reward_type'] : 'reward';
        $label = trim($_POST['label'] ?? 'New Reward');
        $rewardAmt = (float)($_POST['reward_amount'] ?? 0);
        $prob = (float)($_POST['probability'] ?? 0);
        $iconKey = trim($_POST['icon_key'] ?? 'trophy');
        $colorBg = trim($_POST['color_bg'] ?? '#2563eb');

        if ($rewardType === 'jackpot' && !str_contains(strtoupper($label), 'JACKPOT')) {
            $label = format_currency($rewardAmt) . ' JACKPOT';
        }

        $ins = $db->prepare("
            INSERT INTO spin_wheel_outcomes (bet_id, reward_type, label, reward_amount, probability, icon_key, color_bg, color_text, is_enabled, display_order)
            VALUES (:bid, :rtype, :label, :amt, :prob, :icon, :bg, '#ffffff', 1, (SELECT COALESCE(MAX(display_order), 0) + 1 FROM spin_wheel_outcomes o WHERE bet_id = :bid2))
        ");
        $ins->execute([
            'bid'   => $betId,
            'rtype' => $rewardType,
            'label' => $label,
            'amt'   => $rewardAmt,
            'prob'  => $prob,
            'icon'  => $iconKey,
            'bg'    => $colorBg,
            'bid2'  => $betId
        ]);

        SpinWheelEngine::logAdminAction($adminId, 'add_outcome_segment', ['bet_id' => $betId, 'label' => $label]);
        set_flash('info', 'New segment added. Please adjust probabilities across all segments to total exactly 100.000%.');
        redirect('/admin/spin-wheel.php?bet_id=' . $betId);
    }

    // 6. DELETE OUTCOME SEGMENT
    elseif ($action === 'delete_outcome_segment') {
        $outcomeId = (int)$_POST['outcome_id'];
        $betId = (int)$_POST['bet_id'];

        $db->prepare("DELETE FROM spin_wheel_outcomes WHERE id = :id AND bet_id = :bid")->execute(['id' => $outcomeId, 'bid' => $betId]);
        SpinWheelEngine::logAdminAction($adminId, 'delete_outcome_segment', ['outcome_id' => $outcomeId, 'bet_id' => $betId]);
        set_flash('warning', 'Segment removed. Remember to rebalance remaining probabilities to 100.000%.');
        redirect('/admin/spin-wheel.php?bet_id=' . $betId);
    }
}

// Fetch Global Settings
$isGlobalEnabled = SpinWheelEngine::isEnabled();
$maxRewardLimit = get_setting('spin_wheel_max_reward_limit', '1000.0000');
$cooldownSeconds = get_setting('spin_wheel_cooldown_seconds', '2');

// Fetch Current Bet and its Outcomes
$currentBet = SpinWheelEngine::getBetById($selectedBetId);
$currentOutcomes = $selectedBetId > 0 ? SpinWheelEngine::getOutcomesForBet($selectedBetId, false) : [];
$probValidation = $selectedBetId > 0 ? SpinWheelEngine::validateBetProbability($selectedBetId) : ['isValid' => false, 'total' => 0];

// Fetch Audit Logs (Recent 10 entries)
$auditLogs = $db->query("
    SELECT a.*, u.username
    FROM spin_wheel_audit_logs a
    LEFT JOIN users u ON a.admin_id = u.id
    ORDER BY a.id DESC LIMIT 10
")->fetchAll() ?: [];

// Total Spins Stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total_spins,
        COALESCE(SUM(bet_amount), 0) as total_volume,
        COALESCE(SUM(reward_amount), 0) as total_payouts,
        COUNT(CASE WHEN reward_type = 'jackpot' THEN 1 END) as jackpot_hits
    FROM spin_spins
    WHERE status = 'completed'
")->fetch() ?: ['total_spins' => 0, 'total_volume' => 0, 'total_payouts' => 0, 'jackpot_hits' => 0];

$supportedIcons = SpinWheelEngine::getSupportedIcons();

$pageTitle = "Spin Wheel Management - " . APP_NAME . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">

    <!-- Top Bar Navigation & Stats -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-purple-50 text-purple-700 border border-purple-200">
                <span class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></span>
                <span>Casino &amp; Rewards Engine</span>
            </div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight mt-1">
                Spin Wheel Management
            </h1>
            <p class="text-xs text-slate-500">Configure bet tiers, reward segments, and exact 100.000% winning odds</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="/user/spin.php" target="_blank" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all flex items-center gap-1.5">
                <span>Preview Wheel &rarr;</span>
            </a>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2" role="alert">
            <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Aggregate Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs">
            <span class="text-xs text-slate-400 font-semibold block">Total Completed Spins</span>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono-nums"><?= number_format((int)$stats['total_spins']) ?></div>
            <span class="text-[10px] text-slate-400">Lifetime play count</span>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs">
            <span class="text-xs text-slate-400 font-semibold block">Total Turnover (Bets)</span>
            <div class="text-2xl font-black text-blue-600 mt-1 font-mono-nums"><?= format_currency($stats['total_volume']) ?></div>
            <span class="text-[10px] text-slate-400">Total wagered amount</span>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs">
            <span class="text-xs text-slate-400 font-semibold block">Total Payouts Won</span>
            <div class="text-2xl font-black text-emerald-600 mt-1 font-mono-nums"><?= format_currency($stats['total_payouts']) ?></div>
            <span class="text-[10px] text-slate-400">Credited into user wallets</span>
        </div>
        <div class="bg-white rounded-3xl p-5 border border-slate-200/80 shadow-xs">
            <span class="text-xs text-slate-400 font-semibold block">Jackpot Hits</span>
            <div class="text-2xl font-black text-purple-600 mt-1 font-mono-nums"><?= number_format((int)$stats['jackpot_hits']) ?></div>
            <span class="text-[10px] text-slate-400">Grand prizes awarded</span>
        </div>
    </div>

    <!-- 1. GLOBAL SYSTEM CONTROLS -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-black">
                    ⚙️
                </div>
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Global Feature Controls</h2>
                    <p class="text-xs text-slate-400">Toggle public availability, cooldowns, and safety payout ceilings</p>
                </div>
            </div>
        </div>

        <form action="/admin/spin-wheel.php?bet_id=<?= $selectedBetId ?>" method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="update_global_settings">

            <div class="flex items-center justify-between p-4 rounded-2xl bg-slate-50 border border-slate-200/80">
                <div>
                    <label class="text-xs font-bold text-slate-800 block">Spin Wheel Status</label>
                    <span class="text-[11px] text-slate-500">Enable or disable game globally</span>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="spin_wheel_enabled" value="1" <?= $isGlobalEnabled ? 'checked' : '' ?> class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                </label>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Max Reward Limit (Safety Cap)</label>
                <div class="relative">
                    <input type="number" step="0.01" min="0" name="max_reward_limit" value="<?= e($maxRewardLimit) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-purple-500/20 focus:outline-none">
                </div>
                <span class="text-[10px] text-slate-400 mt-1 block">Maximum amount any spin outcome can credit</span>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Spin Cooldown (Seconds)</label>
                <div class="relative">
                    <input type="number" min="1" max="60" name="cooldown_seconds" value="<?= e($cooldownSeconds) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-purple-500/20 focus:outline-none">
                </div>
                <span class="text-[10px] text-slate-400 mt-1 block">Prevents automated spam or rapid clicking</span>
            </div>

            <div class="sm:col-span-3 flex justify-end">
                <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-purple-500/20 transition-all cursor-pointer">
                    Save Global Settings
                </button>
            </div>
        </form>
    </div>

    <!-- 2. BET TIERS SELECTOR & ADDITION -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900">Configured Bet Tiers</h2>
                <p class="text-xs text-slate-400">Select a bet tier below to edit its individual wheel segments and winning probabilities</p>
            </div>

            <!-- Add Bet Tier Form -->
            <form action="/admin/spin-wheel.php" method="POST" class="flex items-center gap-2">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_bet_tier">
                <input type="number" step="0.5" min="0.5" name="bet_amount" placeholder="Amount (e.g. 10)" required class="w-36 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:ring-2 focus:ring-blue-500/20 focus:outline-none">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-sm transition-all whitespace-nowrap cursor-pointer">
                    + Add Bet Tier
                </button>
            </form>
        </div>

        <!-- Bet Tiers Tabs -->
        <div class="flex flex-wrap items-center gap-2.5">
            <?php foreach ($allBets as $b): 
                $isSelected = ((int)$b['id'] === $selectedBetId);
                $bVal = SpinWheelEngine::validateBetProbability((int)$b['id']);
            ?>
                <div class="inline-flex items-center rounded-2xl border p-1 transition-all <?= $isSelected ? 'bg-purple-50/80 border-purple-300 shadow-sm' : 'bg-slate-50 border-slate-200/80 hover:bg-slate-100' ?>">
                    <a href="/admin/spin-wheel.php?bet_id=<?= (int)$b['id'] ?>" class="px-3.5 py-1.5 text-xs font-black transition-colors <?= $isSelected ? 'text-purple-700' : 'text-slate-700' ?>">
                        <?= format_currency($b['bet_amount']) ?>
                        <?php if (!$b['is_enabled']): ?>
                            <span class="text-[10px] text-rose-500 font-bold ml-1">(Disabled)</span>
                        <?php endif; ?>
                        <?php if (!$bVal['isValid']): ?>
                            <span class="text-[10px] text-amber-600 font-bold ml-1">⚠️ <?= number_format($bVal['total'], 1) ?>%</span>
                        <?php endif; ?>
                    </a>

                    <!-- Toggle Status Form -->
                    <form action="/admin/spin-wheel.php?bet_id=<?= (int)$b['id'] ?>" method="POST" class="inline">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="toggle_bet_tier">
                        <input type="hidden" name="bet_id" value="<?= (int)$b['id'] ?>">
                        <input type="hidden" name="status" value="<?= $b['is_enabled'] ? 0 : 1 ?>">
                        <button type="submit" class="px-2 py-1 text-[10px] font-bold rounded-lg <?= $b['is_enabled'] ? 'text-slate-400 hover:text-rose-600' : 'text-emerald-600 hover:bg-emerald-50' ?>" title="<?= $b['is_enabled'] ? 'Disable this tier' : 'Enable this tier' ?>">
                            <?= $b['is_enabled'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. WHEEL OUTCOMES & PROBABILITIES FOR SELECTED BET TIER -->
    <?php if ($currentBet): ?>
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-extrabold text-slate-900">
                            Segments for Bet Amount: <?= format_currency($currentBet['bet_amount']) ?>
                        </h2>
                        <?php if ($probValidation['isValid']): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-black bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span>✓ Valid 100.000% Odds</span>
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-black bg-rose-50 text-rose-700 border border-rose-200 animate-pulse">
                                <span>⚠️ Current Sum: <?= number_format($probValidation['total'], 3) ?>% (Needs exactly 100.000%)</span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Winning probabilities strictly govern server-side outcome selection. Values are never exposed to public visitors.
                    </p>
                </div>

                <!-- Add Segment Modal Button -->
                <button type="button" onclick="document.getElementById('addSegmentDrawer').classList.toggle('hidden')" class="px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-extrabold text-xs rounded-xl shadow-sm transition-all cursor-pointer">
                    + Add Segment Outcome
                </button>
            </div>

            <!-- Add Segment Collapsible Form -->
            <div id="addSegmentDrawer" class="hidden p-5 rounded-2xl bg-blue-50/60 border border-blue-200/80 space-y-4">
                <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">Create New Wheel Segment</h3>
                <form action="/admin/spin-wheel.php?bet_id=<?= $selectedBetId ?>" method="POST" class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="add_outcome_segment">
                    <input type="hidden" name="bet_id" value="<?= $selectedBetId ?>">

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Reward Type</label>
                        <select name="reward_type" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none">
                            <option value="jackpot">Jackpot</option>
                            <option value="reward" selected>Monetary Reward</option>
                            <option value="money_back">Money Back</option>
                            <option value="no_reward">No Reward / Try Again</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Segment Label</label>
                        <input type="text" name="label" placeholder="e.g. ₹20 Reward" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Reward Amount</label>
                        <input type="number" step="0.01" min="0" name="reward_amount" placeholder="0.00" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Probability (%)</label>
                        <input type="number" step="0.001" min="0" max="100" name="probability" placeholder="e.g. 10.000" required class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Icon Style</label>
                        <select name="icon_key" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none">
                            <?php foreach ($supportedIcons as $k => $ic): ?>
                                <option value="<?= e($k) ?>"><?= e($ic['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[10px] font-bold text-slate-600 mb-1">Background Color</label>
                        <input type="color" name="color_bg" value="#3b82f6" class="w-full h-9 p-1 bg-white border border-slate-200 rounded-xl cursor-pointer">
                    </div>

                    <div class="sm:col-span-3 lg:col-span-6 flex justify-end">
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-xs transition-all cursor-pointer">
                            Create Segment
                        </button>
                    </div>
                </form>
            </div>

            <!-- Batch Outcomes Form -->
            <form action="/admin/spin-wheel.php?bet_id=<?= $selectedBetId ?>" method="POST" id="outcomesBatchForm" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="save_outcomes_batch">
                <input type="hidden" name="bet_id" value="<?= $selectedBetId ?>">

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="text-slate-400 font-bold border-b border-slate-200/80">
                                <th class="pb-3 px-2">Order</th>
                                <th class="pb-3 px-2">Status</th>
                                <th class="pb-3 px-2">Reward Type</th>
                                <th class="pb-3 px-2">Display Label (Inside Wheel)</th>
                                <th class="pb-3 px-2">Reward Amount</th>
                                <th class="pb-3 px-2">Probability (%)</th>
                                <th class="pb-3 px-2">Colors</th>
                                <th class="pb-3 px-2">Icon</th>
                                <th class="pb-3 px-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            <?php foreach ($currentOutcomes as $idx => $oc): 
                                $ocId = (int)$oc['id'];
                            ?>
                                <tr class="hover:bg-slate-50/60 transition-colors">
                                    <td class="py-3 px-2 w-14">
                                        <input type="number" name="outcomes[<?= $ocId ?>][display_order]" value="<?= (int)$oc['display_order'] ?>" class="w-12 px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-center text-xs font-bold">
                                    </td>
                                    <td class="py-3 px-2 w-16">
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="outcomes[<?= $ocId ?>][is_enabled]" value="1" <?= $oc['is_enabled'] ? 'checked' : '' ?> onchange="calculateLiveProbabilityTotal()" class="sr-only peer prob-enable-toggle">
                                            <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
                                        </label>
                                    </td>
                                    <td class="py-3 px-2 w-32">
                                        <select name="outcomes[<?= $ocId ?>][reward_type]" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold">
                                            <option value="jackpot" <?= $oc['reward_type'] === 'jackpot' ? 'selected' : '' ?>>Jackpot</option>
                                            <option value="reward" <?= $oc['reward_type'] === 'reward' ? 'selected' : '' ?>>Reward</option>
                                            <option value="money_back" <?= $oc['reward_type'] === 'money_back' ? 'selected' : '' ?>>Money Back</option>
                                            <option value="no_reward" <?= $oc['reward_type'] === 'no_reward' ? 'selected' : '' ?>>No Reward</option>
                                        </select>
                                    </td>
                                    <td class="py-3 px-2">
                                        <input type="text" name="outcomes[<?= $ocId ?>][label]" value="<?= e($oc['label']) ?>" required class="w-full min-w-[140px] px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold">
                                    </td>
                                    <td class="py-3 px-2 w-28">
                                        <input type="number" step="0.01" min="0" name="outcomes[<?= $ocId ?>][reward_amount]" value="<?= (float)$oc['reward_amount'] ?>" required class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold font-mono-nums">
                                    </td>
                                    <td class="py-3 px-2 w-28">
                                        <input type="number" step="0.001" min="0" max="100" name="outcomes[<?= $ocId ?>][probability]" value="<?= number_format((float)$oc['probability'], 3, '.', '') ?>" oninput="calculateLiveProbabilityTotal()" required class="prob-input w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-black font-mono-nums focus:bg-white focus:ring-2 focus:ring-purple-500/20">
                                    </td>
                                    <td class="py-3 px-2 w-24">
                                        <div class="flex items-center gap-1.5">
                                            <input type="color" name="outcomes[<?= $ocId ?>][color_bg]" value="<?= e($oc['color_bg']) ?>" class="w-7 h-7 p-0.5 rounded border border-slate-200 cursor-pointer" title="Background Color">
                                            <input type="color" name="outcomes[<?= $ocId ?>][color_text]" value="<?= e($oc['color_text'] ?: '#ffffff') ?>" class="w-7 h-7 p-0.5 rounded border border-slate-200 cursor-pointer" title="Text Color">
                                        </div>
                                    </td>
                                    <td class="py-3 px-2 w-28">
                                        <select name="outcomes[<?= $ocId ?>][icon_key]" class="w-full px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold">
                                            <?php foreach ($supportedIcons as $k => $ic): ?>
                                                <option value="<?= e($k) ?>" <?= $oc['icon_key'] === $k ? 'selected' : '' ?>><?= e($ic['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="py-3 px-2 text-right w-16">
                                        <button type="button" onclick="deleteSegment(<?= $ocId ?>)" class="text-xs font-bold text-rose-500 hover:text-rose-700 p-1 hover:bg-rose-50 rounded-lg transition-colors cursor-pointer" title="Remove segment">
                                            &times; Remove
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Live Probability Sum Bar & Submit Button -->
                <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold text-slate-500">Live Active Probability Sum:</span>
                        <div id="liveSumBadge" class="px-3 py-1 rounded-xl font-black text-xs font-mono-nums <?= $probValidation['isValid'] ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800 animate-pulse' ?>">
                            <span id="liveSumValue"><?= number_format($probValidation['total'], 3) ?></span>% / 100.000%
                        </div>
                    </div>

                    <button type="submit" id="saveOutcomesBtn" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 active:scale-95 text-white font-black text-xs shadow-md shadow-purple-500/25 transition-all cursor-pointer">
                        Save Verified Configuration
                    </button>
                </div>
            </form>

            <!-- Hidden Delete Form -->
            <form id="deleteSegmentForm" action="/admin/spin-wheel.php?bet_id=<?= $selectedBetId ?>" method="POST" class="hidden">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="delete_outcome_segment">
                <input type="hidden" name="bet_id" value="<?= $selectedBetId ?>">
                <input type="hidden" name="outcome_id" id="deleteOutcomeIdInput">
            </form>
        </div>
    <?php endif; ?>

    <!-- 4. ADMINISTRATIVE AUDIT TRAIL LOGS -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h2 class="text-base font-extrabold text-slate-900">Audit Trail (Configuration Changes)</h2>
                <p class="text-xs text-slate-400">Chronological history of administrative changes made to the Spin Wheel</p>
            </div>
            <span class="text-xs font-bold text-slate-400">Last 10 Records</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-slate-400 font-bold border-b border-slate-100">
                        <th class="pb-2.5 px-3">Timestamp</th>
                        <th class="pb-2.5 px-3">Administrator</th>
                        <th class="pb-2.5 px-3">Action</th>
                        <th class="pb-2.5 px-3">Details</th>
                        <th class="pb-2.5 px-3 text-right">IP Address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($auditLogs)): ?>
                        <tr>
                            <td colspan="5" class="py-4 text-center text-slate-400">No administrative changes recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $log): ?>
                            <tr class="hover:bg-slate-50/60">
                                <td class="py-2.5 px-3 text-slate-500 whitespace-nowrap font-mono-nums"><?= e($log['created_at']) ?></td>
                                <td class="py-2.5 px-3 font-bold text-slate-800"><?= e($log['username'] ?: 'System') ?></td>
                                <td class="py-2.5 px-3 font-semibold text-purple-700"><?= e($log['action']) ?></td>
                                <td class="py-2.5 px-3 text-slate-500 text-[11px] max-w-xs truncate" title="<?= e($log['details']) ?>"><?= e($log['details']) ?></td>
                                <td class="py-2.5 px-3 text-right text-slate-400 font-mono-nums"><?= e($log['ip_address'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function deleteSegment(outcomeId) {
    if (confirm('Are you sure you want to remove this segment? You will need to rebalance the remaining probabilities to 100.000%.')) {
        document.getElementById('deleteOutcomeIdInput').value = outcomeId;
        document.getElementById('deleteSegmentForm').submit();
    }
}

function calculateLiveProbabilityTotal() {
    const rows = document.querySelectorAll('#outcomesBatchForm tbody tr');
    let total = 0;

    rows.forEach(r => {
        const toggle = r.querySelector('.prob-enable-toggle');
        const input = r.querySelector('.prob-input');
        if (toggle && toggle.checked && input) {
            const val = parseFloat(input.value) || 0;
            total += Math.round(val * 1000); // 3 decimals
        }
    });

    const sumPercent = (total / 1000);
    const sumValEl = document.getElementById('liveSumValue');
    const badge = document.getElementById('liveSumBadge');
    const saveBtn = document.getElementById('saveOutcomesBtn');

    if (sumValEl) sumValEl.textContent = sumPercent.toFixed(3);

    if (total === 100000) {
        badge.className = 'px-3 py-1 rounded-xl font-black text-xs font-mono-nums bg-emerald-100 text-emerald-800';
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.classList.remove('opacity-60', 'cursor-not-allowed');
        }
    } else {
        badge.className = 'px-3 py-1 rounded-xl font-black text-xs font-mono-nums bg-rose-100 text-rose-800 animate-pulse';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    calculateLiveProbabilityTotal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
