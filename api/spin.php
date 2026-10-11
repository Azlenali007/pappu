<?php
/**
 * User Spin Wheel AJAX Endpoint
 * SMM Panel - PHP 8.3+
 *
 * Actions:
 * - 'get_config': Returns sanitized public wheel configuration for active bet
 * - 'spin': Atomically executes a spin, debits wallet, chooses outcome via CSPRNG, credits prize
 * - 'recent_winners': Returns real verified winning history with privacy-masked usernames
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spin_wheel_engine.php';

// Authentication verification
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required. Please log in to spin the wheel.'
    ]);
    exit;
}

$user = Auth::user();
$userId = (int)$user['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'get_config';

try {
    switch ($action) {
        case 'get_config':
            $betId = (int)($_GET['bet_id'] ?? $_POST['bet_id'] ?? 0);
            if ($betId <= 0) {
                // Fetch first available active bet
                $activeBets = SpinWheelEngine::getBets(true);
                if (empty($activeBets)) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'No active bet amounts configured at this time.'
                    ]);
                    exit;
                }
                $betId = (int)$activeBets[0]['id'];
            }

            $config = SpinWheelEngine::getPublicWheelConfig($betId);
            if (isset($config['error'])) {
                echo json_encode([
                    'success' => false,
                    'error' => $config['error']
                ]);
                exit;
            }

            // Also provide all available active bet options for selector
            $allActiveBets = SpinWheelEngine::getBets(true);
            $formattedBets = array_map(function($b) use ($betId) {
                return [
                    'id' => (int)$b['id'],
                    'bet_amount' => (float)$b['bet_amount'],
                    'formatted_amount' => format_currency($b['bet_amount']),
                    'is_selected' => ((int)$b['id'] === $betId)
                ];
            }, $allActiveBets);

            echo json_encode([
                'success' => true,
                'wheel_enabled' => SpinWheelEngine::isEnabled(),
                'active_bets' => $formattedBets,
                'current_bet_id' => $betId,
                'wheel_config' => $config,
                'user_balance' => (float)$user['balance'],
                'formatted_balance' => format_currency($user['balance'])
            ]);
            exit;

        case 'spin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
                exit;
            }

            // CSRF Verification
            CSRF::verifyOrAbort();

            $betId = (int)($_POST['bet_id'] ?? 0);
            if ($betId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid bet amount selected.']);
                exit;
            }

            $spinResult = SpinWheelEngine::executeSpin($userId, $betId);
            echo json_encode($spinResult);
            exit;

        case 'recent_winners':
            $limit = min(25, max(5, (int)($_GET['limit'] ?? 10)));
            $winners = SpinWheelEngine::getRecentWinners($limit);
            echo json_encode([
                'success' => true,
                'winners' => $winners
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action requested.']);
            exit;
    }
} catch (\Throwable $e) {
    error_log("api/spin.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected server error occurred. Please try again.'
    ]);
    exit;
}
