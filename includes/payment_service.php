<?php
/**
 * Genuine Payment Gateway Service Integration Engine
 * SMM Panel - PHP 8.3+
 *
 * Implements real provider-hosted payment flows and API verifications for:
 * 1. Razorpay (Standard Checkout & Orders API v1)
 * 2. PhonePe Payment Gateway (PG v1 Pay Page & Status API)
 * 3. PayPal (REST Orders API v2 & OAuth 2.0)
 * 4. Binance Pay (Merchant OpenAPI v2 & HMAC-SHA512)
 * 5. Cashfree Payments (PG v2023-08-01 Orders & Webhooks)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Normalizes system base URL dynamically
 */
if (!function_exists('app_url')) {
    function app_url(string $path = ''): string {
        $baseUrl = get_setting('site_url', defined('APP_URL') ? APP_URL : '');
        if (empty($baseUrl) || $baseUrl === 'http://localhost:3000') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $protocol = $isHttps ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
            $baseUrl = "{$protocol}://{$host}";
        }
        $baseUrl = rtrim($baseUrl, '/');
        if ($path !== '') {
            return $baseUrl . '/' . ltrim($path, '/');
        }
        return $baseUrl;
    }
}

/**
 * Thread-safe, atomic, idempotent deposit fulfillment helper.
 * Credits user's wallet exactly once and records provider transaction reference.
 */
if (!function_exists('fulfill_deposit_transaction')) {
function fulfill_deposit_transaction(
    PDO $db,
    int $depositId,
    string $providerTxnId,
    ?float $verifiedAmount = null,
    string $note = '',
    ?string $rawMetadata = null
): array {
    try {
        $db->beginTransaction();

        // 1. Row-level lock to prevent concurrent webhook/redirect race conditions
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $depositId]);
        $txn = $stmt->fetch();

        if (!$txn) {
            $db->rollBack();
            return ['success' => false, 'error' => "Deposit #{$depositId} not found."];
        }

        // 2. Idempotency check: Already completed
        if ($txn['status'] === 'completed') {
            $db->rollBack();
            return [
                'success' => true,
                'already_credited' => true,
                'message' => "Deposit #{$depositId} was already completed and credited.",
                'amount' => (float)$txn['amount'],
                'user_id' => (int)$txn['user_id']
            ];
        }

        $userId = (int)$txn['user_id'];
        $creditAmount = $verifiedAmount !== null && $verifiedAmount > 0 ? $verifiedAmount : (float)$txn['amount'];

        // 3. Atomically update user balance
        $stmtUser = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
        $stmtUser->execute(['amt' => $creditAmount, 'uid' => $userId]);

        // 4. Update transaction status to completed
        $fullNote = trim(($txn['note'] ? $txn['note'] . ' | ' : '') . $note);
        if ($rawMetadata) {
            $fullNote .= ' ' . substr($rawMetadata, 0, 150);
        }

        $stmtTxn = $db->prepare("
            UPDATE transactions 
            SET status = 'completed',
                amount = :amt,
                gateway_txn_id = :txnid,
                note = :note
            WHERE id = :id
        ");
        $stmtTxn->execute([
            'amt' => $creditAmount,
            'txnid' => $providerTxnId,
            'note' => $fullNote,
            'id' => $depositId
        ]);

        $db->commit();

        // 5. Trigger referral commission if configured
        try {
            process_referral_commission($userId, $creditAmount, 'deposit', $depositId, null);
        } catch (\Throwable $re) {
            error_log("Referral commission error in fulfill_deposit_transaction: " . $re->getMessage());
        }

        return [
            'success' => true,
            'already_credited' => false,
            'message' => "Successfully verified and credited " . format_currency($creditAmount) . " to user wallet.",
            'amount' => $creditAmount,
            'user_id' => $userId
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("fulfill_deposit_transaction error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
}

/**
 * 1. RAZORPAY PAYMENT GATEWAY SERVICE
 * Official API Docs: https://razorpay.com/docs/api/orders/
 */
if (!class_exists('RazorpayGatewayService')) {
class RazorpayGatewayService {
    public static function getConfig(): array {
        $gw = get_payment_method('razorpay');
        $cfg = $gw ? get_gateway_config($gw) : [];
        $keyId = trim($cfg['key_id'] ?? get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : ''));
        $keySecret = trim($cfg['key_secret'] ?? get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : ''));
        $webhookSecret = trim($cfg['webhook_secret'] ?? '');
        $mode = $cfg['mode'] ?? 'test';

        return [
            'key_id' => $keyId,
            'key_secret' => $keySecret,
            'webhook_secret' => $webhookSecret,
            'mode' => $mode
        ];
    }

    public static function createOrder(float $amount, int $depositId, array $user): array {
        $cfg = self::getConfig();
        if (empty($cfg['key_id']) || empty($cfg['key_secret'])) {
            return ['success' => false, 'error' => 'Razorpay Key ID or Key Secret is not configured in Admin Settings.'];
        }

        $amountInPaise = (int)round($amount * 100);
        $payload = [
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => 'rcpt_' . $depositId . '_' . bin2hex(random_bytes(3)),
            'notes' => [
                'deposit_id' => (string)$depositId,
                'user_id' => (string)$user['id'],
                'username' => (string)($user['username'] ?? '')
            ]
        ];

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_USERPWD => $cfg['key_id'] . ':' . $cfg['key_secret'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 20
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'Razorpay connection error: ' . $err];
        }

        $data = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
            return [
                'success' => true,
                'order_id' => $data['id'],
                'amount' => $amount,
                'amount_paise' => $amountInPaise,
                'key_id' => $cfg['key_id'],
                'currency' => 'INR'
            ];
        }

        $errMsg = $data['error']['description'] ?? ($data['error']['message'] ?? 'Failed to initialize Razorpay order.');
        return ['success' => false, 'error' => $errMsg, 'raw' => $data];
    }

    public static function verifySignature(string $orderId, string $paymentId, string $signature): bool {
        $cfg = self::getConfig();
        if (empty($cfg['key_secret'])) {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $cfg['key_secret']);
        return hash_equals($expected, $signature);
    }

    public static function fetchPayment(string $paymentId): ?array {
        $cfg = self::getConfig();
        if (empty($cfg['key_id']) || empty($cfg['key_secret'])) {
            return null;
        }

        $ch = curl_init('https://api.razorpay.com/v1/payments/' . urlencode($paymentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $cfg['key_id'] . ':' . $cfg['key_secret'],
            CURLOPT_TIMEOUT => 15
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return json_decode((string)$res, true);
        }
        return null;
    }
}
}

