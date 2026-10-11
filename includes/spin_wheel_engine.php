<?php
/**
 * Spin Wheel Business Engine & Repository
 * SMM Panel - PHP 8.3+
 *
 * Implements:
 * 1. Schema migration auto-sync (if needed)
 * 2. Secure probability outcome selection using CSPRNG (random_int)
 * 3. Atomic wallet debit & credit within database transactions
 * 4. Configuration validation (exact 100% check with 3 decimal precision)
 * 5. Immutable version snapshots
 * 6. Public wheel configuration generator (sanitized, zero secret leakage)
 * 7. Real recent winners ledger (privacy masked, verified wins only)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

class SpinWheelEngine {

    /**
     * Precision multiplier for probability calculations (3 decimal places: 100.000% = 100,000 units)
     */
    public const PROBABILITY_MULTIPLIER = 1000;
    public const TOTAL_PROBABILITY_UNITS = 100000;

    /**
     * Supported Safe Vector Icon Set for Segments
     */
    public static function getSupportedIcons(): array {
        return [
            'trophy' => [
                'name' => 'Trophy',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>'
            ],
            'coin' => [
                'name' => 'Coins',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
            ],
            'gift' => [
                'name' => 'Gift Box',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V4a2 2 0 10-4 0v4m4-4a2 2 0 114 0v4M4 8h16v4H4V8zm2 4v8a2 2 0 002 2h8a2 2 0 002-2v-8"/></svg>'
            ],
            'refresh' => [
                'name' => 'Money Back / Refund',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>'
            ],
            'sparkles' => [
                'name' => 'Sparkles / Star',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>'
            ],
            'cross' => [
                'name' => 'No Reward / Try Again',
                'svg'  => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>'
            ]
        ];
    }

    /**
     * Ensures database tables exist (Runs automatically once if tables are missing)
     */
    public static function ensureTablesExist(): void {
        static $checked = false;
        if ($checked) return;

        try {
            $db = Database::getConnection();
            $check = $db->query("SHOW TABLES LIKE 'spin_wheel_bets'");
            if (!$check->fetch()) {
                $migFile = APP_ROOT . '/database/migrations/005_spin_wheel_system.sql';
                if (file_exists($migFile)) {
                    $sql = file_get_contents($migFile);
                    $statements = split_sql_statements($sql);
                    foreach ($statements as $stmt) {
                        if (trim($stmt) !== '') {
                            try {
                                $db->exec($stmt);
                            } catch (\Throwable $se) {
                                // Ignore benign "already exists" or duplicate column errors
                                error_log("SpinWheelEngine migration notice: " . $se->getMessage());
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("SpinWheelEngine::ensureTablesExist error: " . $e->getMessage());
        }
        $checked = true;
    }

    /**
     * Checks if Spin Wheel feature is globally enabled
     */
    public static function isEnabled(): bool {
        self::ensureTablesExist();
        return get_setting('spin_wheel_enabled', '1') === '1';
    }

    /**
     * Gets all configured bet options (Admin & User)
     * @param bool $onlyActive If true, returns only active bets
     */
    public static function getBets(bool $onlyActive = true): array {
        self::ensureTablesExist();
        $db = Database::getConnection();
        $sql = "SELECT * FROM spin_wheel_bets";
        if ($onlyActive) {
            $sql .= " WHERE is_enabled = 1";
        }
        $sql .= " ORDER BY display_order ASC, bet_amount ASC";
        $stmt = $db->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Gets a single bet row by ID
     */
    public static function getBetById(int $betId): ?array {
        self::ensureTablesExist();
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM spin_wheel_bets WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $betId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Gets all configured outcomes for a bet amount
     * @param int $betId
     * @param bool $onlyActive
     */
    public static function getOutcomesForBet(int $betId, bool $onlyActive = true): array {
        self::ensureTablesExist();
        $db = Database::getConnection();
        $sql = "SELECT * FROM spin_wheel_outcomes WHERE bet_id = :bid";
        if ($onlyActive) {
            $sql .= " AND is_enabled = 1";
        }
        $sql .= " ORDER BY display_order ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute(['bid' => $betId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Validates that total probability for a bet configuration equals exactly 100.000%
     * Returns array with [isValid => bool, total => float, error => ?string]
     */
    public static function validateBetProbability(int $betId): array {
        $outcomes = self::getOutcomesForBet($betId, true);
        if (empty($outcomes)) {
            return [
                'isValid' => false,
                'total' => 0.0,
                'count' => 0,
                'error' => 'No active reward outcomes configured for this bet amount.'
            ];
        }

        $totalUnits = 0;
        foreach ($outcomes as $o) {
            $prob = (float)$o['probability'];
            if ($prob < 0) {
                return [
                    'isValid' => false,
                    'total' => 0.0,
                    'count' => count($outcomes),
                    'error' => "Negative probability not allowed on outcome: " . e($o['label'])
                ];
            }
            $units = (int)round($prob * self::PROBABILITY_MULTIPLIER);
            $totalUnits += $units;
        }

        $totalPercent = $totalUnits / self::PROBABILITY_MULTIPLIER;
        $isValid = ($totalUnits === self::TOTAL_PROBABILITY_UNITS);

        $error = null;
        if (!$isValid) {
            $error = sprintf(
                'Total probability must equal exactly 100.000%%. Current total is %.3f%% (Difference: %+.3f%%).',
                $totalPercent,
                $totalPercent - 100.0
            );
        }

        return [
            'isValid' => $isValid,
            'total' => $totalPercent,
            'units' => $totalUnits,
            'count' => count($outcomes),
            'error' => $error
        ];
    }

    /**
     * Generates or fetches an immutable configuration version snapshot for this bet
     */
    public static function getOrCreateConfigVersion(int $betId, ?int $adminId = null): ?int {
        $db = Database::getConnection();
        $outcomes = self::getOutcomesForBet($betId, true);
        if (empty($outcomes)) return null;

        $serialized = json_encode($outcomes);
        $hash = hash('sha256', $serialized);

        $stmt = $db->prepare("SELECT id FROM spin_wheel_config_versions WHERE bet_id = :bid AND version_hash = :hash LIMIT 1");
        $stmt->execute(['bid' => $betId, 'hash' => $hash]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $ins = $db->prepare("
            INSERT INTO spin_wheel_config_versions (bet_id, version_hash, outcomes_snapshot, total_probability, created_by)
            VALUES (:bid, :hash, :snap, 100.000, :aid)
        ");
        $ins->execute([
            'bid' => $betId,
            'hash' => $hash,
            'snap' => $serialized,
            'aid' => $adminId
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Builds public wheel segments representation for client-side rendering.
     * CRITICAL SECURITY: Never includes probabilities, weights, or server internal rules!
     */
    public static function getPublicWheelConfig(int $betId): array {
        self::ensureTablesExist();
        $bet = self::getBetById($betId);
        if (!$bet || empty($bet['is_enabled'])) {
            return ['error' => 'Selected bet amount is currently unavailable.'];
        }

        $val = self::validateBetProbability($betId);
        if (!$val['isValid']) {
            return ['error' => 'This wheel configuration is currently undergoing administrator maintenance.'];
        }

        $outcomes = self::getOutcomesForBet($betId, true);
        $segments = [];

        foreach ($outcomes as $idx => $o) {
            $rewardAmt = (float)$o['reward_amount'];
            $label = (string)$o['label'];

            // Ensure Jackpot label and amount appear together inside segment (e.g. "₹30 JACKPOT")
            if ($o['reward_type'] === 'jackpot') {
                if (!str_contains(strtoupper($label), 'JACKPOT')) {
                    $label = format_currency($rewardAmt) . ' JACKPOT';
                }
            }

            $segments[] = [
                'index'        => $idx,
                'reward_type'  => $o['reward_type'],
                'label'        => $label,
                'reward_amount'=> $rewardAmt,
                'formatted_reward' => ($rewardAmt > 0) ? format_currency($rewardAmt) : '',
                'color_bg'     => $o['color_bg'] ?: '#3b82f6',
                'color_text'   => $o['color_text'] ?: '#ffffff',
                'icon_key'     => $o['icon_key'] ?: 'trophy'
            ];
        }

        return [
            'bet_id'           => (int)$bet['id'],
            'bet_amount'       => (float)$bet['bet_amount'],
            'formatted_bet'    => format_currency($bet['bet_amount']),
            'total_segments'   => count($segments),
            'segments'         => $segments
        ];
    }

    /**
     * Executes an Atomic Spin Transaction
     *
     * 1. Validates auth & eligibility
     * 2. Checks wallet balance with row-locking
     * 3. Validates 100% active configuration
     * 4. Deducts bet atomically
     * 5. Selects outcome via CSPRNG random_int against configured probability thresholds
     * 6. Credits reward (if any)
     * 7. Records transactions and spin ledger entry
     * 8. Returns authoritative result
     */
    public static function executeSpin(int $userId, int $betId): array {
        self::ensureTablesExist();

        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'The Spin Wheel is currently disabled by administrator.'];
        }

        $db = Database::getConnection();

        // Check recent cooldown to prevent accidental rapid double-clicks
        $cooldownSec = (int)get_setting('spin_wheel_cooldown_seconds', '2');
        if ($cooldownSec > 0) {
            $coolStmt = $db->prepare("
                SELECT created_at FROM spin_spins
                WHERE user_id = :uid
                ORDER BY id DESC LIMIT 1
            ");
            $coolStmt->execute(['uid' => $userId]);
            $lastSpin = $coolStmt->fetchColumn();
            if ($lastSpin) {
                $lastTime = strtotime($lastSpin);
                if (time() - $lastTime < $cooldownSec) {
                    return ['success' => false, 'error' => 'Please wait a moment before spinning again.'];
                }
            }
        }

        // Validate bet existence
        $bet = self::getBetById($betId);
        if (!$bet || empty($bet['is_enabled'])) {
            return ['success' => false, 'error' => 'Selected bet amount is invalid or disabled.'];
        }
        $betAmount = (float)$bet['bet_amount'];

        // Validate 100.000% configuration integrity
        $val = self::validateBetProbability($betId);
        if (!$val['isValid']) {
            return ['success' => false, 'error' => 'Wheel configuration is currently being updated. Please try again in a moment.'];
        }

        $outcomes = self::getOutcomesForBet($betId, true);
        if (empty($outcomes)) {
            return ['success' => false, 'error' => 'No active outcomes available.'];
        }

        // Max reward safety limit
        $maxRewardLimit = (float)get_setting('spin_wheel_max_reward_limit', '1000.0000');

        // BEGIN ATOMIC TRANSACTION
        $db->beginTransaction();

        try {
            // Lock user row for update to prevent concurrent race condition or balance exploits
            $userStmt = $db->prepare("SELECT id, balance, status FROM users WHERE id = :id FOR UPDATE");
            $userStmt->execute(['id' => $userId]);
            $userRow = $userStmt->fetch();

            if (!$userRow || $userRow['status'] !== 'active') {
                $db->rollBack();
                return ['success' => false, 'error' => 'Account is not eligible to play.'];
            }

            $currentBalance = (float)$userRow['balance'];
            if ($currentBalance < $betAmount) {
                $db->rollBack();
                return [
                    'success' => false,
                    'error' => 'Insufficient wallet balance. Please add funds to spin!',
                    'insufficient_balance' => true,
                    'current_balance' => $currentBalance,
                    'required_amount' => $betAmount
                ];
            }

            // Deduct bet amount from user's wallet
            $deductStmt = $db->prepare("
                UPDATE users
                SET balance = balance - :amt, spent = spent + :amt
                WHERE id = :id AND balance >= :amt
            ");
            $deductStmt->execute(['amt' => $betAmount, 'id' => $userId]);
            if ($deductStmt->rowCount() === 0) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Failed to deduct bet amount. Please retry.'];
            }

            // Generate unique transaction UUID
            $spinUuid = 'spn_' . bin2hex(random_bytes(10));

            // Record Debit Transaction
            $txnBetStmt = $db->prepare("
                INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                VALUES (:uid, 'spin_bet', :amt, 'system', :txnid, 'completed', :note)
            ");
            $txnBetStmt->execute([
                'uid' => $userId,
                'amt' => -$betAmount,
                'txnid' => $spinUuid . '_bet',
                'note' => 'Spin Wheel Bet: ' . format_currency($betAmount)
            ]);
            $betTxnId = (int)$db->lastInsertId();

            // Secure CSPRNG outcome selection
            // Map total probability (100,000 units) into cumulative ranges
            $randomNumber = random_int(1, self::TOTAL_PROBABILITY_UNITS); // 1 .. 100,000
            $cumulativeUnits = 0;
            $selectedOutcome = null;
            $segmentIndex = 0;

            foreach ($outcomes as $idx => $outcome) {
                $probUnits = (int)round(((float)$outcome['probability']) * self::PROBABILITY_MULTIPLIER);
                $cumulativeUnits += $probUnits;

                if ($randomNumber <= $cumulativeUnits) {
                    $selectedOutcome = $outcome;
                    $segmentIndex = $idx;
                    break;
                }
            }

            // Fallback safety to last outcome
            if (!$selectedOutcome) {
                $selectedOutcome = end($outcomes);
                $segmentIndex = count($outcomes) - 1;
            }

            $rewardType = $selectedOutcome['reward_type'];
            $rewardAmount = (float)$selectedOutcome['reward_amount'];
            $outcomeLabel = (string)$selectedOutcome['label'];

            // Cap at safety limit if configured
            if ($maxRewardLimit > 0 && $rewardAmount > $maxRewardLimit) {
                $rewardAmount = $maxRewardLimit;
            }

            $rewardTxnId = null;

            // Process Credit if outcome has a reward or money back
            if ($rewardAmount > 0 && in_array($rewardType, ['jackpot', 'reward', 'money_back'], true)) {
                $creditStmt = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :id");
                $creditStmt->execute(['amt' => $rewardAmount, 'id' => $userId]);

                $rewardNote = match($rewardType) {
                    'jackpot'    => 'Spin Wheel JACKPOT Win: ' . format_currency($rewardAmount),
                    'money_back' => 'Spin Wheel Money Back: ' . format_currency($rewardAmount),
                    default      => 'Spin Wheel Reward: ' . format_currency($rewardAmount)
                };

                $txnWinStmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'spin_win', :amt, 'system', :txnid, 'completed', :note)
                ");
                $txnWinStmt->execute([
                    'uid' => $userId,
                    'amt' => $rewardAmount,
                    'txnid' => $spinUuid . '_win',
                    'note' => $rewardNote
                ]);
                $rewardTxnId = (int)$db->lastInsertId();
            }

            // Get or create config version snapshot
            $cfgVersionId = self::getOrCreateConfigVersion($betId);

            // Calculate precise segment angle for wheel pointer
            // Segment center angle relative to top pointer (12 o'clock)
            $totalSegments = count($outcomes);
            $segmentArc = 360 / $totalSegments;
            // Midpoint of target segment:
            $segmentCenterAngle = ($segmentIndex * $segmentArc) + ($segmentArc / 2);
            // Target final angle to align segment center with top pointer:
            // Since pointer is at top (0 deg / 360 deg), rotating the wheel clockwise means
            // the angle at the pointer is (360 - (wheel_rotation % 360))
            $targetAngle = round((360 - $segmentCenterAngle) % 360, 2);

            // Record spin ledger entry
            $spinStmt = $db->prepare("
                INSERT INTO spin_spins (
                    uuid, user_id, bet_id, bet_amount, outcome_id, reward_type,
                    outcome_label, reward_amount, segment_index, final_angle,
                    config_version_id, bet_transaction_id, reward_transaction_id, status
                ) VALUES (
                    :uuid, :uid, :bid, :bamt, :oid, :rtype,
                    :olabel, :ramt, :sidx, :fangle,
                    :cvid, :btxn, :rtxn, 'completed'
                )
            ");
            $spinStmt->execute([
                'uuid' => $spinUuid,
                'uid' => $userId,
                'bid' => $betId,
                'bamt' => $betAmount,
                'oid' => (int)$selectedOutcome['id'],
                'rtype' => $rewardType,
                'olabel' => $outcomeLabel,
                'ramt' => $rewardAmount,
                'sidx' => $segmentIndex,
                'fangle' => $targetAngle,
                'cvid' => $cfgVersionId,
                'btxn' => $betTxnId,
                'rtxn' => $rewardTxnId
            ]);
            $spinId = (int)$db->lastInsertId();

            // Fetch verified updated balance
            $balStmt = $db->prepare("SELECT balance FROM users WHERE id = :id");
            $balStmt->execute(['id' => $userId]);
            $newBalance = (float)$balStmt->fetchColumn();

            // Commit atomic transaction
            $db->commit();

            return [
                'success'           => true,
                'spin_id'           => $spinId,
                'uuid'              => $spinUuid,
                'segment_index'     => $segmentIndex,
                'target_angle'      => $targetAngle,
                'reward_type'       => $rewardType,
                'outcome_label'     => $outcomeLabel,
                'reward_amount'     => $rewardAmount,
                'formatted_reward'  => format_currency($rewardAmount),
                'new_balance'       => $newBalance,
                'formatted_balance' => format_currency($newBalance)
            ];

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SpinWheelEngine::executeSpin exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'An unexpected error occurred while processing your spin. No funds were debited.'
            ];
        }
    }

    /**
     * Gets real, verified recent winners from database
     * Excludes losing spins. Masks username for user privacy.
     */
    public static function getRecentWinners(int $limit = 12): array {
        self::ensureTablesExist();
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT s.id, s.reward_amount, s.reward_type, s.outcome_label, s.created_at, u.username
            FROM spin_spins s
            JOIN users u ON s.user_id = u.id
            WHERE s.reward_amount > 0
              AND s.reward_type IN ('jackpot', 'reward', 'money_back')
              AND s.status = 'completed'
            ORDER BY s.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $winners = [];
        foreach ($rows as $r) {
            $rawUser = (string)$r['username'];
            $masked = self::maskUsername($rawUser);
            $winners[] = [
                'id'            => (int)$r['id'],
                'masked_user'   => $masked,
                'reward_amount' => (float)$r['reward_amount'],
                'formatted_win' => format_currency($r['reward_amount']),
                'reward_type'   => $r['reward_type'],
                'outcome_label' => $r['outcome_label'],
                'relative_time' => self::formatRelativeTime($r['created_at'])
            ];
        }

        return $winners;
    }

    /**
     * Privacy-conscious username masking (e.g. "rahulxx" or "ra***")
     */
    public static function maskUsername(string $username): string {
        $len = strlen($username);
        if ($len <= 2) {
            return $username . '***';
        }
        if ($len <= 4) {
            return substr($username, 0, 2) . '***';
        }
        return substr($username, 0, 3) . '***' . substr($username, -1);
    }

    /**
     * Relative human-readable timestamp formatter
     */
    public static function formatRelativeTime(string $datetime): string {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 45) return 'Just now';
        if ($diff < 90) return '1 min ago';
        if ($diff < 3600) return floor($diff / 60) . ' mins ago';
        if ($diff < 7200) return '1 hour ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        return date('M j', $timestamp);
    }

    /**
     * Records administrative audit log
     */
    public static function logAdminAction(?int $adminId, string $action, array|string $details): void {
        try {
            self::ensureTablesExist();
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO spin_wheel_audit_logs (admin_id, action, details, ip_address)
                VALUES (:aid, :act, :det, :ip)
            ");
            $stmt->execute([
                'aid' => $adminId,
                'act' => $action,
                'det' => is_array($details) ? json_encode($details) : (string)$details,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (\Throwable $e) {
            error_log("SpinWheelEngine::logAdminAction error: " . $e->getMessage());
        }
    }
}
