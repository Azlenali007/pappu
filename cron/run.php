<?php
/**
 * Master Central Cron Scheduler
 * Mona SMM Panel - PHP 8.3+
 *
 * Single consolidated scheduled entry point for all background routines:
 * 1. Order Status Synchronization & Safe Atomic Auto-Refunds
 * 2. Provider API Balance Synchronization
 * 3. Scheduled Drip-feed Order Dispatcher
 * 4. Automated Payment Verification & Reconciler (PhonePe Business QR / Gmail)
 * 5. System Maintenance & Expiration Housekeeping (Flash Sales, Polls, Stale Sessions)
 *
 * CLI Usage:
 *   php cron/run.php
 *   php cron/run.php --task=orders
 *   php cron/run.php --task=services
 *   php cron/run.php --task=dripfeed
 *   php cron/run.php --task=payments
 *   php cron/run.php --task=maintenance
 *   php cron/run.php --task=all --dry-run
 *
 * Web / cPanel cURL Usage:
 *   curl -s "https://yourdomain.com/cron/run.php?cron_key=YOUR_CRON_KEY"
 *   curl -s "https://yourdomain.com/cron/run.php?cron_key=YOUR_CRON_KEY&task=orders&format=json"
 */

declare(strict_types=1);

// Set unlimited execution time and keep memory limits reasonable
@set_time_limit(600);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

class MasterCron
{
    private \PDO $db;
    private bool $isCli;
    private bool $dryRun = false;
    private bool $lockAcquired = false;
    private string $lockName = 'smm_master_cron_lock';
    private float $startTime;
    private array $logs = [];
    private array $stats = [
        'orders' => [
            'checked' => 0,
            'updated' => 0,
            'completed' => 0,
            'cancelled_refunded' => 0,
            'partial_refunded' => 0,
            'errors' => 0
        ],
        'providers' => [
            'checked' => 0,
            'updated' => 0,
            'errors' => 0
        ],
        'dripfeed' => [
            'jobs_checked' => 0,
            'runs_executed' => 0,
            'completed' => 0,
            'failed' => 0
        ],
        'payments' => [
            'checked' => 0,
            'credited' => 0,
            'rejected' => 0,
            'pending' => 0,
            'errors' => 0
        ],
        'maintenance' => [
            'flash_sales_archived' => 0,
            'polls_closed' => 0
        ]
    ];

    public function __construct(\PDO $db, bool $isCli, bool $dryRun = false)
    {
        $this->db = $db;
        $this->isCli = $isCli;
        $this->dryRun = $dryRun;
        $this->startTime = microtime(true);
    }