/**
 * 2. PHONEPE PAYMENT GATEWAY SERVICE
 * Official API Docs: PhonePe PG v1 Pay & Order Status APIs
 */
if (!class_exists('PhonePeGatewayService')) {
class PhonePeGatewayService {
    public static function getConfig(): array {
        $gw = get_payment_method('phonepe');
        $cfg = $gw ? get_gateway_config($gw) : [];
        $merchantId = trim($cfg['merchant_id'] ?? '');
        $saltKey = trim($cfg['salt_key'] ?? '');
        $saltIndex = trim($cfg['salt_index'] ?? '1');
        $mode = $cfg['mode'] ?? 'sandbox';

        return [
            'merchant_id' => $merchantId,
            'salt_key' => $saltKey,
            'salt_index' => $saltIndex,
            'mode' => in_array($mode, ['production', 'live'], true) ? 'production' : 'sandbox'
        ];
    }

    private static function getBaseUrl(string $mode): string {
        return $mode === 'production'
            ? 'https://api.phonepe.com/apis/hermes'
            : 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    }

    public static function initiatePayment(float $amount, int $depositId, array $user): array {
        $cfg = self::getConfig();
        if (empty($cfg['merchant_id']) || empty($cfg['salt_key'])) {
            return ['success' => false, 'error' => 'PhonePe Merchant ID or Salt Key is not configured in Admin Settings.'];
        }

        $merchantTxnId = 'PP_' . $depositId . '_' . time();
        $amountPaise = (int)round($amount * 100);

        $payload = [
            'merchantId' => $cfg['merchant_id'],
            'merchantTransactionId' => $merchantTxnId,
            'merchantUserId' => 'USR_' . $user['id'],
            'amount' => $amountPaise,
            'redirectUrl' => app_url('/user/add-funds.php?gateway=phonepe&action=callback&deposit_id=' . $depositId),
            'redirectMode' => 'POST',
            'callbackUrl' => app_url('/api/payments.php?gateway=phonepe'),
            'mobileNumber' => !empty($user['phone']) ? preg_replace('/\D/', '', (string)$user['phone']) : '9999999999',
            'paymentInstrument' => [
                'type' => 'PAY_PAGE'
            ]
        ];

        $jsonPayload = json_encode($payload);
        $base64Payload = base64_encode($jsonPayload);
        $checksum = hash('sha256', $base64Payload . '/pg/v1/pay' . $cfg['salt_key']) . '###' . $cfg['salt_index'];

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $ch = curl_init($baseUrl . '/pg/v1/pay');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['request' => $base64Payload]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-VERIFY: ' . $checksum
            ],
            CURLOPT_TIMEOUT => 25
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'PhonePe connection error: ' . $err];
        }

        $data = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
            $redirectUrl = $data['data']['instrumentResponse']['redirectInfo']['url'] ?? null;
            if ($redirectUrl) {
                return [
                    'success' => true,
                    'checkout_url' => $redirectUrl,
                    'merchant_txn_id' => $merchantTxnId,
                    'amount' => $amount
                ];
            }
        }

        $msg = $data['message'] ?? 'Failed to initiate PhonePe payment session.';
        return ['success' => false, 'error' => $msg, 'raw' => $data];
    }

    public static function checkStatus(string $merchantTxnId): array {
        $cfg = self::getConfig();
        if (empty($cfg['merchant_id']) || empty($cfg['salt_key'])) {
            return ['success' => false, 'error' => 'PhonePe credentials not configured.'];
        }

        $path = '/pg/v1/status/' . $cfg['merchant_id'] . '/' . $merchantTxnId;
        $checksum = hash('sha256', $path . $cfg['salt_key']) . '###' . $cfg['salt_index'];
        $baseUrl = self::getBaseUrl($cfg['mode']);

        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-VERIFY: ' . $checksum,
                'X-MERCHANT-ID: ' . $cfg['merchant_id']
            ],
            CURLOPT_TIMEOUT => 20
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['success'])) {
            $code = $data['code'] ?? '';
            $amountPaise = $data['data']['amount'] ?? 0;
            $providerTxnId = $data['data']['transactionId'] ?? $merchantTxnId;

            return [
                'success' => true,
                'is_paid' => ($code === 'PAYMENT_SUCCESS'),
                'status_code' => $code,
                'amount' => ((float)$amountPaise) / 100,
                'provider_txn_id' => $providerTxnId,
                'raw' => $data
            ];
        }

        return [
            'success' => false,
            'error' => $data['message'] ?? 'Status check failed',
            'raw' => $data
        ];
    }

    public static function verifyCallbackChecksum(string $responseBase64, string $xVerify): bool {
        $cfg = self::getConfig();
        if (empty($cfg['salt_key'])) {
            return false;
        }
        $expected = hash('sha256', $responseBase64 . $cfg['salt_key']) . '###' . $cfg['salt_index'];
        return hash_equals($expected, $xVerify);
    }
}
}

