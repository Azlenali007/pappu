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
     * Ensures all database tables, columns, indexes, settings, and enum values exist.
     * Checks each table independently rather than assuming all exist if one does.
     */
    public static function ensureTablesExist(): void {
        static $checked = false;
        if ($checked) return;

        try {
            $db = Database::getConnection();

            // 1. Table: spin_wheel_bets
            $db->exec("
                CREATE TABLE IF NOT EXISTS `spin_wheel_bets` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `bet_amount` DECIMAL(12, 4) NOT NULL,
                    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `display_order` INT NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_spin_bet_amount` (`bet_amount`),
                    INDEX `idx_spin_bet_status` (`is_enabled`, `display_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 2. Table: spin_wheel_outcomes
            $db->exec("
                CREATE TABLE IF NOT EXISTS `spin_wheel_outcomes` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `bet_id` INT UNSIGNED NOT NULL,
                    `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL DEFAULT 'reward',
                    `label` VARCHAR(80) NOT NULL,
                    `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
                    `probability` DECIMAL(6, 3) NOT NULL DEFAULT 0.000,
                    `icon_key` VARCHAR(40) NOT NULL DEFAULT 'trophy',
                    `color_bg` VARCHAR(30) NOT NULL DEFAULT '#3b82f6',
                    `color_text` VARCHAR(30) NOT NULL DEFAULT '#ffffff',
                    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                    `display_order` INT NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_outcome_bet_order` (`bet_id`, `display_order`),
                    INDEX `idx_outcome_enabled` (`is_enabled`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 3. Table: spin_wheel_config_versions
            $db->exec("
                CREATE TABLE IF NOT EXISTS `spin_wheel_config_versions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `bet_id` INT UNSIGNED NOT NULL,
                    `version_hash` VARCHAR(64) NOT NULL,
                    `outcomes_snapshot` JSON NOT NULL,
                    `total_probability` DECIMAL(6, 3) NOT NULL DEFAULT 100.000,
                    `created_by` INT UNSIGNED NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_spin_cfg_bet` (`bet_id`),
                    INDEX `idx_spin_cfg_hash` (`version_hash`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 4. Table: spin_spins
            $db->exec("
                CREATE TABLE IF NOT EXISTS `spin_spins` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `uuid` VARCHAR(64) NOT NULL UNIQUE,
                    `user_id` INT UNSIGNED NOT NULL,
                    `bet_id` INT UNSIGNED NOT NULL,
                    `bet_amount` DECIMAL(12, 4) NOT NULL,
                    `outcome_id` INT UNSIGNED NULL,
                    `reward_type` ENUM('jackpot', 'reward', 'money_back', 'no_reward') NOT NULL,
                    `outcome_label` VARCHAR(80) NOT NULL,
                    `reward_amount` DECIMAL(12, 4) NOT NULL DEFAULT 0.0000,
                    `segment_index` INT UNSIGNED NOT NULL DEFAULT 0,
                    `final_angle` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
                    `config_version_id` INT UNSIGNED NULL,
                    `bet_transaction_id` INT UNSIGNED NULL,
                    `reward_transaction_id` INT UNSIGNED NULL,
                    `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'completed',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_spins_user_created` (`user_id`, `created_at`),
                    INDEX `idx_spins_reward_status` (`reward_type`, `status`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 5. Table: spin_wheel_audit_logs
            $db->exec("
                CREATE TABLE IF NOT EXISTS `spin_wheel_audit_logs` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `admin_id` INT UNSIGNED NULL,
                    `action` VARCHAR(60) NOT NULL,
                    `details` TEXT NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_spin_audit_admin` (`admin_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 6. Ensure `transactions.type` column supports 'spin_bet' and 'spin_win'
            try {
                $checkTypeStmt = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'type'");
                $typeCol = $checkTypeStmt->fetch();
                if ($typeCol && !empty($typeCol['Type'])) {
                    if (stripos($typeCol['Type'], "'spin_bet'") === false || stripos($typeCol['Type'], "'spin_win'") === false) {
                        $db->exec("
                            ALTER TABLE `transactions`
                            MODIFY COLUMN `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment', 'spin_bet', 'spin_win') NOT NULL
                        ");
                    }
                }
            } catch (\Throwable $te) {
                error_log("SpinWheelEngine: Note on transactions.type column inspection: " . $te->getMessage());
            }

            // 7. Ensure `spin_spins` column integrity (add missing columns if table pre-existed)
            try {
                $colsStmt = $db->query("SHOW COLUMNS FROM `spin_spins`");
                $existingCols = array_column($colsStmt->fetchAll() ?: [], 'Field');
                if (!in_array('config_version_id', $existingCols, true)) {
                    $db->exec("ALTER TABLE `spin_spins` ADD COLUMN `config_version_id` INT UNSIGNED NULL AFTER `final_angle`");
                }
                if (!in_array('bet_transaction_id', $existingCols, true)) {
                    $db->exec("ALTER TABLE `spin_spins` ADD COLUMN `bet_transaction_id` INT UNSIGNED NULL AFTER `config_version_id`");
                }
                if (!in_array('reward_transaction_id', $existingCols, true)) {
                    $db->exec("ALTER TABLE `spin_spins` ADD COLUMN `reward_transaction_id` INT UNSIGNED NULL AFTER `bet_transaction_id`");
                }
            } catch (\Throwable $ce) {
                error_log("SpinWheelEngine: Note on spin_spins column check: " . $ce->getMessage());
            }

            // 8. Seed Default Settings if missing
            try {
                $db->exec("
                    INSERT INTO `settings` (`key`, `value`)
                    VALUES 
                      ('spin_wheel_enabled', '1'),
                      ('spin_wheel_max_reward_limit', '1000.0000'),
                      ('spin_wheel_cooldown_seconds', '2')
                    ON DUPLICATE KEY UPDATE `key` = `key`
                ");
            } catch (\Throwable $se) {
                error_log("SpinWheelEngine: Note on settings seeding: " . $se->getMessage());
            }

            // 9. Seed Default Bets if empty
            $betCountStmt = $db->query("SELECT COUNT(*) FROM `spin_wheel_bets`");
            $betCount = (int)$betCountStmt->fetchColumn();
            if ($betCount === 0) {
                $db->exec("
                    INSERT INTO `spin_wheel_bets` (`id`, `bet_amount`, `is_enabled`, `display_order`)
                    VALUES 
                      (1, 1.0000, 1, 1),
                      (2, 5.0000, 1, 2)
                    ON DUPLICATE KEY UPDATE `is_enabled` = 1
                ");
            }

            // 10. Seed Default Outcomes if empty for active bets
            self::seedDefaultOutcomesIfEmpty($db);

        } catch (\Throwable $e) {
            error_log("SpinWheelEngine::ensureTablesExist error: " . $e->getMessage());
        }
        $checked = true;
    }

    /**
     * Seeds default 8 balanced outcomes for bet 1 and bet 2 totaling exactly 100.000%
     */
    private static function seedDefaultOutcomesIfEmpty(PDO $db): void {
        try {
            // Check outcomes for Bet 1 (₹1)
            $stmt1 = $db->prepare("SELECT COUNT(*) FROM `spin_wheel_outcomes` WHERE `bet_id` = 1");
            $stmt1->execute();
            if ((int)$stmt1->fetchColumn() === 0) {
                $db->exec("
                    INSERT INTO `spin_wheel_outcomes` (`id`, `bet_id`, `reward_type`, `label`, `reward_amount`, `probability`, `icon_key`, `color_bg`, `color_text`, `is_enabled`, `display_order`)
                    VALUES
                      (1, 1, 'jackpot', '₹30 JACKPOT', 30.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
                      (2, 1, 'no_reward', 'TRY AGAIN', 0.0000, 35.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
                      (3, 1, 'reward', '₹2 Reward', 2.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
                      (4, 1, 'money_back', 'MONEY BACK', 1.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
                      (5, 1, 'reward', '₹5 Reward', 5.0000, 4.500, 'gift', '#d97706', '#ffffff', 1, 4),
                      (6, 1, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
                      (7, 1, 'reward', '₹10 Reward', 10.0000, 2.000, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
                      (8, 1, 'money_back', '₹1 Return', 1.0000, 3.000, 'refresh', '#0d9488', '#ffffff', 1, 7)
                    ON DUPLICATE KEY UPDATE `is_enabled` = 1
                ");
            }

            // Check outcomes for Bet 2 (₹5)
            $stmt2 = $db->prepare("SELECT COUNT(*) FROM `spin_wheel_outcomes` WHERE `bet_id` = 2");
            $stmt2->execute();
            if ((int)$stmt2->fetchColumn() === 0) {
                $db->exec("
                    INSERT INTO `spin_wheel_outcomes` (`id`, `bet_id`, `reward_type`, `label`, `reward_amount`, `probability`, `icon_key`, `color_bg`, `color_text`, `is_enabled`, `display_order`)
                    VALUES
                      (9, 2, 'jackpot', '₹80 JACKPOT', 80.0000, 0.500, 'trophy', '#7c3aed', '#ffffff', 1, 0),
                      (10, 2, 'no_reward', 'TRY AGAIN', 0.0000, 30.000, 'cross', '#1e293b', '#94a3b8', 1, 1),
                      (11, 2, 'reward', '₹10 Reward', 10.0000, 15.000, 'coin', '#2563eb', '#ffffff', 1, 2),
                      (12, 2, 'money_back', 'MONEY BACK', 5.0000, 25.000, 'refresh', '#059669', '#ffffff', 1, 3),
                      (13, 2, 'reward', '₹20 Reward', 20.0000, 5.000, 'gift', '#d97706', '#ffffff', 1, 4),
                      (14, 2, 'no_reward', 'BETTER LUCK', 0.0000, 15.000, 'cross', '#334155', '#94a3b8', 1, 5),
                      (15, 2, 'reward', '₹50 Reward', 50.0000, 1.500, 'sparkles', '#4f46e5', '#ffffff', 1, 6),
                      (16, 2, 'money_back', '₹5 Return', 5.0000, 8.000, 'refresh', '#0d9488', '#ffffff', 1, 7)
                    ON DUPLICATE KEY UPDATE `is_enabled` = 1
                ");
            }
        } catch (\Throwable $e) {
            error_log("SpinWheelEngine::seedDefaultOutcomesIfEmpty error: " . $e->getMessage());
        }
    }

    /**
     * Inspects transactions.type column enum and returns a supported type.
     * If preferred type is not supported and cannot be altered, returns fallback type.
     */
    public static function getSafeTransactionType(PDO $db, string $preferredType, string $fallbackType = 'order'): string {
        static $supportedTypes = null;

        if ($supportedTypes === null) {
            $supportedTypes = [];
            try {
                $stmt = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'type'");
                $row = $stmt->fetch();
                if ($row && !empty($row['Type'])) {
                    if (preg_match_all("/'([^']+)'/", $row['Type'], $m)) {
                        $supportedTypes = $m[1];
                    }
                }
            } catch (\Throwable $e) {
                error_log("SpinWheelEngine::getSafeTransactionType check error: " . $e->getMessage());
            }
        }

        if (in_array($preferredType, $supportedTypes, true)) {
            return $preferredType;
        }

        // Try adding preferred type to transactions table ENUM
        try {
            $db->exec("
                ALTER TABLE `transactions`
                MODIFY COLUMN `type` ENUM('deposit', 'order', 'refund', 'bonus', 'admin_adjustment', 'spin_bet', 'spin_win') NOT NULL
            ");
            $supportedTypes[] = 'spin_bet';
            $supportedTypes[] = 'spin_win';
            if (in_array($preferredType, $supportedTypes, true)) {
                return $preferredType;
            }
        } catch (\Throwable $ae) {
            error_log("SpinWheelEngine::getSafeTransactionType alter notice: " . $ae->getMessage());
        }

        return $fallbackType;
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
        try {
            self::ensureTablesExist();
            $db = Database::getConnection();
            $outcomes = self::getOutcomesForBet($betId, true);
            if (empty($outcomes)) return null;

            $serialized = json_encode($outcomes);
            $hash = hash('sha256', (string)$serialized);

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
        } catch (\Throwable $e) {
            error_log("SpinWheelEngine::getOrCreateConfigVersion non-fatal notice: " . $e->getMessage());
            return null;
        }
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
     * Pre-renders crisp SVG segments markup for instantaneous zero-flicker wheel display
     */
    public static function renderWheelSegmentsSvg(array $config): string {
        $segments = $config['segments'] ?? [];
        $count = count($segments);
        if ($count === 0) return '';

        $cx = 200;
        $cy = 200;
        $r = 196;
        $arcAngle = (2 * M_PI) / $count;
        $arcDeg = 360 / $count;

        $iconPaths = [
            'trophy'   => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
            'coin'     => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'gift'     => 'M12 8v13m0-13V4a2 2 0 10-4 0v4m4-4a2 2 0 114 0v4M4 8h16v4H4V8zm2 4v8a2 2 0 002 2h8a2 2 0 002-2v-8',
            'refresh'  => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
            'sparkles' => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
            'cross'    => 'M6 18L18 6M6 6l12 12'
        ];

        $html = '';
        foreach ($segments as $i => $seg) {
            $startAngle = $i * $arcAngle;
            $endAngle = ($i + 1) * $arcAngle;

            $x1 = round($cx + $r * sin($startAngle), 2);
            $y1 = round($cy - $r * cos($startAngle), 2);
            $x2 = round($cx + $r * sin($endAngle), 2);
            $y2 = round($cy - $r * cos($endAngle), 2);
            $largeArc = ($endAngle - $startAngle > M_PI) ? 1 : 0;

            $pathD = "M {$cx} {$cy} L {$x1} {$y1} A {$r} {$r} 0 {$largeArc} 1 {$x2} {$y2} Z";
            $bg = htmlspecialchars($seg['color_bg'] ?? '#3b82f6', ENT_QUOTES, 'UTF-8');
            $tx = htmlspecialchars($seg['color_text'] ?? '#ffffff', ENT_QUOTES, 'UTF-8');
            $iconKey = $seg['icon_key'] ?? 'trophy';
            $iconD = $iconPaths[$iconKey] ?? $iconPaths['trophy'];
            $rType = $seg['reward_type'] ?? 'reward';
            $amt = (float)($seg['reward_amount'] ?? 0);
            $midAngleDeg = round(($i * $arcDeg) + ($arcDeg / 2), 2);

            $html .= "<g class=\"wheel-segment\" data-index=\"{$i}\">";
            $html .= "<path d=\"{$pathD}\" fill=\"{$bg}\" stroke=\"#ffffff\" stroke-width=\"1.75\" stroke-linejoin=\"round\"/>";
            $html .= "<g transform=\"rotate({$midAngleDeg}, {$cx}, {$cy})\">";

            // Icon at top perimeter
            $html .= "<g transform=\"translate(" . ($cx - 8) . ", 26) scale(0.68)\" fill=\"none\" stroke=\"{$tx}\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\">";
            $html .= "<path d=\"{$iconD}\"/>";
            $html .= "</g>";

            // Text Labels inside segment
            if ($rType === 'jackpot') {
                $amtLabel = htmlspecialchars(format_currency($amt), ENT_QUOTES, 'UTF-8');
                $html .= "<text x=\"{$cx}\" y=\"54\" fill=\"#fef08a\" font-size=\"14\" font-weight=\"900\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"0.3px\">{$amtLabel}</text>";
                $html .= "<text x=\"{$cx}\" y=\"70\" fill=\"#fef08a\" font-size=\"9.5\" font-weight=\"900\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"1.2px\">JACKPOT</text>";
            } elseif ($rType === 'reward') {
                $amtLabel = htmlspecialchars(format_currency($amt), ENT_QUOTES, 'UTF-8');
                $html .= "<text x=\"{$cx}\" y=\"54\" fill=\"{$tx}\" font-size=\"13.5\" font-weight=\"900\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"0.2px\">{$amtLabel}</text>";
                $html .= "<text x=\"{$cx}\" y=\"69\" fill=\"{$tx}\" fill-opacity=\"0.9\" font-size=\"9\" font-weight=\"800\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"0.6px\">REWARD</text>";
            } elseif ($rType === 'money_back') {
                $amtLabel = htmlspecialchars(format_currency($amt), ENT_QUOTES, 'UTF-8');
                $html .= "<text x=\"{$cx}\" y=\"53\" fill=\"{$tx}\" font-size=\"12.5\" font-weight=\"900\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\">{$amtLabel}</text>";
                $html .= "<text x=\"{$cx}\" y=\"68\" fill=\"{$tx}\" fill-opacity=\"0.95\" font-size=\"8\" font-weight=\"800\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"0.5px\">MONEY BACK</text>";
            } else {
                $label = htmlspecialchars($seg['label'] ?? 'TRY AGAIN', ENT_QUOTES, 'UTF-8');
                $html .= "<text x=\"{$cx}\" y=\"58\" fill=\"{$tx}\" fill-opacity=\"0.9\" font-size=\"9.5\" font-weight=\"800\" text-anchor=\"middle\" dominant-baseline=\"central\" font-family=\"'Plus Jakarta Sans', sans-serif\" letter-spacing=\"0.5px\">{$label}</text>";
            }

            $html .= "</g></g>";
        }
        return $html;
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
    public static function executeSpin(int $userId, int $betId, ?string $requestUuid = null): array {
        self::ensureTablesExist();

        if (!self::isEnabled()) {
            return ['success' => false, 'error' => 'The Spin Wheel is currently disabled by administrator.'];
        }

        $db = Database::getConnection();

        // Idempotency Protection: If this request UUID was already processed for this user, return existing record
        if ($requestUuid !== null && trim($requestUuid) !== '') {
            $safeUuid = substr(trim($requestUuid), 0, 64);
            try {
                $checkStmt = $db->prepare("SELECT * FROM spin_spins WHERE uuid = :uuid AND user_id = :uid LIMIT 1");
                $checkStmt->execute(['uuid' => $safeUuid, 'uid' => $userId]);
                $existing = $checkStmt->fetch();
                if ($existing) {
                    $balStmt = $db->prepare("SELECT balance FROM users WHERE id = :id");
                    $balStmt->execute(['id' => $userId]);
                    $userBal = (float)$balStmt->fetchColumn();

                    return [
                        'success'           => true,
                        'spin_id'           => (int)$existing['id'],
                        'uuid'              => $existing['uuid'],
                        'segment_index'     => (int)$existing['segment_index'],
                        'target_angle'      => (float)$existing['final_angle'],
                        'reward_type'       => $existing['reward_type'],
                        'outcome_label'     => $existing['outcome_label'],
                        'reward_amount'     => (float)$existing['reward_amount'],
                        'formatted_reward'  => format_currency((float)$existing['reward_amount']),
                        'bet_amount'        => (float)$existing['bet_amount'],
                        'formatted_bet'     => format_currency((float)$existing['bet_amount']),
                        'deducted_balance'  => $userBal,
                        'formatted_deducted_balance' => format_currency($userBal),
                        'new_balance'       => $userBal,
                        'formatted_balance' => format_currency($userBal),
                        'is_retried'        => true
                    ];
                }
            } catch (\Throwable $ie) {
                error_log("SpinWheelEngine idempotency check notice: " . $ie->getMessage());
            }
        }

        // Check recent cooldown to prevent accidental rapid double-clicks
        $cooldownSec = (int)get_setting('spin_wheel_cooldown_seconds', '2');
        if ($cooldownSec > 0) {
            try {
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
            } catch (\Throwable $ce) {
                error_log("SpinWheelEngine cooldown check notice: " . $ce->getMessage());
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

            // Deduct bet amount from user's wallet (supports tables with or without 'spent' column)
            try {
                $deductStmt = $db->prepare("
                    UPDATE users
                    SET balance = balance - :amt, spent = spent + :amt
                    WHERE id = :id AND balance >= :amt
                ");
                $deductStmt->execute(['amt' => $betAmount, 'id' => $userId]);
            } catch (\Throwable $de) {
                $deductStmt = $db->prepare("
                    UPDATE users
                    SET balance = balance - :amt
                    WHERE id = :id AND balance >= :amt
                ");
                $deductStmt->execute(['amt' => $betAmount, 'id' => $userId]);
            }

            if ($deductStmt->rowCount() === 0) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Failed to deduct bet amount. Please retry.'];
            }

            // Generate unique transaction UUID
            $spinUuid = ($requestUuid !== null && trim($requestUuid) !== '') 
                ? substr(trim($requestUuid), 0, 64) 
                : ('spn_' . bin2hex(random_bytes(10)));

            // Record Debit Transaction safely
            $betTxnType = self::getSafeTransactionType($db, 'spin_bet', 'order');
            $betTxnId = null;
            try {
                $txnBetStmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, :type, :amt, 'system', :txnid, 'completed', :note)
                ");
                $txnBetStmt->execute([
                    'uid' => $userId,
                    'type' => $betTxnType,
                    'amt' => -$betAmount,
                    'txnid' => substr($spinUuid, 0, 100) . '_bet',
                    'note' => 'Spin Wheel Bet: ' . format_currency($betAmount)
                ]);
                $betTxnId = (int)$db->lastInsertId();
            } catch (\Throwable $te) {
                error_log("SpinWheelEngine: Bet transaction insertion notice: " . $te->getMessage());
                try {
                    $txnBetStmt = $db->prepare("
                        INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, 'order', :amt, 'system', :txnid, 'completed', :note)
                    ");
                    $txnBetStmt->execute([
                        'uid' => $userId,
                        'amt' => -$betAmount,
                        'txnid' => substr($spinUuid, 0, 100) . '_b',
                        'note' => 'Spin Wheel Bet: ' . format_currency($betAmount)
                    ]);
                    $betTxnId = (int)$db->lastInsertId();
                } catch (\Throwable $te2) {
                    error_log("SpinWheelEngine: Fallback bet transaction insertion failed: " . $te2->getMessage());
                }
            }

            // Secure CSPRNG outcome selection
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

            $rewardType = (string)$selectedOutcome['reward_type'];
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

                $winTxnType = self::getSafeTransactionType($db, 'spin_win', 'bonus');
                try {
                    $txnWinStmt = $db->prepare("
                        INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, :type, :amt, 'system', :txnid, 'completed', :note)
                    ");
                    $txnWinStmt->execute([
                        'uid' => $userId,
                        'type' => $winTxnType,
                        'amt' => $rewardAmount,
                        'txnid' => substr($spinUuid, 0, 100) . '_win',
                        'note' => $rewardNote
                    ]);
                    $rewardTxnId = (int)$db->lastInsertId();
                } catch (\Throwable $we) {
                    error_log("SpinWheelEngine: Win transaction insertion notice: " . $we->getMessage());
                    try {
                        $txnWinStmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                            VALUES (:uid, 'bonus', :amt, 'system', :txnid, 'completed', :note)
                        ");
                        $txnWinStmt->execute([
                            'uid' => $userId,
                            'amt' => $rewardAmount,
                            'txnid' => substr($spinUuid, 0, 100) . '_w',
                            'note' => $rewardNote
                        ]);
                        $rewardTxnId = (int)$db->lastInsertId();
                    } catch (\Throwable $we2) {
                        error_log("SpinWheelEngine: Fallback win transaction insertion failed: " . $we2->getMessage());
                    }
                }
            }

            // Get or create config version snapshot safely
            $cfgVersionId = self::getOrCreateConfigVersion($betId);

            // Calculate precise segment angle for wheel pointer
            $totalSegments = count($outcomes);
            $segmentArc = 360.0 / $totalSegments;
            $segmentCenterAngle = ($segmentIndex * $segmentArc) + ($segmentArc / 2.0);
            $targetAngle = round(fmod((360.0 - $segmentCenterAngle) + 360.0, 360.0), 2);

            // Sanitize reward type enum for spin_spins table
            $safeRewardType = in_array($rewardType, ['jackpot', 'reward', 'money_back', 'no_reward'], true) ? $rewardType : 'no_reward';

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
                'rtype' => $safeRewardType,
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
                'bet_amount'        => $betAmount,
                'formatted_bet'     => format_currency($betAmount),
                'deducted_balance'  => $currentBalance - $betAmount,
                'formatted_deducted_balance' => format_currency($currentBalance - $betAmount),
                'new_balance'       => $newBalance,
                'formatted_balance' => format_currency($newBalance)
            ];

        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("SpinWheelEngine::executeSpin exception [User #{$userId}, Bet #{$betId}]: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
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
