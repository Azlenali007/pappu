<?php
/**
 * Add Funds Page (Dynamic Production Gateway Architecture)
 * SMM Panel - PHP 8.3+
 *
 * Real Provider-Hosted Payment Flows & Instant Verifications:
 * - Razorpay (Standard Checkout & Orders API v1)
 * - PhonePe Payment Gateway (PG v1 Pay Page & S2S Status Check)
 * - PayPal (REST Orders API v2 & OAuth Capture)
 * - Binance Pay (OpenAPI v2 & HMAC-SHA512)
 * - Cashfree Payments (PG v2023-08-01 Orders & Webhooks)
 * - PhonePe Business QR (Automated Gmail Reconciler & Manual UTR)
 * - Paytm UPI QR (Manual UTR verification)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_service.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

// =========================================================================
// 1. PROVIDER RETURN & CALLBACK HANDLERS
// =========================================================================
$returnGateway = strtolower(trim($_GET['gateway'] ?? ''));
$action = strtolower(trim($_GET['action'] ?? ''));
$depositId = (int)($_GET['deposit_id'] ?? ($_GET['txn_id'] ?? 0));

// 1.1 RAZORPAY RETURN VERIFICATION
if ($returnGateway === 'razorpay' && $action === 'verify') {
    CSRF::verifyOrAbort();

    $razorpayPaymentId = trim($_POST['razorpay_payment_id'] ?? '');
    $razorpayOrderId = trim($_POST['razorpay_order_id'] ?? '');
    $razorpaySignature = trim($_POST['razorpay_signature'] ?? '');

    if (empty($razorpayPaymentId) || empty($razorpayOrderId) || empty($razorpaySignature)) {
        set_flash('error', "Incomplete Razorpay payment verification parameters.");
        redirect('/user/add-funds.php');
    }

    $isSigValid = RazorpayGatewayService::verifySignature($razorpayOrderId, $razorpayPaymentId, $razorpaySignature);
    if (!$isSigValid) {
        set_flash('error', "Razorpay payment signature mismatch. Payment verification failed.");
        redirect('/user/add-funds.php');
    }

    $paymentData = RazorpayGatewayService::fetchPayment($razorpayPaymentId);
    if (!$paymentData || !in_array($paymentData['status'] ?? '', ['captured', 'authorized'], true)) {
        set_flash('error', "Razorpay payment verification returned non-captured status.");
        redirect('/user/add-funds.php');
    }

    $verifiedAmount = ((float)($paymentData['amount'] ?? 0)) / 100;
    $res = fulfill_deposit_transaction($db, $depositId, $razorpayPaymentId, $verifiedAmount, "Verified via Razorpay API (Payment #{$razorpayPaymentId})");

    if ($res['success']) {
        set_flash('success', "Payment of " . format_currency($res['amount']) . " successfully verified and added to your wallet!");
    } else {
        set_flash('error', "Failed to credit wallet: " . $res['error']);
    }
    redirect('/user/add-funds.php');
}

// 1.2 PHONEPE RETURN CALLBACK
if ($returnGateway === 'phonepe' && $action === 'callback') {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND user_id = :uid LIMIT 1");
    $stmt->execute(['id' => $depositId, 'uid' => $user['id']]);
    $txn = $stmt->fetch();

    if (!$txn) {
        set_flash('error', "Transaction record #{$depositId} not found.");
        redirect('/user/add-funds.php');
    }

    $merchantTxnId = (string)($txn['gateway_txn_id'] ?? '');
    $statusCheck = PhonePeGatewayService::checkStatus($merchantTxnId);

    if ($statusCheck['success'] && !empty($statusCheck['is_paid'])) {
        $res = fulfill_deposit_transaction($db, $depositId, $statusCheck['provider_txn_id'], (float)$txn['amount'], "Verified via PhonePe Gateway API");
        if ($res['success']) {
            set_flash('success', "Payment of " . format_currency($res['amount']) . " successfully verified via PhonePe and added to your wallet!");
        } else {
            set_flash('error', "Failed to credit wallet: " . $res['error']);
        }
    } else {
        if ($txn['status'] === 'pending') {
            $failStmt = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Payment Failed or Cancelled on PhonePe]') WHERE id = :id");
            $failStmt->execute(['id' => $depositId]);
        }
        set_flash('error', "PhonePe payment was not completed or failed. If amount was debited, your bank will reverse it.");
    }
    redirect('/user/add-funds.php');
}

// 1.3 PAYPAL RETURN / CANCEL
if ($returnGateway === 'paypal') {
    if ($action === 'return') {
        $token = trim($_GET['token'] ?? ''); // PayPal Order ID
        $captureRes = PayPalGatewayService::captureOrder($token);

        if ($captureRes['success'] && !empty($captureRes['is_paid'])) {
            $captureId = $captureRes['capture_id'] ?? $token;
            $res = fulfill_deposit_transaction($db, $depositId, $captureId, null, "Verified via PayPal Capture API (Capture #{$captureId})");
            if ($res['success']) {
                set_flash('success', "PayPal payment of " . format_currency($res['amount']) . " successfully captured and added to your wallet!");
            } else {
                set_flash('error', "Failed to credit wallet: " . $res['error']);
            }
        } else {
            set_flash('error', "PayPal capture failed: " . ($captureRes['error'] ?? 'Order was not completed.'));
        }
    } elseif ($action === 'cancel') {
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Cancelled on PayPal]') WHERE id = :id AND user_id = :uid AND status = 'pending'");
        $stmt->execute(['id' => $depositId, 'uid' => $user['id']]);
        set_flash('info', "PayPal payment checkout was cancelled.");
    }
    redirect('/user/add-funds.php');
}

// 1.4 BINANCE PAY RETURN / CANCEL
if ($returnGateway === 'binance') {
    if ($action === 'return') {
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute(['id' => $depositId, 'uid' => $user['id']]);
        $txn = $stmt->fetch();

        if ($txn) {
            $merchantTradeNo = (string)$txn['gateway_txn_id'];
            $queryRes = BinancePayGatewayService::queryOrder($merchantTradeNo);

            if ($queryRes['success'] && !empty($queryRes['is_paid'])) {
                $res = fulfill_deposit_transaction($db, $depositId, $merchantTradeNo, (float)$txn['amount'], "Verified via Binance Pay OpenAPI");
                if ($res['success']) {
                    set_flash('success', "Binance Pay payment of " . format_currency($res['amount']) . " successfully verified and added to your wallet!");
                } else {
                    set_flash('error', "Failed to credit wallet: " . $res['error']);
                }
            } else {
                set_flash('warning', "Binance Pay transaction is pending blockchain confirmation. Wallet will be credited once confirmed.");
            }
        }
    } elseif ($action === 'cancel') {
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Cancelled on Binance Pay]') WHERE id = :id AND user_id = :uid AND status = 'pending'");
        $stmt->execute(['id' => $depositId, 'uid' => $user['id']]);
        set_flash('info', "Binance Pay checkout was cancelled.");
    }
    redirect('/user/add-funds.php');
}

// 1.5 CASHFREE RETURN
if ($returnGateway === 'cashfree' && $action === 'return') {
    $orderId = trim($_GET['order_id'] ?? '');
    if (empty($orderId) && $depositId > 0) {
        $stmt = $db->prepare("SELECT gateway_txn_id FROM transactions WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $depositId]);
        $orderId = (string)$stmt->fetchColumn();
    }

    if (!empty($orderId)) {
        $orderCheck = CashfreeGatewayService::getOrder($orderId);
        if ($orderCheck['success'] && !empty($orderCheck['is_paid'])) {
            $res = fulfill_deposit_transaction($db, $depositId, $orderId, null, "Verified via Cashfree PG API");
            if ($res['success']) {
                set_flash('success', "Cashfree payment of " . format_currency($res['amount']) . " successfully verified and added to your wallet!");
            } else {
                set_flash('error', "Failed to credit wallet: " . $res['error']);
            }
        } else {
            $orderStatus = $orderCheck['order_status'] ?? 'PENDING';
            if (in_array($orderStatus, ['CANCELLED', 'EXPIRED', 'TERMINATED', 'FAILED'], true)) {
                $failStmt = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Cashfree Status: " . addslashes($orderStatus) . "]') WHERE id = :id AND status = 'pending'");
                $failStmt->execute(['id' => $depositId]);
                set_flash('error', "Cashfree payment was not completed (Status: {$orderStatus}).");
            } else {
                set_flash('info', "Cashfree payment is being processed. Your wallet will update as soon as confirmed.");
            }
        }
    }
    redirect('/user/add-funds.php');
}

// =========================================================================
// 2. CHECKOUT OVERLAY VIEWS (Razorpay & Cashfree SDK)
// =========================================================================
$checkoutMode = strtolower(trim($_GET['checkout'] ?? ''));
$checkoutDepositId = (int)($_GET['deposit_id'] ?? 0);

if ($checkoutMode === 'razorpay' && $checkoutDepositId > 0) {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND user_id = :uid AND status = 'pending' LIMIT 1");
    $stmt->execute(['id' => $checkoutDepositId, 'uid' => $user['id']]);
    $chkTxn = $stmt->fetch();

    if (!$chkTxn) {
        set_flash('error', "Active Razorpay checkout session not found.");
        redirect('/user/add-funds.php');
    }

    $rzpCfg = RazorpayGatewayService::getConfig();
    $rzpOrderId = (string)$chkTxn['gateway_txn_id'];
    $amountPaise = (int)round((float)$chkTxn['amount'] * 100);

    $pageTitle = "Complete Razorpay Checkout - " . app_name();
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="max-w-md mx-auto my-12 p-8 bg-white rounded-3xl border border-slate-200/80 shadow-xl text-center space-y-6">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-3xl shadow-sm">
            ⚡
        </div>
        <div>
            <h2 class="text-xl font-black text-slate-900">Complete Razorpay Payment</h2>
            <p class="text-xs text-slate-400 mt-1">Instant checkout supporting UPI, Cards, NetBanking &amp; Wallets</p>
        </div>
        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200/80 font-mono text-center">
            <span class="text-xs text-slate-500 uppercase tracking-wider block">Amount to Pay</span>
            <span class="text-3xl font-black text-slate-900 mt-1 block"><?= format_currency($chkTxn['amount']) ?></span>
            <span class="text-[11px] text-slate-400 mt-1 block">Order #<?= e($rzpOrderId) ?></span>
        </div>

        <form id="rzp-form" action="/user/add-funds.php?gateway=razorpay&action=verify&deposit_id=<?= $checkoutDepositId ?>" method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
            <input type="hidden" name="razorpay_order_id" id="razorpay_order_id">
            <input type="hidden" name="razorpay_signature" id="razorpay_signature">
            <button type="button" id="rzp-button" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 active:scale-98 text-white font-black text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all cursor-pointer">
                Pay Now with Razorpay &rarr;
            </button>
        </form>

        <a href="/user/add-funds.php" class="inline-block text-xs font-bold text-slate-400 hover:text-slate-600">
            &larr; Cancel and return to Add Funds
        </a>
    </div>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
    const options = {
        key: "<?= e($rzpCfg['key_id']) ?>",
        amount: <?= $amountPaise ?>,
        currency: "INR",
        name: "<?= addslashes(app_name()) ?>",
        description: "Deposit #<?= $checkoutDepositId ?> Wallet Credit",
        order_id: "<?= e($rzpOrderId) ?>",
        prefill: {
            name: "<?= addslashes($user['username']) ?>",
            email: "<?= addslashes($user['email']) ?>"
        },
        theme: {
            color: "#2563eb"
        },
        handler: function(response) {
            document.getElementById('razorpay_payment_id').value = response.razorpay_payment_id;
            document.getElementById('razorpay_order_id').value = response.razorpay_order_id;
            document.getElementById('razorpay_signature').value = response.razorpay_signature;
            document.getElementById('rzp-form').submit();
        },
        modal: {
            ondismiss: function() {
                // User closed popup
            }
        }
    };
    const rzp = new Razorpay(options);
    document.getElementById('rzp-button').onclick = function(e) {
        rzp.open();
        e.preventDefault();
    };
    // Automatically trigger Razorpay popup on load
    window.addEventListener('load', function() {
        rzp.open();
    });
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($checkoutMode === 'cashfree' && $checkoutDepositId > 0) {
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND user_id = :uid AND status = 'pending' LIMIT 1");
    $stmt->execute(['id' => $checkoutDepositId, 'uid' => $user['id']]);
    $chkTxn = $stmt->fetch();

    if (!$chkTxn) {
        set_flash('error', "Active Cashfree checkout session not found.");
        redirect('/user/add-funds.php');
    }

    $cfCfg = CashfreeGatewayService::getConfig();
    $sessionId = $_SESSION['cf_payment_session_' . $checkoutDepositId] ?? '';
    $orderId = (string)$chkTxn['gateway_txn_id'];
    $checkoutUrl = ($cfCfg['mode'] === 'production')
        ? 'https://payments.cashfree.com/forms/' . urlencode($sessionId)
        : 'https://payments-test.cashfree.com/forms/' . urlencode($sessionId);

    $pageTitle = "Complete Cashfree Checkout - " . app_name();
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="max-w-md mx-auto my-12 p-8 bg-white rounded-3xl border border-slate-200/80 shadow-xl text-center space-y-6">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-3xl shadow-sm">
            💳
        </div>
        <div>
            <h2 class="text-xl font-black text-slate-900">Cashfree Payments Checkout</h2>
            <p class="text-xs text-slate-400 mt-1">Official secure payment gateway for UPI, Cards &amp; NetBanking</p>
        </div>
        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200/80 font-mono text-center">
            <span class="text-xs text-slate-500 uppercase tracking-wider block">Deposit Amount</span>
            <span class="text-3xl font-black text-indigo-600 mt-1 block"><?= format_currency($chkTxn['amount']) ?></span>
            <span class="text-[11px] text-slate-400 mt-1 block">Order #<?= e($orderId) ?></span>
        </div>

        <div class="space-y-3">
            <button type="button" id="cf-pay-btn" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 active:scale-98 text-white font-black text-xs rounded-xl shadow-md shadow-indigo-500/25 transition-all cursor-pointer">
                Proceed to Cashfree Checkout &rarr;
            </button>
            <a href="<?= e($checkoutUrl) ?>" target="_blank" class="block text-xs font-bold text-indigo-600 hover:underline">
                Or open Cashfree hosted payment page in new tab &rarr;
            </a>
        </div>

        <a href="/user/add-funds.php" class="inline-block text-xs font-bold text-slate-400 hover:text-slate-600">
            &larr; Cancel and return to Add Funds
        </a>
    </div>

    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <script>
    const cashfree = Cashfree({
        mode: "<?= $cfCfg['mode'] === 'production' ? 'production' : 'sandbox' ?>"
    });
    function launchCashfree() {
        cashfree.checkout({
            paymentSessionId: "<?= e($sessionId) ?>",
            redirectTarget: "_self"
        });
    }
    document.getElementById('cf-pay-btn').onclick = launchCashfree;
    window.addEventListener('load', function() {
        setTimeout(launchCashfree, 500);
    });
    </script>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Fetch all enabled AND properly configured gateways directly from database
$availableGateways = get_payment_methods(true, true);

$error = null;
$success = null;

// Determine active tab
$defaultMethod = !empty($availableGateways) ? $availableGateways[0]['code'] : '';
$activeTab = $_GET['method'] ?? $defaultMethod;

$activeGatewayData = null;
foreach ($availableGateways as $gw) {
    if ($gw['code'] === $activeTab) {
        $activeGatewayData = $gw;
        break;
    }
}
if (!$activeGatewayData && !empty($availableGateways)) {
    $activeGatewayData = $availableGateways[0];
    $activeTab = $activeGatewayData['code'];
}

// =========================================================================
// 3. HANDLE DEPOSIT FORM SUBMISSION (GENUINE PROVIDER FLOWS)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $gatewayCode = strtolower(trim($_POST['gateway'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);

    $gateway = get_payment_method($gatewayCode);
    if (!$gateway || !is_gateway_active($gateway['status'] ?? '')) {
        $error = "This payment gateway is currently disabled or unavailable. Please choose another payment method.";
    } elseif (!is_gateway_configured($gateway)) {
        $error = "This payment method is currently undergoing configuration and is temporarily unavailable. Please choose another payment method or contact support.";
    } else {
        $min = (float)$gateway['min_amount'];
        $max = (float)$gateway['max_amount'];

        if ($amount < $min) {
            $error = "Minimum deposit amount for {$gateway['name']} is " . format_currency($min) . ".";
        } elseif ($amount > $max) {
            $error = "Maximum single deposit amount for {$gateway['name']} is " . format_currency($max) . ".";
        } else {
            $gatewayConfig = get_gateway_config($gateway);

            // -------------------------------------------------------------
            // A. RAZORPAY
            // -------------------------------------------------------------
            if ($gatewayCode === 'razorpay') {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'Razorpay', NULL, 'pending', 'Awaiting Razorpay checkout')
                ");
                $stmt->execute(['uid' => $user['id'], 'amt' => $amount]);
                $depositId = (int)$db->lastInsertId();

                $orderRes = RazorpayGatewayService::createOrder($amount, $depositId, $user);
                if ($orderRes['success']) {
                    $upd = $db->prepare("UPDATE transactions SET gateway_txn_id = :ord WHERE id = :id");
                    $upd->execute(['ord' => $orderRes['order_id'], 'id' => $depositId]);

                    redirect('/user/add-funds.php?checkout=razorpay&deposit_id=' . $depositId);
                } else {
                    $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Error: ', :err, ']') WHERE id = :id");
                    $upd->execute(['err' => substr($orderRes['error'], 0, 100), 'id' => $depositId]);
                    $error = $orderRes['error'];
                }
            }

            // -------------------------------------------------------------
            // B. PHONEPE PAYMENT GATEWAY
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'phonepe') {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'PhonePe', NULL, 'pending', 'Awaiting PhonePe PG payment')
                ");
                $stmt->execute(['uid' => $user['id'], 'amt' => $amount]);
                $depositId = (int)$db->lastInsertId();

                $initRes = PhonePeGatewayService::initiatePayment($amount, $depositId, $user);
                if ($initRes['success'] && !empty($initRes['checkout_url'])) {
                    $upd = $db->prepare("UPDATE transactions SET gateway_txn_id = :txnid WHERE id = :id");
                    $upd->execute(['txnid' => $initRes['merchant_txn_id'], 'id' => $depositId]);

                    redirect($initRes['checkout_url']);
                } else {
                    $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Error: ', :err, ']') WHERE id = :id");
                    $upd->execute(['err' => substr($initRes['error'], 0, 100), 'id' => $depositId]);
                    $error = "PhonePe PG initialization error: " . $initRes['error'];
                }
            }

            // -------------------------------------------------------------
            // C. PAYPAL
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'paypal') {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'PayPal', NULL, 'pending', 'Awaiting PayPal checkout confirmation')
                ");
                $stmt->execute(['uid' => $user['id'], 'amt' => $amount]);
                $depositId = (int)$db->lastInsertId();

                $orderRes = PayPalGatewayService::createOrder($amount, $depositId, 'USD');
                if ($orderRes['success'] && !empty($orderRes['checkout_url'])) {
                    $upd = $db->prepare("UPDATE transactions SET gateway_txn_id = :ord WHERE id = :id");
                    $upd->execute(['ord' => $orderRes['order_id'], 'id' => $depositId]);

                    redirect($orderRes['checkout_url']);
                } else {
                    $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Error: ', :err, ']') WHERE id = :id");
                    $upd->execute(['err' => substr($orderRes['error'], 0, 100), 'id' => $depositId]);
                    $error = "PayPal initialization error: " . $orderRes['error'];
                }
            }

            // -------------------------------------------------------------
            // D. BINANCE PAY
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'binance') {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'Binance Pay', NULL, 'pending', 'Awaiting Binance Pay crypto confirmation')
                ");
                $stmt->execute(['uid' => $user['id'], 'amt' => $amount]);
                $depositId = (int)$db->lastInsertId();

                $orderRes = BinancePayGatewayService::createOrder($amount, $depositId, 'USDT');
                if ($orderRes['success'] && !empty($orderRes['checkout_url'])) {
                    $upd = $db->prepare("UPDATE transactions SET gateway_txn_id = :trade WHERE id = :id");
                    $upd->execute(['trade' => $orderRes['merchant_trade_no'], 'id' => $depositId]);

                    redirect($orderRes['checkout_url']);
                } else {
                    $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Error: ', :err, ']') WHERE id = :id");
                    $upd->execute(['err' => substr($orderRes['error'], 0, 100), 'id' => $depositId]);
                    $error = "Binance Pay initialization error: " . $orderRes['error'];
                }
            }

            // -------------------------------------------------------------
            // E. CASHFREE PAYMENTS
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'cashfree') {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, 'Cashfree Payments', NULL, 'pending', 'Awaiting Cashfree PG completion')
                ");
                $stmt->execute(['uid' => $user['id'], 'amt' => $amount]);
                $depositId = (int)$db->lastInsertId();

                $orderRes = CashfreeGatewayService::createOrder($amount, $depositId, $user);
                if ($orderRes['success']) {
                    $upd = $db->prepare("UPDATE transactions SET gateway_txn_id = :ord WHERE id = :id");
                    $upd->execute(['ord' => $orderRes['order_id'], 'id' => $depositId]);

                    $_SESSION['cf_payment_session_' . $depositId] = $orderRes['payment_session_id'];

                    redirect('/user/add-funds.php?checkout=cashfree&deposit_id=' . $depositId);
                } else {
                    $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Error: ', :err, ']') WHERE id = :id");
                    $upd->execute(['err' => substr($orderRes['error'], 0, 100), 'id' => $depositId]);
                    $error = "Cashfree initialization error: " . $orderRes['error'];
                }
            }

            // -------------------------------------------------------------
            // F. PHONEPE BUSINESS QR (UTR Submission + Automated Gmail Verification)
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'phonepe_business_qr') {
                $utr = trim($_POST['utr_number'] ?? '');
                $senderNote = trim($_POST['sender_note'] ?? '');
                $cleanUtr = strtoupper(trim(preg_replace('/\s+/', '', $utr)));

                if (empty($cleanUtr) || strlen($cleanUtr) < 6) {
                    $error = "Please enter a valid 12-digit UPI Reference / UTR number from your payment receipt.";
                } else {
                    $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :utr AND status = 'completed' LIMIT 1");
                    $check->execute(['utr' => $cleanUtr]);
                    if ($check->fetch()) {
                        $error = "This UTR / Transaction reference (#{$cleanUtr}) has already been claimed.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                            VALUES (:uid, 'deposit', :amt, 'PhonePe Business QR', :txnid, 'pending', :note)
                        ");
                        $stmt->execute([
                            'uid' => $user['id'],
                            'amt' => $amount,
                            'txnid' => $cleanUtr,
                            'note' => "PhonePe QR Deposit (" . ($senderNote ?: "UTR: {$cleanUtr}") . ")"
                        ]);
                        $depositId = (int)$db->lastInsertId();

                        $autoVerify = (int)($gatewayConfig['auto_verify_on_submit'] ?? 1) === 1;
                        $hasGmail = !empty($gatewayConfig['gmail_email']) || !empty($gatewayConfig['gmail_client_id']);

                        if ($autoVerify && $hasGmail) {
                            require_once __DIR__ . '/../includes/gmail_verifier.php';
                            $verifier = new GmailPaymentVerifier($gatewayConfig);
                            $verifyRes = $verifier->verifyDeposit($db, $depositId, $cleanUtr, $amount);

                            if (!empty($verifyRes['success']) && !empty($verifyRes['credited'])) {
                                set_flash('success', "Payment of " . format_currency($amount) . " verified instantly via PhonePe! Credited to your wallet.");
                                redirect('/user/add-funds.php');
                            } elseif (($verifyRes['status'] ?? '') === 'duplicate_rejected') {
                                $error = "Verification failed: Duplicate UTR detected. This transaction was already claimed.";
                            } elseif (($verifyRes['status'] ?? '') === 'amount_mismatch') {
                                $error = $verifyRes['error'] ?? "Verification failed: Received amount does not match submitted deposit amount.";
                            } else {
                                set_flash('info', "Deposit request for " . format_currency($amount) . " submitted (UTR #{$cleanUtr}). Awaiting payment notification verification.");
                                redirect('/user/add-funds.php');
                            }
                        } else {
                            set_flash('success', "Deposit request for " . format_currency($amount) . " submitted! Your UTR #{$cleanUtr} has been queued for staff verification.");
                            redirect('/user/add-funds.php');
                        }
                    }
                }
            }

            // -------------------------------------------------------------
            // G. PAYTM QR (UTR Submission)
            // -------------------------------------------------------------
            elseif ($gatewayCode === 'paytm' || $gatewayCode === 'paytm_qr') {
                $utr = trim($_POST['utr_number'] ?? '');
                $senderNote = trim($_POST['sender_note'] ?? '');

                if (empty($utr) || strlen($utr) < 6) {
                    $error = "Please enter a valid 12-digit UTR number or bank transaction reference ID.";
                } else {
                    $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :utr AND status = 'completed' LIMIT 1");
                    $check->execute(['utr' => $utr]);
                    if ($check->fetch()) {
                        $error = "This UTR / Transaction ID has already been credited.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                            VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', :note)
                        ");
                        $stmt->execute([
                            'uid' => $user['id'],
                            'amt' => $amount,
                            'gw' => $gateway['name'],
                            'txnid' => $utr,
                            'note' => "Pending manual verification (" . ($senderNote ?: "UTR: {$utr}") . ")"
                        ]);

                        set_flash('success', "Deposit request of " . format_currency($amount) . " submitted! Your UTR #{$utr} is queued for admin verification.");
                        redirect('/user/add-funds.php');
                    }
                }
            }

            // -------------------------------------------------------------
            // H. GENERIC / MANUAL FALLBACK
            // -------------------------------------------------------------
            else {
                $ref = trim($_POST['reference_id'] ?? bin2hex(random_bytes(6)));
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', 'Pending manual review')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'amt' => $amount,
                    'gw' => $gateway['name'],
                    'txnid' => $ref
                ]);
                set_flash('success', "Deposit intent for " . format_currency($amount) . " submitted.");
                redirect('/user/add-funds.php');
            }
        }
    }
}

// Fetch user's recent deposits
$recentDepositsStmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = :uid AND type = 'deposit' 
    ORDER BY id DESC LIMIT 15
");
$recentDepositsStmt->execute(['uid' => $user['id']]);
$recentDeposits = $recentDepositsStmt->fetchAll();

$pageTitle = "Add Funds - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
    <!-- Header Back Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <a href="/user/transactions.php" class="text-xs font-bold text-blue-600 hover:underline">
            View Ledger History &rarr;
        </a>
    </div>

    <!-- Current Balance Callout Card -->
    <div class="p-6 rounded-3xl bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 text-white shadow-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Current Account Balance</span>
            <div class="text-3xl sm:text-4xl font-black font-mono-nums mt-1 text-emerald-300">
                <?= format_currency($user['balance']) ?>
            </div>
            <span class="text-[11px] text-slate-300 font-medium flex items-center gap-1.5 mt-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Instant Wallet Delivery
            </span>
        </div>
        <div class="text-left sm:text-right">
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Active Gateways</span>
            <span class="text-xs text-slate-200 block font-mono"><?= count($availableGateways) ?> Methods Operational</span>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Gateway Selector & Form Container -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <h2 class="text-lg font-black text-slate-900 tracking-tight">Select Deposit Gateway</h2>
            <p class="text-xs text-slate-400 mt-0.5">Only administrator-enabled and verified payment methods are displayed below</p>
        </div>

        <?php if (empty($availableGateways)): ?>
            <div class="p-10 text-center text-slate-500 space-y-3 bg-slate-50 rounded-2xl border border-slate-200">
                <div class="text-3xl">⚠️</div>
                <h3 class="text-sm font-extrabold text-slate-800">No Payment Gateways Currently Active</h3>
                <p class="text-xs text-slate-400 max-w-md mx-auto">
                    The administrator has not yet enabled any payment methods. Please open a support ticket or check back shortly.
                </p>
                <a href="/user/tickets.php" class="inline-flex px-4 py-2 bg-blue-600 text-white font-bold text-xs rounded-xl shadow-sm">
                    Contact Support &rarr;
                </a>
            </div>
        <?php else: ?>
            <!-- Dynamic Gateway Tabs (Strictly enabled & configured gateways only) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                <?php foreach ($availableGateways as $gw): 
                    $isSelected = ($gw['code'] === $activeTab);
                ?>
                    <button type="button" 
                            onclick="selectGateway('<?= e($gw['code']) ?>')" 
                            id="tab-<?= e($gw['code']) ?>" 
                            class="p-3.5 rounded-2xl border text-left transition-all gateway-tab <?= $isSelected ? 'bg-blue-50/70 border-blue-500 shadow-xs ring-2 ring-blue-500/20' : 'border-slate-200 hover:border-slate-300' ?>">
                        <span class="text-xl block mb-1"><?= e($gw['icon'] ?: '💳') ?></span>
                        <span class="text-xs font-black text-slate-900 block leading-tight truncate"><?= e($gw['name']) ?></span>
                        <span class="text-[10px] text-slate-500 font-bold block mt-0.5 font-mono">
                            <?= (float)$gw['fee_percent'] > 0 ? ((float)$gw['fee_percent'] . '% fee') : '0% Fee' ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Dynamic Gateway Form Panels -->
            <?php foreach ($availableGateways as $gw): 
                $isSelected = ($gw['code'] === $activeTab);
                $gwCfg = get_gateway_config($gw);
                $minG = (float)$gw['min_amount'];
                $maxG = (float)$gw['max_amount'];
            ?>
                <div id="method-<?= e($gw['code']) ?>" class="gateway-content space-y-6 pt-2 <?= $isSelected ? '' : 'hidden' ?>">
                    <!-- Gateway Header Info Box -->
                    <div class="p-5 rounded-2xl bg-gradient-to-br from-slate-50 to-blue-50/30 border border-slate-200/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-black uppercase text-blue-700 tracking-wider flex items-center gap-1.5">
                                <span><?= e($gw['icon'] ?: '💳') ?></span>
                                <span><?= e($gw['name']) ?> Checkout</span>
                            </span>
                            <span class="text-xs font-mono font-bold text-slate-600">
                                Limits: <?= format_currency($minG) ?> - <?= format_currency($maxG) ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-600 leading-relaxed">
                            <?= e($gw['instructions'] ?: 'Fast automated payment fulfillment.') ?>
                        </p>
                    </div>

                    <!-- Gateway Specific Form -->
                    <form action="/user/add-funds.php" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="gateway" value="<?= e($gw['code']) ?>">

                        <?php if ($gw['code'] === 'phonepe_business_qr'): ?>
                            <!-- PhonePe Business QR Visual Display + UTR Submission -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 p-5 rounded-2xl bg-gradient-to-br from-purple-50/50 via-slate-50 to-purple-50/30 border border-purple-100 items-center">
                                <div class="md:col-span-5 flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-purple-200 text-center shadow-xs">
                                    <?php if (!empty($gwCfg['qr_image_url'])): ?>
                                        <div class="p-2 bg-white rounded-xl border border-slate-200">
                                            <img src="<?= e($gwCfg['qr_image_url']) ?>" alt="PhonePe Business QR" class="w-48 h-48 object-contain rounded-lg">
                                        </div>
                                    <?php else: ?>
                                        <div class="w-48 h-48 rounded-xl border-2 border-dashed border-purple-300 flex flex-col items-center justify-center p-4 text-center bg-purple-50/40">
                                            <span class="text-3xl mb-1">🟣</span>
                                            <span class="text-xs font-black text-purple-900">PhonePe Business QR</span>
                                            <span class="text-[10px] text-slate-500 mt-1">Scan or pay to merchant UPI ID</span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($gwCfg['upi_id'])): ?>
                                        <div class="mt-3 flex items-center justify-between gap-2 px-3 py-1.5 bg-purple-50 border border-purple-200 rounded-lg w-full">
                                            <div class="text-left truncate">
                                                <span class="text-[9px] uppercase font-bold text-purple-600 block">UPI ID:</span>
                                                <span class="text-[11px] font-mono font-black text-purple-950 truncate block select-all"><?= e($gwCfg['upi_id']) ?></span>
                                            </div>
                                            <button type="button" onclick="navigator.clipboard.writeText('<?= addslashes($gwCfg['upi_id']) ?>'); alert('UPI ID copied to clipboard!');" class="text-[10px] font-bold text-purple-700 bg-white px-2 py-0.5 rounded border border-purple-200 hover:bg-purple-100 cursor-pointer shrink-0">
                                                Copy
                                            </button>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($gwCfg['merchant_id'])): ?>
                                        <span class="text-[10px] text-slate-400 font-mono mt-1 block">MID: <?= e($gwCfg['merchant_id']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="md:col-span-7 space-y-3">
                                    <h4 class="text-xs font-extrabold text-slate-900 flex items-center gap-1.5">
                                        <span>🟣</span> How to pay with PhonePe Business QR:
                                    </h4>
                                    <ol class="text-xs text-slate-600 space-y-2 list-decimal pl-4 leading-relaxed">
                                        <li>Open <strong>PhonePe, Google Pay, Paytm, BHIM</strong>, or any UPI banking app.</li>
                                        <li>Scan the official <strong>PhonePe Business QR</strong> code on the left (or transfer to UPI ID).</li>
                                        <li>Enter your deposit amount and complete the payment.</li>
                                        <li>Copy the <strong>12-digit UTR / UPI Reference Number</strong> from your payment receipt screen.</li>
                                        <li>Enter the exact amount and 12-digit UTR below for automated verification.</li>
                                    </ol>
                                    <div class="p-3 bg-purple-50/70 border border-purple-100 rounded-xl text-[11px] text-purple-900 leading-tight">
                                        ⚡ <strong>Automated Verification:</strong> Your payment reference will be verified against genuine transaction notifications received in our bank inbox for instant balance crediting.
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">12-Digit UTR / Reference ID <span class="text-rose-500">*</span></label>
                                    <input type="text" name="utr_number" required placeholder="e.g. 412389501234" maxlength="35" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">Sender UPI ID or Remark (Optional)</label>
                                <input type="text" name="sender_note" placeholder="e.g. yourname@upi" maxlength="100" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                            </div>
                        <?php elseif ($gw['code'] === 'paytm' || $gw['code'] === 'paytm_qr'): ?>
                            <!-- Paytm UPI QR Visual + UTR submission -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 p-5 rounded-2xl bg-slate-50 border border-slate-200/80 items-center">
                                <div class="md:col-span-4 flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-200 text-center shadow-xs">
                                    <div class="w-36 h-36 bg-slate-900 rounded-xl p-2 flex items-center justify-center text-white relative group">
                                        <svg class="w-32 h-32 text-white" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm10-2h8v8h-8V2zm2 2v4h4V4h-4zM2 14h8v8H2v-8zm2 2v4h4v-4H4zm13-2h3v3h-3v-3zm0 5h3v3h-3v-3zm-3-5h2v2h-2v-2zm0 4h2v2h-2v-2zm5-1h2v2h-2v-2z"/>
                                        </svg>
                                    </div>
                                    <span class="text-[11px] font-bold text-slate-500 mt-2 block">UPI ID: <?= e($gwCfg['upi_id'] ?? 'smmpanel@upi') ?></span>
                                </div>
                                <div class="md:col-span-8 space-y-2">
                                    <h4 class="text-xs font-extrabold text-slate-900">How to pay:</h4>
                                    <ol class="text-xs text-slate-600 space-y-1 list-decimal pl-4">
                                        <li>Scan QR or pay to UPI ID: <strong class="text-blue-600 font-mono"><?= e($gwCfg['upi_id'] ?? 'smmpanel@upi') ?></strong></li>
                                        <li>Copy the 12-digit UTR/Reference ID from the receipt.</li>
                                        <li>Submit below for instant verification.</li>
                                    </ol>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1">12-Digit UTR / Transaction ID <span class="text-rose-500">*</span></label>
                                    <input type="text" name="utr_number" required placeholder="e.g. 412389501234" maxlength="32" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Automated Online Checkout Input -->
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">Amount to Add (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                                <input type="number" step="1" min="<?= $minG ?>" max="<?= $maxG ?>" name="amount" required placeholder="<?= $minG ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-blue-500">
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all cursor-pointer">
                            Proceed via <?= e($gw['name']) ?> &rarr;
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- User Recent Deposit Ledger -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-base font-extrabold text-slate-900 tracking-tight">Recent Deposits History</h3>
                <p class="text-xs text-slate-400 mt-0.5">Live statement of your deposits</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($recentDeposits) ?> records</span>
        </div>

        <?php if (empty($recentDeposits)): ?>
            <div class="p-8 text-center text-slate-400 space-y-2">
                <div class="text-2xl">💳</div>
                <div class="text-xs font-bold text-slate-600">No deposit records found.</div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Gateway</th>
                            <th class="py-3 px-3">Reference / UTR</th>
                            <th class="py-3 px-3 text-right">Amount</th>
                            <th class="py-3 px-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($recentDeposits as $tx): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-3 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($tx['created_at'])) ?>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800">
                                    <?= e($tx['gateway']) ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-600 select-all">
                                    <?= e($tx['gateway_txn_id'] ?: '—') ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono-nums font-black text-slate-900">
                                    +<?= format_currency($tx['amount']) ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if ($tx['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Credited
                                        </span>
                                    <?php elseif ($tx['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⏳ Pending Review
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Failed / Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function selectGateway(method) {
    document.querySelectorAll('.gateway-tab').forEach(t => {
        t.classList.remove('bg-blue-50/70', 'border-blue-500', 'shadow-xs', 'ring-2', 'ring-blue-500/20');
        t.classList.add('border-slate-200');
    });
    const currentTab = document.getElementById('tab-' + method);
    if (currentTab) {
        currentTab.classList.add('bg-blue-50/70', 'border-blue-500', 'shadow-xs', 'ring-2', 'ring-blue-500/20');
        currentTab.classList.remove('border-slate-200');
    }
    document.querySelectorAll('.gateway-content').forEach(c => c.classList.add('hidden'));
    const currentContent = document.getElementById('method-' + method);
    if (currentContent) {
        currentContent.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
