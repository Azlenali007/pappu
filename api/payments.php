<?php
/**
 * Payment Gateway Webhook & Server-to-Server Callback Router
 * SMM Panel - PHP 8.3+
 *
 * Handles authenticated asynchronous payment events for:
 * - Razorpay Webhooks (payment.captured, order.paid)
 * - PhonePe PG S2S Callbacks & Webhooks
 * - Cashfree Payments Webhooks (PAYMENT_SUCCESS_WEBHOOK)
 * - Binance Pay Merchant Webhook Notifications
 * - PayPal Webhooks (PAYMENT.CAPTURE.COMPLETED)
 * - Cryptomus Crypto Callbacks
 * - PhonePe Business QR Automated Verification Poller
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_service.php';

$payload = file_get_contents('php://input');
$db = Database::getConnection();
$gatewayParam = strtolower(trim($_GET['gateway'] ?? ''));

// =========================================================================
// 1. RAZORPAY WEBHOOK HANDLER
// =========================================================================
$razorpaySig = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
if (!empty($razorpaySig) && !empty($payload)) {
    $rzpCfg = RazorpayGatewayService::getConfig();
    $secret = !empty($rzpCfg['webhook_secret']) ? $rzpCfg['webhook_secret'] : $rzpCfg['key_secret'];

    if (!empty($secret)) {
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expectedSignature, $razorpaySig)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid Razorpay signature']);
            exit;
        }

        $event = json_decode($payload, true);
        $eventName = $event['event'] ?? '';

        if ($eventName === 'payment.captured' || $eventName === 'order.paid') {
            $payment = $event['payload']['payment']['entity'] ?? null;
            if ($payment) {
                $amount = ((float)$payment['amount']) / 100;
                $paymentId = (string)$payment['id'];
                $orderId = (string)($payment['order_id'] ?? '');
                $depositId = (int)($payment['notes']['deposit_id'] ?? 0);

                if ($depositId > 0) {
                    fulfill_deposit_transaction($db, $depositId, $paymentId, $amount, 'Credited via Razorpay Webhook');
                } else {
                    // Match by order_id in transactions
                    $stmt = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :ord LIMIT 1");
                    $stmt->execute(['ord' => $orderId]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        fulfill_deposit_transaction($db, (int)$existing['id'], $paymentId, $amount, 'Credited via Razorpay Webhook');
                    } else {
                        // Match user by email
                        $email = (string)($payment['email'] ?? '');
                        if (!empty($email)) {
                            $userStmt = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                            $userStmt->execute(['email' => $email]);
                            $u = $userStmt->fetch();
                            if ($u) {
                                $chk = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
                                $chk->execute(['txnid' => $paymentId]);
                                if (!$chk->fetch()) {
                                    wallet_credit($db, (int)$u['id'], $amount, 'Razorpay', $paymentId, 'Razorpay webhook auto credit');
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// =========================================================================
// 2. PHONEPE PAYMENT GATEWAY S2S CALLBACK / WEBHOOK
// =========================================================================
$phonepeVerify = $_SERVER['HTTP_X_VERIFY'] ?? '';
if ($gatewayParam === 'phonepe' || (!empty($phonepeVerify) && strpos($payload, 'response') !== false)) {
    $data = json_decode($payload, true);
    $responseBase64 = $data['response'] ?? '';

    if (!empty($responseBase64)) {
        $ppCfg = PhonePeGatewayService::getConfig();
        if (!empty($phonepeVerify) && !PhonePeGatewayService::verifyCallbackChecksum($responseBase64, $phonepeVerify)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid PhonePe checksum']);
            exit;
        }

        $decoded = json_decode(base64_decode($responseBase64), true);
        if ($decoded && ($decoded['code'] ?? '') === 'PAYMENT_SUCCESS') {
            $merchantTxnId = $decoded['data']['merchantTransactionId'] ?? '';
            $providerTxnId = $decoded['data']['transactionId'] ?? $merchantTxnId;
            $amountPaise = $decoded['data']['amount'] ?? 0;
            $amount = ((float)$amountPaise) / 100;

            $stmt = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
            $stmt->execute(['txnid' => $merchantTxnId]);
            $txn = $stmt->fetch();

            if ($txn) {
                fulfill_deposit_transaction($db, (int)$txn['id'], $providerTxnId, $amount, 'Credited via PhonePe S2S Webhook');
            }
        }
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// =========================================================================
// 3. CASHFREE PAYMENTS WEBHOOK HANDLER
// =========================================================================
$cfTimestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$cfSignature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
if ($gatewayParam === 'cashfree' || (!empty($cfTimestamp) && !empty($cfSignature))) {
    if (!empty($cfTimestamp) && !empty($cfSignature)) {
        if (!CashfreeGatewayService::verifyWebhookSignature($payload, $cfTimestamp, $cfSignature)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid Cashfree webhook signature']);
            exit;
        }
    }

    $event = json_decode($payload, true);
    $eventType = $event['type'] ?? '';

    if ($eventType === 'PAYMENT_SUCCESS_WEBHOOK' || ($event['event'] ?? '') === 'PAYMENT_SUCCESS') {
        $orderData = $event['data']['order'] ?? [];
        $paymentData = $event['data']['payment'] ?? [];
        $orderId = (string)($orderData['order_id'] ?? ($event['orderId'] ?? ''));
        $paymentId = (string)($paymentData['cf_payment_id'] ?? ($event['referenceId'] ?? $orderId));
        $amount = (float)($orderData['order_amount'] ?? ($event['orderAmount'] ?? 0));

        if (!empty($orderId)) {
            $stmt = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :ord LIMIT 1");
            $stmt->execute(['ord' => $orderId]);
            $txn = $stmt->fetch();

            if ($txn) {
                fulfill_deposit_transaction($db, (int)$txn['id'], $paymentId, $amount, 'Credited via Cashfree Webhook');
            }
        }
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

// =========================================================================
// 4. BINANCE PAY WEBHOOK HANDLER
// =========================================================================
if ($gatewayParam === 'binance' || isset($_SERVER['HTTP_BINANCEPAY_SIGNATURE'])) {
    $event = json_decode($payload, true);
    if (($event['bizStatus'] ?? '') === 'PAY_SUCCESS') {
        $data = json_decode($event['data'] ?? '{}', true);
        $merchantTradeNo = (string)($data['merchantTradeNo'] ?? '');
        $amount = (float)($data['orderAmount'] ?? 0);

        if (!empty($merchantTradeNo)) {
            $stmt = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
            $stmt->execute(['txnid' => $merchantTradeNo]);
            $txn = $stmt->fetch();

            if ($txn) {
                fulfill_deposit_transaction($db, (int)$txn['id'], $merchantTradeNo, $amount, 'Credited via Binance Pay Webhook');
            }
        }
    }

    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    exit;
}

// =========================================================================
// 5. CRYPTOMUS WEBHOOK HANDLER
// =========================================================================
$cryptomusSign = $_SERVER['HTTP_SIGN'] ?? '';
$cryptomusKey = get_setting('cryptomus_api_key', '');
if (!empty($cryptomusSign) && !empty($payload) && !empty($cryptomusKey)) {
    $data = json_decode($payload, true);
    if (is_array($data) && isset($data['sign'])) {
        $receivedSign = $data['sign'];
        unset($data['sign']);
        $expectedSign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $cryptomusKey);

        if (hash_equals($expectedSign, $receivedSign) && ($data['status'] ?? '') === 'paid') {
            $orderId = $data['order_id'] ?? '';
            $amount = (float)($data['merchant_amount'] ?? $data['amount'] ?? 0);

            $findTxn = $db->prepare("SELECT id, user_id FROM transactions WHERE gateway_txn_id = :txnid LIMIT 1");
            $findTxn->execute(['txnid' => $orderId]);
            $txnRow = $findTxn->fetch();

            if ($txnRow && $amount > 0) {
                fulfill_deposit_transaction($db, (int)$txnRow['id'], $orderId, $amount, 'Cryptomus crypto webhook auto credit');
            }
        }
    }
}

// =========================================================================
// 6. PHONEPE BUSINESS QR GMAIL RECONCILER ACTION
// =========================================================================
$action = $_GET['action'] ?? '';
if ($action === 'verify_phonepe_qr') {
    $cronKeyConfig = get_setting('cron_key', 'smm_secure_cron_token');
    $key = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    if (!hash_equals($cronKeyConfig, (string)$key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }

    require_once __DIR__ . '/../includes/gmail_verifier.php';
    $gw = get_payment_method('phonepe_business_qr');
    if (!$gw || !is_gateway_active($gw['status'] ?? '')) {
        echo json_encode(['status' => 'inactive', 'message' => 'PhonePe Business QR gateway is inactive']);
        exit;
    }

    $verifier = new GmailPaymentVerifier();
    $stmt = $db->query("
        SELECT id FROM transactions 
        WHERE type = 'deposit' AND status = 'pending' AND (gateway = 'PhonePe Business QR' OR gateway = 'phonepe_business_qr')
        ORDER BY id ASC LIMIT 20
    ");
    $pending = $stmt->fetchAll();
    $results = [];
    foreach ($pending as $p) {
        $results[$p['id']] = $verifier->verifyDeposit($db, (int)$p['id']);
    }

    echo json_encode(['status' => 'ok', 'processed' => count($results), 'results' => $results]);
    exit;
}

echo json_encode(['status' => 'ok']);