/**
 * 3. PAYPAL PAYMENT GATEWAY SERVICE
 * Official API Docs: PayPal REST API v2 Orders & Capture
 */
if (!class_exists('PayPalGatewayService')) {
class PayPalGatewayService {
    public static function getConfig(): array {
        $gw = get_payment_method('paypal');
        $cfg = $gw ? get_gateway_config($gw) : [];
        $clientId = trim($cfg['client_id'] ?? '');
        $clientSecret = trim($cfg['client_secret'] ?? '');
        $webhookId = trim($cfg['webhook_id'] ?? '');
        $mode = $cfg['mode'] ?? 'sandbox';

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'webhook_id' => $webhookId,
            'mode' => in_array($mode, ['live', 'production'], true) ? 'live' : 'sandbox'
        ];
    }

    private static function getBaseUrl(string $mode): string {
        return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public static function getAccessToken(): ?string {
        $cfg = self::getConfig();
        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            return null;
        }

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $ch = curl_init($baseUrl . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_USERPWD => $cfg['client_id'] . ':' . $cfg['client_secret'],
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
            CURLOPT_TIMEOUT => 20
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $data = json_decode((string)$res, true);
            return $data['access_token'] ?? null;
        }
        return null;
    }

    public static function createOrder(float $amount, int $depositId, string $currency = 'USD'): array {
        $cfg = self::getConfig();
        $token = self::getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'Unable to authenticate with PayPal API. Verify Client ID and Client Secret in Admin Settings.'];
        }

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'DEP_' . $depositId,
                'description' => 'SMM Panel Wallet Deposit #' . $depositId,
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($amount, 2, '.', '')
                ]
            ]],
            'application_context' => [
                'brand_name' => app_name(),
                'landing_page' => 'BILLING',
                'user_action' => 'PAY_NOW',
                'return_url' => app_url('/user/add-funds.php?gateway=paypal&action=return&deposit_id=' . $depositId),
                'cancel_url' => app_url('/user/add-funds.php?gateway=paypal&action=cancel&deposit_id=' . $depositId)
            ]
        ];

        $ch = curl_init($baseUrl . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT => 25
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'PayPal connection error: ' . $err];
        }

        $data = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
            $orderId = $data['id'];
            $approveUrl = null;
            if (!empty($data['links'])) {
                foreach ($data['links'] as $link) {
                    if (($link['rel'] ?? '') === 'approve') {
                        $approveUrl = $link['href'];
                        break;
                    }
                }
            }

            if ($approveUrl) {
                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'checkout_url' => $approveUrl,
                    'amount' => $amount
                ];
            }
        }

        $msg = $data['message'] ?? 'Failed to initialize PayPal checkout order.';
        return ['success' => false, 'error' => $msg, 'raw' => $data];
    }

    public static function captureOrder(string $orderId): array {
        $cfg = self::getConfig();
        $token = self::getAccessToken();
        if (!$token) {
            return ['success' => false, 'error' => 'PayPal authentication failed during capture.'];
        }

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $ch = curl_init($baseUrl . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_TIMEOUT => 25
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && ($data['status'] ?? '') === 'COMPLETED') {
            $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
            $captureId = $capture['id'] ?? $orderId;
            $amt = (float)($capture['amount']['value'] ?? 0);

            return [
                'success' => true,
                'is_paid' => true,
                'status' => 'COMPLETED',
                'capture_id' => $captureId,
                'amount' => $amt,
                'raw' => $data
            ];
        }

        return [
            'success' => false,
            'error' => $data['message'] ?? 'PayPal order capture was not completed.',
            'raw' => $data
        ];
    }
}
}