    /**
     * Entry point to execute requested task or all tasks.
     */
    public function execute(string $task = 'all'): array
    {
        $this->log("==================================================================");
        $this->log(" Mona SMM Panel — Master Cron Scheduler");
        $this->log(" Started at: " . date('Y-m-d H:i:s T'));
        $this->log(" Mode: " . ($this->isCli ? "CLI" : "Web/cURL") . ($this->dryRun ? " [DRY-RUN]" : ""));
        $this->log(" Requested Task: " . strtoupper($task));
        $this->log("==================================================================");

        // 1. Acquire MySQL Advisory Lock to prevent concurrency collisions
        if (!$this->acquireLock()) {
            $msg = "Another master cron process is currently running (advisory lock '{$this->lockName}' active). Exiting cleanly to prevent overlap.";
            $this->log("[CONCURRENCY NOTICE] " . $msg);
            return [
                'status' => 'busy',
                'message' => $msg,
                'stats' => $this->stats,
                'logs' => $this->logs
            ];
        }

        try {
            $allowedTasks = ['all', 'orders', 'services', 'dripfeed', 'payments', 'maintenance'];
            $task = in_array(strtolower($task), $allowedTasks, true) ? strtolower($task) : 'all';

            if ($task === 'all' || $task === 'orders') {
                $this->runTaskOrders();
            }

            if ($task === 'all' || $task === 'services') {
                $this->runTaskServices();
            }

            if ($task === 'all' || $task === 'dripfeed') {
                $this->runTaskDripFeed();
            }

            if ($task === 'all' || $task === 'payments') {
                $this->runTaskPayments();
            }

            if ($task === 'all' || $task === 'maintenance') {
                $this->runTaskMaintenance();
            }

            $elapsed = round(microtime(true) - $this->startTime, 3);
            $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

            $this->log("------------------------------------------------------------------");
            $this->log(" Master Cron execution completed in {$elapsed}s (Peak RAM: {$memoryPeak}MB).");
            $this->log(" Summary: " . json_encode($this->stats));
            $this->log("==================================================================");

            // Save execution heartbeat to settings for admin dashboard
            if (!$this->dryRun) {
                $this->recordCronHeartbeat($elapsed, 'success');
            }

            return [
                'status' => 'success',
                'elapsed_seconds' => $elapsed,
                'peak_memory_mb' => $memoryPeak,
                'stats' => $this->stats,
                'logs' => $this->logs
            ];
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $this->startTime, 3);
            $this->log("[FATAL ERROR] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            if (!$this->dryRun) {
                $this->recordCronHeartbeat($elapsed, 'error', $e->getMessage());
            }
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'elapsed_seconds' => $elapsed,
                'stats' => $this->stats,
                'logs' => $this->logs
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Acquire MySQL GET_LOCK advisory lock.
     */
    private function acquireLock(): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT GET_LOCK(:lock_name, 2) AS acquired");
            $stmt->execute(['lock_name' => $this->lockName]);
            $res = $stmt->fetch();
            $this->lockAcquired = ((int)($res['acquired'] ?? 0)) === 1;
            return $this->lockAcquired;
        } catch (\Throwable $e) {
            $this->log("[LOCK WARNING] Could not check advisory lock: " . $e->getMessage() . ". Proceeding with process.");
            $this->lockAcquired = false;
            return true;
        }
    }

    /**
     * Release MySQL advisory lock.
     */
    public function releaseLock(): void
    {
        if ($this->lockAcquired) {
            try {
                $stmt = $this->db->prepare("SELECT RELEASE_LOCK(:lock_name)");
                $stmt->execute(['lock_name' => $this->lockName]);
            } catch (\Throwable $e) {
                // Ignore release errors on shutdown
            }
            $this->lockAcquired = false;
        }
    }

    /**
     * 1. Task: Synchronize Order Statuses & Handle Atomic Auto-Refunds
     */
    private function runTaskOrders(): void
    {
        $this->log("[TASK: ORDERS] Checking active provider orders...");

        try {
            $stmt = $this->db->query("
                SELECT o.id, o.provider_order_id, o.provider_id, o.user_id, o.service_id, o.charge, o.quantity, o.remains, o.start_count, o.status,
                       p.name AS provider_name, p.api_url, p.api_key
                FROM orders o
                JOIN providers p ON o.provider_id = p.id
                WHERE o.status IN ('pending', 'processing', 'in_progress')
                  AND o.provider_order_id IS NOT NULL
                  AND o.provider_order_id != ''
                  AND p.status = 'active'
                ORDER BY o.id ASC
                LIMIT 100
            ");
            $orders = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $this->log(" -> Error querying pending orders: " . $e->getMessage());
            $this->stats['orders']['errors']++;
            return;
        }

        $count = count($orders);
        $this->stats['orders']['checked'] = $count;
        $this->log(" -> Found {$count} eligible order(s) awaiting provider status check.");

        if ($count === 0) {
            return;
        }

        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            $providerOrderId = (string)$order['provider_order_id'];
            $providerName = (string)$order['provider_name'];

            $response = call_provider_api($order['api_url'], [
                'key' => $order['api_key'],
                'action' => 'status',
                'order' => $providerOrderId
            ], 12);

            if (!empty($response['error'])) {
                $err = is_string($response['error']) ? $response['error'] : 'Unknown provider error';
                $this->log(" -> Order #{$orderId} ({$providerName} #{$providerOrderId}): Provider returned error '{$err}'. Keeping current status.");
                $this->stats['orders']['errors']++;
                continue;
            }

            if (empty($response['status'])) {
                $this->log(" -> Order #{$orderId} ({$providerName} #{$providerOrderId}): No status field returned. Skipping.");
                $this->stats['orders']['errors']++;
                continue;
            }

            $rawStatus = strtolower(trim((string)$response['status']));
            $startCount = isset($response['start_count']) ? (int)$response['start_count'] : (int)$order['start_count'];
            $remains = isset($response['remains']) ? (int)$response['remains'] : (int)$order['remains'];

            $statusMap = [
                'completed'   => 'completed',
                'in progress' => 'in_progress',
                'in_progress' => 'in_progress',
                'processing'  => 'processing',
                'pending'     => 'pending',
                'partial'     => 'partial',
                'canceled'    => 'cancelled',
                'cancelled'   => 'cancelled'
            ];

            $targetStatus = $statusMap[$rawStatus] ?? null;
            if (!$targetStatus) {
                $this->log(" -> Order #{$orderId}: Unrecognized provider status '{$rawStatus}'. Retaining status '{$order['status']}'.");
                continue;
            }

            if ($this->dryRun) {
                $this->log(" -> [DRY-RUN] Order #{$orderId} would transition to '{$targetStatus}' (remains: {$remains}).");
                $this->stats['orders']['updated']++;
                continue;
            }

            // Case A: Provider cancelled order -> Automatic full refund
            if ($targetStatus === 'cancelled') {
                $refundProcessed = $this->processOrderCancellationAndRefund($order, $remains);
                if ($refundProcessed) {
                    $this->stats['orders']['cancelled_refunded']++;
                    $this->stats['orders']['updated']++;
                    $this->log(" -> Order #{$orderId}: CANCELLED by provider. Full refund of " . format_currency((float)$order['charge']) . " credited to User #{$order['user_id']}.");
                }
                continue;
            }

            // Case B: Provider marked partial -> Automatic proportional refund of unfulfilled remains
            if ($targetStatus === 'partial') {
                $partialProcessed = $this->processOrderPartialAndRefund($order, $startCount, $remains);
                if ($partialProcessed) {
                    $this->stats['orders']['partial_refunded']++;
                    $this->stats['orders']['updated']++;
                    $this->log(" -> Order #{$orderId}: PARTIAL delivery ({$remains}/{$order['quantity']} unfulfilled). Proportional refund processed.");
                }
                continue;
            }

            // Case C: Normal status transition (completed, in_progress, processing, pending)
            try {
                $upd = $this->db->prepare("
                    UPDATE orders 
                    SET status = :st, start_count = :sc, remains = :rem, updated_at = NOW() 
                    WHERE id = :id
                ");
                $upd->execute([
                    'st' => $targetStatus,
                    'sc' => $startCount,
                    'rem' => $remains,
                    'id' => $orderId
                ]);

                if ($targetStatus === 'completed') {
                    $this->stats['orders']['completed']++;
                }
                $this->stats['orders']['updated']++;
                $this->log(" -> Order #{$orderId}: Status updated to '{$targetStatus}' (Start: {$startCount}, Remains: {$remains}).");
            } catch (\Throwable $e) {
                $this->log(" -> Order #{$orderId}: Failed to update status: " . $e->getMessage());
                $this->stats['orders']['errors']++;
            }
        }
    }

    /**
     * Atomic Cancellation & Full Refund with Row-Locking & Duplicate Check.
     */
    private function processOrderCancellationAndRefund(array $order, int $remains): bool
    {
        $orderId = (int)$order['id'];
        $userId = (int)$order['user_id'];
        $charge = (float)$order['charge'];

        try {
            $this->db->beginTransaction();

            // Lock order row to prevent parallel web/admin/cron race conditions
            $lockStmt = $this->db->prepare("SELECT id, user_id, charge, status FROM orders WHERE id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $orderId]);
            $currentOrder = $lockStmt->fetch();

            if (!$currentOrder || $currentOrder['status'] === 'cancelled') {
                $this->db->rollBack();
                return false;
            }

            // Duplicate refund transaction prevention
            $dupStmt = $this->db->prepare("
                SELECT id FROM transactions 
                WHERE order_id = :oid AND user_id = :uid AND type = 'refund' 
                LIMIT 1
            ");
            $dupStmt->execute(['oid' => $orderId, 'uid' => $userId]);
            if ($dupStmt->fetch()) {
                // Order already has a refund ledger entry; just ensure order status is marked cancelled
                $this->db->prepare("UPDATE orders SET status = 'cancelled', remains = :rem WHERE id = :id")
                         ->execute(['rem' => $remains, 'id' => $orderId]);
                $this->db->commit();
                return false;
            }

            // 1. Mark order cancelled
            $this->db->prepare("UPDATE orders SET status = 'cancelled', remains = :rem, updated_at = NOW() WHERE id = :id")
                     ->execute(['rem' => $remains, 'id' => $orderId]);

            // 2. Refund balance and decrement spent ledger atomically
            $this->db->prepare("UPDATE users SET balance = balance + :amt, spent = GREATEST(0, spent - :amt) WHERE id = :uid")
                     ->execute(['amt' => $charge, 'uid' => $userId]);

            // 3. Record official refund transaction in ledger
            $insTxn = $this->db->prepare("
                INSERT INTO transactions (user_id, order_id, type, amount, gateway, status, note, created_at)
                VALUES (:uid, :oid, 'refund', :amt, 'system', 'completed', 'Provider cancelled order refund', NOW())
            ");
            $insTxn->execute([
                'uid' => $userId,
                'oid' => $orderId,
                'amt' => $charge
            ]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->log(" -> Error processing refund for Order #{$orderId}: " . $e->getMessage());
            $this->stats['orders']['errors']++;
            return false;
        }
    }

    /**
     * Atomic Partial Order Handling with Proportional Auto-Refund.
     */
    private function processOrderPartialAndRefund(array $order, int $startCount, int $remains): bool
    {
        $orderId = (int)$order['id'];
        $userId = (int)$order['user_id'];
        $totalQty = (int)$order['quantity'];
        $totalCharge = (float)$order['charge'];

        try {
            $this->db->beginTransaction();

            $lockStmt = $this->db->prepare("SELECT id, user_id, charge, quantity, status FROM orders WHERE id = :id FOR UPDATE");
            $lockStmt->execute(['id' => $orderId]);
            $currentOrder = $lockStmt->fetch();

            if (!$currentOrder || $currentOrder['status'] === 'cancelled') {
                $this->db->rollBack();
                return false;
            }

            // Check if already partial and refunded
            $dupStmt = $this->db->prepare("
                SELECT id FROM transactions 
                WHERE order_id = :oid AND user_id = :uid AND type = 'refund' 
                LIMIT 1
            ");
            $dupStmt->execute(['oid' => $orderId, 'uid' => $userId]);
            $alreadyRefunded = (bool)$dupStmt->fetch();

            // Calculate refund for remaining unfulfilled quantity
            $refundAmount = 0.0000;
            if (!$alreadyRefunded && $remains > 0 && $totalQty > 0 && $totalCharge > 0) {
                $ratio = min(1.0, max(0.0, (float)$remains / (float)$totalQty));
                $refundAmount = round($ratio * $totalCharge, 4);
            }

            // 1. Update order status to partial
            $this->db->prepare("
                UPDATE orders 
                SET status = 'partial', start_count = :sc, remains = :rem, updated_at = NOW() 
                WHERE id = :id
            ")->execute([
                'sc' => $startCount,
                'rem' => $remains,
                'id' => $orderId
            ]);

            // 2. If refundable amount exists and hasn't been refunded yet, credit user
            if ($refundAmount > 0.0001 && !$alreadyRefunded) {
                $this->db->prepare("UPDATE users SET balance = balance + :amt, spent = GREATEST(0, spent - :amt) WHERE id = :uid")
                         ->execute(['amt' => $refundAmount, 'uid' => $userId]);

                $note = "Partial order refund ({$remains}/{$totalQty} unfulfilled)";
                $this->db->prepare("
                    INSERT INTO transactions (user_id, order_id, type, amount, gateway, status, note, created_at)
                    VALUES (:uid, :oid, 'refund', :amt, 'system', 'completed', :note, NOW())
                ")->execute([
                    'uid' => $userId,
                    'oid' => $orderId,
                    'amt' => $refundAmount,
                    'note' => $note
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->log(" -> Error processing partial for Order #{$orderId}: " . $e->getMessage());
            $this->stats['orders']['errors']++;
            return false;
        }
    }

    /**
     * 2. Task: Synchronize External Provider Balances & Currency
     */
    private function runTaskServices(): void
    {
        $this->log("[TASK: PROVIDERS] Checking active provider balances...");

        try {
            $stmt = $this->db->query("SELECT id, name, api_url, api_key, balance, currency FROM providers WHERE status = 'active'");
            $providers = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $this->log(" -> Error querying providers: " . $e->getMessage());
            $this->stats['providers']['errors']++;
            return;
        }

        $this->stats['providers']['checked'] = count($providers);
        $this->log(" -> Found " . count($providers) . " active provider(s) to synchronize.");

        foreach ($providers as $p) {
            $pId = (int)$p['id'];
            $pName = (string)$p['name'];

            $response = call_provider_api($p['api_url'], [
                'key' => $p['api_key'],
                'action' => 'balance'
            ], 12);

            if (isset($response['balance']) && is_numeric($response['balance'])) {
                $newBal = (float)$response['balance'];
                $currency = !empty($response['currency']) ? strtoupper(trim((string)$response['currency'])) : ($p['currency'] ?: 'USD');

                if ($this->dryRun) {
                    $this->log(" -> [DRY-RUN] Provider #{$pId} ({$pName}): Balance would update to {$currency} {$newBal}.");
                    $this->stats['providers']['updated']++;
                    continue;
                }

                $this->db->prepare("
                    UPDATE providers 
                    SET balance = :b, currency = :c, updated_at = NOW() 
                    WHERE id = :id
                ")->execute([
                    'b' => $newBal,
                    'c' => $currency,
                    'id' => $pId
                ]);

                $this->stats['providers']['updated']++;
                $this->log(" -> Provider #{$pId} ({$pName}): Balance updated successfully -> {$currency} " . number_format($newBal, 2));
            } else {
                $errMsg = $response['error'] ?? 'No balance field in response';
                $this->stats['providers']['errors']++;
                $this->log(" -> Provider #{$pId} ({$pName}): Could not fetch balance ('{$errMsg}'). Existing balance preserved.");
            }
        }
    }

    /**
     * 3. Task: Process Due Drip-feed Order Runs
     */
    private function runTaskDripFeed(): void
    {
        $this->log("[TASK: DRIP-FEED] Checking scheduled drip-feed runs...");

        // Ensure tables exist
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS `drip_feed_orders` (
                  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  `user_id` INT UNSIGNED NOT NULL,
                  `service_id` INT UNSIGNED NOT NULL,
                  `link` VARCHAR(512) NOT NULL,
                  `quantity_per_run` INT UNSIGNED NOT NULL,
                  `total_runs` INT UNSIGNED NOT NULL,
                  `completed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
                  `failed_runs` INT UNSIGNED NOT NULL DEFAULT 0,
                  `interval_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
                  `charge_per_run` DECIMAL(10, 4) NOT NULL,
                  `total_charge` DECIMAL(10, 4) NOT NULL,
                  `refunded_amount` DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
                  `status` ENUM('queued', 'running', 'completed', 'partially_failed', 'cancelled', 'failed') NOT NULL DEFAULT 'queued',
                  `last_run_at` DATETIME NULL,
                  `next_run_at` DATETIME NOT NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  INDEX `idx_drip_status_next` (`status`, `next_run_at`),
                  INDEX `idx_drip_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `drip_feed_runs` (
                  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                  `drip_feed_id` INT UNSIGNED NOT NULL,
                  `run_number` INT UNSIGNED NOT NULL,
                  `order_id` INT UNSIGNED NULL,
                  `status` ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
                  `provider_order_id` VARCHAR(64) NULL,
                  `error_message` TEXT NULL,
                  `scheduled_at` DATETIME NOT NULL,
                  `executed_at` DATETIME NULL,
                  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                  INDEX `idx_drip_run_schedule` (`drip_feed_id`, `run_number`),
                  INDEX `idx_drip_run_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $e) {
            // Ignore if already present
        }

        try {
            $dueStmt = $this->db->prepare("
                SELECT d.*, s.name AS service_name, s.provider_id, s.provider_service_id, s.status AS service_status,
                       p.api_url, p.api_key
                FROM drip_feed_orders d
                JOIN services s ON d.service_id = s.id
                LEFT JOIN providers p ON s.provider_id = p.id
                WHERE d.status IN ('queued', 'running')
                  AND d.next_run_at <= NOW()
                ORDER BY d.next_run_at ASC
                LIMIT 25
            ");
            $dueStmt->execute();
            $dueJobs = $dueStmt->fetchAll();
        } catch (\Throwable $e) {
            $this->log(" -> Error fetching drip feed orders: " . $e->getMessage());
            return;
        }

        $this->stats['dripfeed']['jobs_checked'] = count($dueJobs);
        $this->log(" -> Found " . count($dueJobs) . " due drip-feed job(s).");

        if (empty($dueJobs)) {
            return;
        }

        foreach ($dueJobs as $job) {
            $jobId = (int)$job['id'];

            // Find next pending run
            $runStmt = $this->db->prepare("
                SELECT * FROM drip_feed_runs
                WHERE drip_feed_id = :jid AND status = 'pending'
                ORDER BY run_number ASC
                LIMIT 1
            ");
            $runStmt->execute(['jid' => $jobId]);
            $run = $runStmt->fetch();

            if (!$run) {
                // All runs completed
                $newStatus = ($job['failed_runs'] > 0) ? 'partially_failed' : 'completed';
                if (!$this->dryRun) {
                    $this->db->prepare("UPDATE drip_feed_orders SET status = :st WHERE id = :id")->execute(['st' => $newStatus, 'id' => $jobId]);
                }
                $this->log(" -> Drip-feed Job #{$jobId}: No pending runs left. Marked as {$newStatus}.");
                continue;
            }

            $runId = (int)$run['id'];
            $runNum = (int)$run['run_number'];

            if ($this->dryRun) {
                $this->log(" -> [DRY-RUN] Drip-feed Job #{$jobId}: Would execute Run #{$runNum}.");
                $this->stats['dripfeed']['runs_executed']++;
                continue;
            }

            // Lock run record
            $lockStmt = $this->db->prepare("UPDATE drip_feed_runs SET status = 'running' WHERE id = :rid AND status = 'pending'");
            $lockStmt->execute(['rid' => $runId]);
            if ($lockStmt->rowCount() === 0) {
                $this->log(" -> Drip-feed Job #{$jobId} Run #{$runNum}: Already in progress by another worker. Skipping.");
                continue;
            }

            // Check if service is still active
            if ($job['service_status'] !== 'active') {
                $this->log(" -> Drip-feed Job #{$jobId}: Service #{$job['service_id']} is inactive. Marking run as failed.");
                $this->db->prepare("UPDATE drip_feed_runs SET status = 'failed', error_message = 'Service inactive', executed_at = NOW() WHERE id = :rid")->execute(['rid' => $runId]);
                $this->db->prepare("UPDATE drip_feed_orders SET failed_runs = failed_runs + 1 WHERE id = :jid")->execute(['jid' => $jobId]);
                $this->stats['dripfeed']['failed']++;
                continue;
            }

            $this->db->beginTransaction();
            try {
                // 1. Create order entry
                $insOrder = $this->db->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode, created_at)
                    VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto', NOW())
                ");
                $insOrder->execute([
                    'uid' => $job['user_id'],
                    'sid' => $job['service_id'],
                    'pid' => $job['provider_id'] ?: null,
                    'link' => $job['link'],
                    'qty' => $job['quantity_per_run'],
                    'charge' => $job['charge_per_run'],
                    'remains' => $job['quantity_per_run']
                ]);
                $newOrderId = (int)$this->db->lastInsertId();

                // 2. Dispatch to external provider if mapped
                $providerOrderId = null;
                $runError = null;

                if (!empty($job['provider_id']) && !empty($job['api_url']) && !empty($job['api_key']) && !empty($job['provider_service_id'])) {
                    $apiResp = call_provider_api($job['api_url'], [
                        'key' => $job['api_key'],
                        'action' => 'add',
                        'service' => $job['provider_service_id'],
                        'link' => $job['link'],
                        'quantity' => $job['quantity_per_run']
                    ], 15);

                    if (!empty($apiResp['order'])) {
                        $providerOrderId = (string)$apiResp['order'];
                        $this->db->prepare("UPDATE orders SET provider_order_id = :poid, status = 'processing' WHERE id = :oid")
                                 ->execute(['poid' => $providerOrderId, 'oid' => $newOrderId]);
                    } elseif (!empty($apiResp['error'])) {
                        $runError = is_string($apiResp['error']) ? $apiResp['error'] : 'Provider rejected request';
                        $this->db->prepare("UPDATE orders SET status = 'pending' WHERE id = :oid")->execute(['oid' => $newOrderId]);
                    }
                }

                // 3. Mark run completed
                $this->db->prepare("
                    UPDATE drip_feed_runs 
                    SET order_id = :oid, status = 'completed', provider_order_id = :poid, error_message = :err, executed_at = NOW()
                    WHERE id = :rid
                ")->execute([
                    'oid' => $newOrderId,
                    'poid' => $providerOrderId,
                    'err' => $runError,
                    'rid' => $runId
                ]);

                // 4. Update parent drip-feed progress
                $nextRunNumber = $runNum + 1;
                $allFinished = ($nextRunNumber > (int)$job['total_runs']);
                $nextRunTime = date('Y-m-d H:i:s', strtotime("+{$job['interval_minutes']} minutes"));

                if ($allFinished) {
                    $this->db->prepare("
                        UPDATE drip_feed_orders 
                        SET completed_runs = completed_runs + 1, last_run_at = NOW(), status = 'completed'
                        WHERE id = :jid
                    ")->execute(['jid' => $jobId]);
                } else {
                    $this->db->prepare("
                        UPDATE drip_feed_orders 
                        SET completed_runs = completed_runs + 1, last_run_at = NOW(), next_run_at = :nxt, status = 'queued'
                        WHERE id = :jid
                    ")->execute(['nxt' => $nextRunTime, 'jid' => $jobId]);
                }

                $this->db->commit();
                $this->stats['dripfeed']['runs_executed']++;
                $this->stats['dripfeed']['completed']++;
                $this->log(" -> Drip-feed Job #{$jobId} Run #{$runNum}: Successfully created Order #{$newOrderId}" . ($providerOrderId ? " (Provider #{$providerOrderId})" : "") . ".");
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $this->db->prepare("UPDATE drip_feed_runs SET status = 'failed', error_message = :err WHERE id = :rid")->execute([
                    'err' => $e->getMessage(),
                    'rid' => $runId
                ]);
                $this->db->prepare("UPDATE drip_feed_orders SET failed_runs = failed_runs + 1, status = 'queued' WHERE id = :jid")->execute(['jid' => $jobId]);
                $this->stats['dripfeed']['failed']++;
                $this->log(" -> Drip-feed Job #{$jobId} Run #{$runNum}: Failed with error: " . $e->getMessage());
            }
        }
    }

    /**
     * 4. Task: Automated PhonePe Business QR / Gmail Deposit Reconciliation
     */
    private function runTaskPayments(): void
    {
        $this->log("[TASK: PAYMENTS] Checking automated payment verification queues...");

        $gw = get_payment_method('phonepe_business_qr');
        if (!$gw || !is_gateway_active($gw['status'] ?? '')) {
            $this->log(" -> PhonePe Business QR gateway is inactive or not installed. Skipping payment reconciliation.");
            return;
        }

        $gwConfig = get_gateway_config($gw);
        if (empty($gwConfig['gmail_email']) && empty($gwConfig['gmail_client_id'])) {
            $this->log(" -> PhonePe Business QR gateway has no Gmail credentials configured. Skipping.");
            return;
        }

        if (!file_exists(__DIR__ . '/../includes/gmail_verifier.php')) {
            $this->log(" -> includes/gmail_verifier.php helper missing. Skipping.");
            return;
        }

        require_once __DIR__ . '/../includes/gmail_verifier.php';

        try {
            $stmt = $this->db->query("
                SELECT t.*, u.username, u.email
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                WHERE t.type = 'deposit'
                  AND t.status = 'pending'
                  AND (t.gateway = 'PhonePe Business QR' OR t.gateway = 'phonepe_business_qr')
                  AND t.created_at >= NOW() - INTERVAL 48 HOUR
                ORDER BY t.id ASC
                LIMIT 25
            ");
            $pendingList = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $this->log(" -> Error fetching pending deposits: " . $e->getMessage());
            $this->stats['payments']['errors']++;
            return;
        }

        $this->stats['payments']['checked'] = count($pendingList);
        $this->log(" -> Found " . count($pendingList) . " pending deposit(s) awaiting verification.");

        if (empty($pendingList)) {
            return;
        }

        if ($this->dryRun) {
            $this->log(" -> [DRY-RUN] Found " . count($pendingList) . " deposits to verify. Dry run skipping IMAP/API connections.");
            return;
        }

        $verifier = new \GmailPaymentVerifier($gwConfig);

        foreach ($pendingList as $txn) {
            $depositId = (int)$txn['id'];
            $utr = (string)$txn['gateway_txn_id'];
            $amount = (float)$txn['amount'];

            try {
                $result = $verifier->verifyDeposit($this->db, $depositId, $utr, $amount);

                if (!empty($result['success']) && !empty($result['credited'])) {
                    $this->stats['payments']['credited']++;
                    $this->log(" -> Deposit #{$depositId} (UTR: {$utr}): VERIFIED & CREDITED! ₹" . number_format($amount, 2));
                } elseif (($result['status'] ?? '') === 'duplicate_rejected') {
                    $this->stats['payments']['rejected']++;
                    $this->log(" -> Deposit #{$depositId} (UTR: {$utr}): REJECTED (Duplicate UTR).");
                } elseif (($result['status'] ?? '') === 'amount_mismatch') {
                    $this->stats['payments']['rejected']++;
                    $this->log(" -> Deposit #{$depositId} (UTR: {$utr}): FLAGGED (Amount mismatch).");
                } elseif (($result['status'] ?? '') === 'connection_error') {
                    $this->stats['payments']['errors']++;
                    $this->log(" -> Deposit #{$depositId}: Connection error ('{$result['error']}'). Pausing payment scan.");
                    break;
                } else {
                    $this->stats['payments']['pending']++;
                    $this->log(" -> Deposit #{$depositId} (UTR: {$utr}): PENDING (No matching payment email found yet).");
                }
            } catch (\Throwable $e) {
                $this->stats['payments']['errors']++;
                $this->log(" -> Deposit #{$depositId} exception: " . $e->getMessage());
            }
        }
    }

    /**
     * 5. Task: Housekeeping & Maintenance (Flash Sales, Polls Expiration)
     */
    private function runTaskMaintenance(): void
    {
        $this->log("[TASK: MAINTENANCE] Running system housekeeping...");

        // Auto-archive expired flash sales
        try {
            if ($this->dryRun) {
                $count = (int)$this->db->query("SELECT COUNT(*) FROM flash_sales WHERE ends_at <= NOW() AND status = 'active'")->fetchColumn();
                $this->log(" -> [DRY-RUN] Would archive {$count} expired flash sale(s).");
            } else {
                $upd = $this->db->query("UPDATE flash_sales SET status = 'archived' WHERE ends_at <= NOW() AND status = 'active'");
                $archived = $upd->rowCount();
                $this->stats['maintenance']['flash_sales_archived'] = $archived;
                if ($archived > 0) {
                    $this->log(" -> Archived {$archived} expired flash sale(s).");
                }
            }
        } catch (\Throwable $e) {
            // Flash sales table may not exist in early installs
        }

        // Auto-close expired polls
        try {
            if ($this->dryRun) {
                $count = (int)$this->db->query("SELECT COUNT(*) FROM polls WHERE ends_at IS NOT NULL AND ends_at <= NOW() AND status = 'published'")->fetchColumn();
                $this->log(" -> [DRY-RUN] Would close {$count} expired poll(s).");
            } else {
                $upd = $this->db->query("UPDATE polls SET status = 'closed' WHERE ends_at IS NOT NULL AND ends_at <= NOW() AND status = 'published'");
                $closed = $upd->rowCount();
                $this->stats['maintenance']['polls_closed'] = $closed;
                if ($closed > 0) {
                    $this->log(" -> Closed {$closed} expired community poll(s).");
                }
            }
        } catch (\Throwable $e) {
            // Polls table may not exist in early installs
        }

        $this->log(" -> Housekeeping completed cleanly.");
    }

    /**
     * Records last execution metrics into the settings table.
     */
    private function recordCronHeartbeat(float $duration, string $status, string $error = ''): void
    {
        try {
            set_setting('cron_last_run_at', date('Y-m-d H:i:s'));
            set_setting('cron_last_run_status', $status);
            set_setting('cron_last_run_duration', sprintf('%.2fs', $duration));
            set_setting('cron_last_run_summary', json_encode([
                'timestamp' => date('Y-m-d H:i:s'),
                'duration_seconds' => $duration,
                'status' => $status,
                'stats' => $this->stats,
                'error' => $error
            ], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // Silently ignore settings write errors
        }
    }

    /**
     * Internal logger that formats messages with timestamps.
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}";
        $this->logs[] = $line;

        if ($this->isCli) {
            echo $line . PHP_EOL;
            @ob_flush();
            @flush();
        }
    }
}

// =========================================================================
// RUNNER BOOTSTRAPPER (CLI OR WEB)
// =========================================================================

$isCli = (php_sapi_name() === 'cli');

// Determine task parameter
$task = 'all';
$dryRun = false;
$format = 'text';

if ($isCli) {
    // Parse CLI options
    $options = getopt('', ['task:', 'dry-run', 'help', 'cron-key:']);
    if (isset($options['help'])) {
        echo <<<HELP
Mona SMM Panel Master Cron Runner
Usage:
  php cron/run.php [options]

Options:
  --task=all|orders|services|dripfeed|payments|maintenance  Specific task to run (default: all)
  --dry-run                                                 Simulate execution without DB writes
  --help                                                    Show this help message

HELP;
        exit(0);
    }
    if (!empty($options['task'])) {
        $task = (string)$options['task'];
    }
    if (isset($options['dry-run'])) {
        $dryRun = true;
    }
} else {
    // Web / cURL execution security check
    $cronKeyConfig = get_setting('cron_key', 'smm_secure_cron_token');
    $providedKey = $_GET['cron_key'] ?? $_POST['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';

    if (empty($providedKey) || !hash_equals($cronKeyConfig, (string)$providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'code' => 403,
            'message' => 'Forbidden: Invalid or missing cron_key parameter.'
        ]);
        exit(1);
    }

    if (!empty($_GET['task'])) {
        $task = (string)$_GET['task'];
    }
    if (!empty($_GET['dry_run']) && $_GET['dry_run'] === '1') {
        $dryRun = true;
    }
    if (!empty($_GET['format']) && $_GET['format'] === 'json') {
        $format = 'json';
    }
}

// Connect to Database
$db = Database::getConnection();

// Instantiate and execute Master Cron
$master = new MasterCron($db, $isCli, $dryRun);

// Register shutdown function to guarantee advisory lock release on any fatal halt
register_shutdown_function(static function() use ($master) {
    $master->releaseLock();
});

$result = $master->execute($task);

if (!$isCli) {
    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo implode("\n", $result['logs']) . "\n";
    }
}
