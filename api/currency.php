<?php
/**
 * Currency Switcher API Endpoint
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

$code = strtoupper(trim($_POST['currency'] ?? $_GET['currency'] ?? ''));

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Currency code is required.']);
    exit;
}

$currencies = get_active_currencies();
$selected = null;
foreach ($currencies as $curr) {
    if ($curr['code'] === $code) {
        $selected = $curr;
        break;
    }
}

if (!$selected) {
    echo json_encode(['success' => false, 'error' => "Currency '{$code}' is not supported or active."]);
    exit;
}

$_SESSION['user_currency'] = $selected['code'];

echo json_encode([
    'success' => true,
    'currency' => $selected['code'],
    'symbol' => $selected['symbol'],
    'rate' => (float)$selected['exchange_rate']
]);