/**
 * 4. BINANCE PAY SERVICE
 * Official API Docs: Binance Pay Merchant OpenAPI v2/v3
 */
if (!class_exists('BinancePayGatewayService')) {
class BinancePayGatewayService {
    public static function getConfig(): array {
        $gw = get_payment_method('binance');
        $cfg = $gw ? get_gateway_config($gw) : [];
        $apiKey = trim($cfg['api_key'] ?? '');
        $secretKey = trim($cfg['secret_key'] ?? '');
        $merchantId = trim($cfg['merchant_id'] ?? '');
        $mode = $cfg['mode'] ?? 'test';

        return [
            'api_key' => $apiKey,
            'secret_key' => $secretKey,
            'merchant_id' => $merchantId,
            'mode' => $mode
        ];
    }

    private static function makeSignedRequest(string $endpoint, array $payload): array {
        $cfg = self::getConfig();
        if (empty($cfg['api_key']) || empty($cfg['secret_key'])) {
            return ['success' => false, 'error' => 'Binance Pay API Key or Secret Key is not configured.'];
        }

        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $json = json_encode($payload);
        $toSign = $timestamp . "\n" . $nonce . "\n" . $json . "\n";
        $signature = strtoupper(hash_hmac('sha512', $toSign, $cfg['secret_key']));

        $url = 'https://bpay.binanceapi.com' . $endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'BinancePay-Timestamp: ' . $timestamp,
                'BinancePay-Nonce: ' . $nonce,
                'BinancePay-Certificate-SN: ' . $cfg['api_key'],
                'BinancePay-Signature: ' . $signature
            ],
            CURLOPT_TIMEOUT => 25
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'Binance connection error: ' . $err];
        }

        $data = json_decode((string)$res, true);
        return [
            'success' => ($httpCode >= 200 && $httpCode < 300 && ($data['status'] ?? '') === 'SUCCESS'),
            'http_code' => $httpCode,
            'data' => $data
        ];
    }

    public static function createOrder(float $amount, int $depositId, string $currency = 'USDT'): array {
        $tradeNo = 'BP_' . $depositId . '_' . time();
        $payload = [
            'env' => ['terminalType' => 'WEB'],
            'merchantTradeNo' => $tradeNo,
            'orderAmount' => round($amount, 2),
            'currency' => $currency,
            'goods' => [
                'goodsType' => '02',
                'goodsCategory' => '6000',
                'referenceGoodsId' => 'deposit_' . $depositId,
                'goodsName' => 'Wallet Deposit',
                'goodsDetail' => 'SMM Panel Deposit #' . $depositId
            ],
            'returnUrl' => app_url('/user/add-funds.php?gateway=binance&action=return&deposit_id=' . $depositId),
            'cancelUrl' => app_url('/user/add-funds.php?gateway=binance&action=cancel&deposit_id=' . $depositId)
        ];

        $res = self::makeSignedRequest('/binancepay/openapi/v2/order', $payload);
        if ($res['success'] && !empty($res['data']['data']['checkoutUrl'])) {
            return [
                'success' => true,
                'checkout_url' => $res['data']['data']['checkoutUrl'],
                'merchant_trade_no' => $tradeNo,
                'prepay_id' => $res['data']['data']['prepayId'] ?? '',
                'amount' => $amount
            ];
        }

        $msg = $res['data']['errorMessage'] ?? ($res['error'] ?? 'Binance Pay order creation failed.');
        return ['success' => false, 'error' => $msg, 'raw' => $res['data'] ?? null];
    }

    public static function queryOrder(string $merchantTradeNo): array {
        $payload = ['merchantTradeNo' => $merchantTradeNo];
        $res = self::makeSignedRequest('/binancepay/openapi/v2/order/query', $payload);

        if ($res['success']) {
            $data = $res['data']['data'] ?? [];
            $status = $data['status'] ?? '';
            $isPaid = ($status === 'PAID');
            $amt = (float)($data['orderAmount'] ?? 0);

            return [
                'success' => true,
                'is_paid' => $isPaid,
                'status' => $status,
                'amount' => $amt,
                'raw' => $data
            ];
        }

        return [
            'success' => false,
            'error' => $res['data']['errorMessage'] ?? 'Query failed',
            'raw' => $res['data'] ?? null
        ];
    }
}
}

