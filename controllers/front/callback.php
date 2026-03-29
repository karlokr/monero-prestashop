<?php
/**
 * Monero Callback Controller — AJAX Payment Verification Endpoint
 *
 * Called by the browser's fetch() loop every ~30 seconds.
 * Accepts a signed HMAC token (from JS memory), verifies it,
 * checks the blockchain for payment, and — if paid — creates the
 * PrestaShop order then immediately obfuscates all crypto identity
 * from the database so the order looks like a "Bank wire" payment.
 *
 * On successful payment, returns a JSON receipt — the ONLY record
 * linking order ↔ subaddress. The customer is responsible for saving it.
 *
 * ZERO persistent crypto data: nothing is written to disk/DB that
 * isn't immediately overwritten with generic payment references.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/MoneroToken.php';
require_once dirname(__FILE__) . '/../../classes/MoneroHelper.php';

/**
 * Monero Callback Controller — AJAX Payment Verification Endpoint.
 *
 * Called by the browser's fetch() polling loop every ~30 seconds.
 * Accepts a signed HMAC token (from JS memory), verifies signature and TTL,
 * checks the blockchain for payment via wallet-rpc, and — when payment is
 * confirmed — creates the PrestaShop order then immediately obfuscates all crypto
 * identity from the database so the order appears as a "Bank wire" payment.
 *
 * On successful payment, returns a JSON receipt — the ONLY record linking
 * order to subaddress. The customer is responsible for saving it.
 *
 * ZERO persistent crypto data: nothing is written to disk/DB that
 * isn't immediately overwritten with generic payment references.
 */
class monerocallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool Enable AJAX mode (disables template rendering) */
    public $ajax = true;

    /**
     * Handle POST requests from the payment page's AJAX polling loop.
     *
     * Workflow:
     *   1. Validate HTTP method (POST only)
     *   2. Extract and verify the HMAC token (signature + TTL)
     *   3. Validate cart ownership against current customer session
     *   4. Check if an order already exists for this cart
     *   5. Connect to wallet-rpc and query blockchain for payment
     *   6. Return status JSON: pending | confirming | paid | error
     *   7. On 'paid': create order via validateOrder(), obfuscate module identity
     *      to ps_wirepayment/"Bank wire", quantize+jitter timestamps,
     *      and return JSON receipt with order reference + subaddress
     *
     * @return void Outputs JSON and exits
     */
    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');

        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
            return;
        }

        // Read and verify token
        $token = Tools::getValue('token');
        if (empty($token)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Missing token'], 400);
            return;
        }

        $payload = MoneroToken::verify($token);
        if ($payload === false) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid or expired token'], 403);
            return;
        }

        $cartId = (int) $payload['cart_id'];
        $subaddrIndex = (int) $payload['index'];
        $xmrAmountAtomic = (string) $payload['amount'];

        // Validate cart belongs to the current customer
        $cart = new Cart($cartId);
        if (!Validate::isLoadedObject($cart)
            || (int) $cart->id_customer !== (int) $this->context->customer->id
            || $cart->nbProducts() <= 0
        ) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid cart'], 400);
            return;
        }

        // Check if an order already exists for this cart
        if ($cart->orderExists()) {
            $this->jsonResponse(['status' => 'completed', 'message' => 'Order already placed']);
            return;
        }

        // Connect to wallet-rpc
        try {
            $rpc = MoneroHelper::rpc();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Callback: RPC connection failed: ' . $e->getMessage(), 3);
            $this->jsonResponse(['status' => 'error', 'message' => 'Payment service unavailable'], 503);
            return;
        }

        // Required confirmations from config
        $requiredConfs = (int) Configuration::get('MONERO_CONFIRMATIONS');
        if ($requiredConfs < 0) {
            $requiredConfs = 0;
        }

        // Verify payment on the blockchain
        $result = MoneroHelper::verifyPayment($rpc, $subaddrIndex, $xmrAmountAtomic, $requiredConfs);

        switch ($result['status']) {
            case 'pending':
                $this->jsonResponse([
                    'status' => 'pending',
                    'message' => 'Awaiting payment...',
                    'received' => MoneroHelper::formatXmr($result['total_received']),
                    'expected' => MoneroHelper::formatXmr($xmrAmountAtomic),
                ]);
                return;

            case 'confirming':
                $this->jsonResponse([
                    'status' => 'confirming',
                    'message' => 'Payment detected — waiting for confirmations...',
                    'confirmations' => (int) $result['confirmations'],
                    'required' => $requiredConfs,
                    'received' => MoneroHelper::formatXmr($result['total_received']),
                    'expected' => MoneroHelper::formatXmr($xmrAmountAtomic),
                ]);
                return;

            case 'paid':
                // Payment confirmed — create the order, then obfuscate
                break;

            default:
                $this->jsonResponse(['status' => 'error', 'message' => 'Unknown status'], 500);
                return;
        }

        // ═══════════════════════════════════════════════════════════════
        // ORDER CREATION + IDENTITY OBFUSCATION
        // ═══════════════════════════════════════════════════════════════

        try {
            $module = Module::getInstanceByName('monero');
            $currency = new Currency((int) $cart->id_currency);
            $customer = new Customer((int) $cart->id_customer);
            $total = $cart->getOrderTotal();

            // Get the "Awaiting Payment Confirmation" order state
            // (created during module install) or fall back to PS_OS_PAYMENT
            $orderState = (int) Configuration::get('MONERO_OS_WAITING');
            if ($orderState <= 0) {
                $orderState = (int) Configuration::get('PS_OS_PAYMENT');
            }

            // Create the order — this writes to ps_orders, ps_order_payment, etc.
            // with module='monero' and payment='Pay with Monero (XMR)'
            $module->validateOrder(
                $cartId,
                $orderState,
                $total,
                'Bank wire',       // payment method string — already camouflaged
                null,              // message
                [],                // extra vars
                (int) $currency->id,
                false,             // don't stay on page
                $customer->secure_key
            );

            $orderId = (int) $module->currentOrder;

            if ($orderId <= 0) {
                throw new RuntimeException('validateOrder returned no order ID');
            }

            // ─── OBFUSCATE: overwrite module identity in database ───
            $db = Db::getInstance();
            $orderRef = Order::getUniqReferenceOf($orderId);

            // ─── OBFUSCATE: timestamp quantize + jitter ───
            // Quantize to nearest 6-hour bucket, then jitter ±3 hours.
            // This decouples the DB timestamp from the real payment time,
            // making timing correlation with on-chain transactions much harder.
            $bucketSeconds = 6 * 3600; // 6 hours
            $jitterMax = 3 * 3600;     // ±3 hours
            $now = time();
            $quantized = (int) round($now / $bucketSeconds) * $bucketSeconds;
            $jitter = random_int(-$jitterMax, $jitterMax);
            $obfuscated = $quantized + $jitter;
            // Clamp: don't let the obfuscated time land in the future
            if ($obfuscated > $now) {
                $obfuscated = $now - random_int(0, $jitterMax);
            }
            $obfuscatedDate = date('Y-m-d H:i:s', $obfuscated);

            // ps_orders: module identity + timestamp
            $db->update('orders', [
                'module' => 'ps_wirepayment',
                'payment' => 'Bank wire',
                'date_add' => $obfuscatedDate,
            ], 'id_order = ' . $orderId);

            // ps_order_payment: payment method + timestamp
            $db->update('order_payment', [
                'payment_method' => 'Bank wire',
                'date_add' => $obfuscatedDate,
            ], 'order_reference = \'' . pSQL($orderRef) . '\'');

            // ps_order_history: timestamp of the initial state entry
            $db->update('order_history', [
                'date_add' => $obfuscatedDate,
            ], 'id_order = ' . $orderId);

            // Get the order reference for the receipt
            $order = new Order($orderId);
            $orderReference = $order->reference;

            // Get the subaddress string for the receipt
            $subaddressStr = '';
            try {
                $addrResult = $rpc->resolveSubaddress(0, $subaddrIndex);
                if (isset($addrResult['address'])) {
                    $subaddressStr = $addrResult['address'];
                }
            } catch (Exception $e) {
                // Non-critical — receipt will just miss the address
                PrestaShopLogger::addLog('Callback: could not fetch subaddress for receipt: ' . $e->getMessage(), 2);
            }

            // Build overpayment notice
            $overpaid = $result['overpaid'];
            $overpaymentXmr = '0';
            if ($overpaid) {
                $overpaymentXmr = MoneroHelper::formatXmr(
                    bcsub($result['confirmed_received'], $xmrAmountAtomic, 0)
                );
            }

            // Order confirmation URL (generic PrestaShop confirmation page)
            $confirmUrl = $this->context->link->getPageLink(
                'order-confirmation',
                true,
                null,
                [
                    'id_cart' => $cartId,
                    'id_module' => $module->id,
                    'id_order' => $orderId,
                    'key' => $customer->secure_key,
                ]
            );

            // Build canonical receipt text for signing
            $receiptLines = [
                'Order Reference : ' . $orderReference,
                'Subaddress      : ' . $subaddressStr,
                'XMR Expected    : ' . MoneroHelper::formatXmr($xmrAmountAtomic) . ' XMR',
                'XMR Received    : ' . MoneroHelper::formatXmr($result['confirmed_received']) . ' XMR',
                'Timestamp       : ' . date('Y-m-d H:i:s T'),
            ];
            $receiptText = implode("\n", $receiptLines);
            $receiptSignature = MoneroToken::signReceipt($receiptText);

            // Return receipt — this is the ONLY record linking order ↔ crypto
            $this->jsonResponse([
                'status' => 'paid',
                'message' => 'Payment confirmed!',
                'receipt' => [
                    'order_reference' => $orderReference,
                    'subaddress' => $subaddressStr,
                    'xmr_expected' => MoneroHelper::formatXmr($xmrAmountAtomic),
                    'xmr_received' => MoneroHelper::formatXmr($result['confirmed_received']),
                    'overpaid' => $overpaid,
                    'overpayment_xmr' => $overpaymentXmr,
                    'timestamp' => date('Y-m-d H:i:s T'),
                    'signature' => $receiptSignature,
                ],
                'redirect_url' => $confirmUrl,
            ]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Callback: order creation failed: ' . $e->getMessage(), 3);
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Order creation failed. Your payment has been received — please contact support.',
            ], 500);
        }
    }

    /**
     * Send a JSON response and terminate.
     *
     * @param array $data       Response data
     * @param int   $httpCode   HTTP status code
     */
    private function jsonResponse(array $data, $httpCode = 200)
    {
        http_response_code($httpCode);
        echo json_encode($data);
        exit;
    }
}