/**
 * 5. CASHFREE PAYMENTS GATEWAY SERVICE
 * Official API Docs: Cashfree Payments API (2023-08-01 / PG Orders)
 * https://www.cashfree.com/docs/
 */
if (!class_exists('CashfreeGatewayService')) {
class CashfreeGatewayService {
    public static function getConfig(): array {
        $gw = get_payment_method('cashfree');
        $cfg = $gw ? get_gateway_config($gw) : [];
        $appId = trim($cfg['app_id'] ?? ($cfg['client_id'] ?? ''));
        $secretKey = trim($cfg['secret_key'] ?? ($cfg['client_secret'] ?? ''));
        $mode = $cfg['mode'] ?? 'sandbox';

        return [
            'app_id' => $appId,
            'secret_key' => $secretKey,
            'mode' => in_array($mode, ['production', 'live'], true) ? 'production' : 'sandbox'
        ];
    }

    private static function getBaseUrl(string $mode): string {
        return $mode === 'production'
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg';
    }

    public static function createOrder(float $amount, int $depositId, array $user): array {
        $cfg = self::getConfig();
        if (empty($cfg['app_id']) || empty($cfg['secret_key'])) {
            return ['success' => false, 'error' => 'Cashfree Client ID / Secret Key is not configured in Admin Settings.'];
        }

        $orderId = 'CF_' . $depositId . '_' . time();
        $payload = [
            'order_id' => $orderId,
            'order_amount' => round($amount, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => 'CUST_' . $user['id'],
                'customer_name' => !empty($user['username']) ? substr($user['username'], 0, 50) : 'Customer',
                'customer_email' => !empty($user['email']) ? $user['email'] : 'customer@example.com',
                'customer_phone' => !empty($user['phone']) ? preg_replace('/\D/', '', (string)$user['phone']) : '9999999999'
            ],
            'order_meta' => [
                'return_url' => app_url('/user/add-funds.php?gateway=cashfree&action=return&order_id={order_id}&deposit_id=' . $depositId),
                'notify_url' => app_url('/api/payments.php?gateway=cashfree'),
                'payment_methods' => 'cc,dc,upi,netbanking,app'
            ],
            'order_note' => 'SMM Panel Deposit #' . $depositId
        ];

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $ch = curl_init($baseUrl . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-client-id: ' . $cfg['app_id'],
                'x-client-secret: ' . $cfg['secret_key'],
                'x-api-version: 2023-08-01'
            ],
            CURLOPT_TIMEOUT => 25
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'Cashfree connection error: ' . $err];
        }

        $data = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['payment_session_id'])) {
            $sessionId = $data['payment_session_id'];
            
            // Cashfree Hosted Checkout page URL:
            // For production / sandbox, payments.cashfree.com/forms/{session_id} or checkout wrapper
            $checkoutUrl = ($cfg['mode'] === 'production')
                ? 'https://payments.cashfree.com/forms/' . urlencode($sessionId)
                : 'https://payments-test.cashfree.com/forms/' . urlencode($sessionId);

            return [
                'success' => true,
                'order_id' => $data['order_id'] ?? $orderId,
                'cf_order_id' => $data['cf_order_id'] ?? '',
                'payment_session_id' => $sessionId,
                'checkout_url' => $checkoutUrl,
                'mode' => $cfg['mode'],
                'amount' => $amount
            ];
        }

        $msg = $data['message'] ?? 'Failed to initialize Cashfree payment session.';
        return ['success' => false, 'error' => $msg, 'raw' => $data];
    }

    public static function getOrder(string $orderId): array {
        $cfg = self::getConfig();
        if (empty($cfg['app_id']) || empty($cfg['secret_key'])) {
            return ['success' => false, 'error' => 'Cashfree credentials not configured.'];
        }

        $baseUrl = self::getBaseUrl($cfg['mode']);
        $ch = curl_init($baseUrl . '/orders/' . urlencode($orderId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-client-id: ' . $cfg['app_id'],
                'x-client-secret: ' . $cfg['secret_key'],
                'x-api-version: 2023-08-01'
            ],
            CURLOPT_TIMEOUT => 20
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$res, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['order_status'])) {
            $status = strtoupper($data['order_status']);
            $isPaid = ($status === 'PAID');
            $amt = (float)($data['order_amount'] ?? 0);

            return [
                'success' => true,
                'is_paid' => $isPaid,
                'order_status' => $status,
                'amount' => $amt,
                'cf_order_id' => $data['cf_order_id'] ?? '',
                'raw' => $data
            ];
        }

        return [
            'success' => false,
            'error' => $data['message'] ?? 'Failed to query Cashfree order status.',
            'raw' => $data
        ];
    }

    public static function verifyWebhookSignature(string $rawBody, string $timestamp, string $signature): bool {
        $cfg = self::getConfig();
        if (empty($cfg['secret_key'])) {
            return false;
        }

        // Official Cashfree signature algorithm: HMAC-SHA256(timestamp + rawBody, secret_key) base64-encoded
        $data = $timestamp . $rawBody;
        $expected = base64_encode(hash_hmac('sha256', $data, $cfg['secret_key'], true));
        return hash_equals($expected, $signature);
    }
}
}

