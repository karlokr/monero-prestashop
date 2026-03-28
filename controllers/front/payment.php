<?php
/**
 * Monero Payment Front Controller — Ephemeral Payment Page
 *
 * Generates a unique subaddress via wallet-rpc, converts fiat to XMR,
 * creates an HMAC-signed token, and renders the payment page.
 *
 * ZERO data persistence: no cookies, no DB writes, no files.
 * All payment state lives in PHP variables (freed on request end)
 * and browser JS memory (freed on tab close).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/MoneroToken.php';
require_once dirname(__FILE__) . '/../../classes/MoneroHelper.php';

/**
 * Monero Payment Front Controller.
 *
 * Generates a unique subaddress via wallet-rpc, converts the cart total
 * from fiat to XMR atomic units, creates an HMAC-signed ephemeral token,
 * and renders the payment page template.
 *
 * ZERO data persistence: no cookies, no DB writes, no session, no files.
 * All payment state lives in PHP memory (freed on request end) and
 * browser JS memory (freed on tab close).
 */
class moneropaymentModuleFrontController extends ModuleFrontController
{
    /** @var bool Force SSL on the payment page */
    public $ssl = true;

    /** @var bool Hide the left column in the layout */
    public $display_column_left = false;

    /**
     * Initialize page content and render the payment form.
     *
     * Validates cart and customer state, checks RPC configuration and HMAC
     * key availability, connects to wallet-rpc, generates a fresh subaddress
     * (with empty label for privacy), converts the fiat total to XMR atomic
     * units via CryptoCompare, creates an HMAC token, and assigns all
     * template variables. On any failure, renders an error state instead.
     *
     * @see FrontController::initContent()
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $customer = $this->context->customer;

        // Validate: must have a valid cart, customer, and module config
        if (!Validate::isLoadedObject($customer) || !$cart->id || $cart->nbProducts() <= 0) {
            Tools::redirect('index.php?controller=order');
            return;
        }

        // Check RPC configuration
        if (!Configuration::get('MONERO_WALLET') || !Configuration::get('MONERO_RPC_USER') || !Configuration::get('MONERO_RPC_PASS')) {
            PrestaShopLogger::addLog('Payment: gateway not configured', 3);
            $this->renderError('Payments are not available at this time. Please contact the store.');
            return;
        }

        // Check HMAC key
        if (!MoneroToken::isKeyAvailable()) {
            PrestaShopLogger::addLog('Payment: signing key not configured', 3);
            $this->renderError('Payments are not available at this time. Please contact the store.');
            return;
        }

        // Connect to wallet-rpc
        try {
            $rpc = MoneroHelper::rpc();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Payment: gateway connection failed: ' . $e->getMessage(), 3);
            $this->renderError('Payment service is unavailable. Please try again later.');
            return;
        }

        // Convert fiat to XMR
        $currency = $this->context->currency;
        $total = $cart->getOrderTotal();

        try {
            $xmrAtomic = MoneroHelper::convertToXmr($total, $currency->iso_code);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Payment: currency conversion failed: ' . $e->getMessage(), 3);
            $this->renderError('Unable to calculate payment amount. Please try again later or choose a different payment method.');
            return;
        }

        if (bccomp($xmrAtomic, '0', 0) <= 0) {
            PrestaShopLogger::addLog('Payment: conversion returned zero amount', 3);
            $this->renderError('Unable to calculate payment amount. Please try again later.');
            return;
        }

        // Generate a new subaddress — empty label, no order/cart identifiers
        try {
            $subaddr = $rpc->generateSubaddress(0, '');

            if (!isset($subaddr['address']) || !isset($subaddr['address_index'])) {
                throw new RuntimeException('RPC returned invalid response — missing address or address_index');
            }

            $address = $subaddr['address'];
            $index = (int) $subaddr['address_index'];

            if ($index === 0) {
                throw new RuntimeException('RPC returned primary address index 0');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Payment: address generation failed: ' . $e->getMessage(), 3);
            $this->renderError('Unable to generate a payment address. Please try again later.');
            return;
        }

        // Create signed HMAC token — lives in browser JS memory only
        $token = MoneroToken::create($cart->id, $index, $xmrAtomic, time());

        // Human-readable XMR amount
        $xmrDisplay = MoneroHelper::formatXmr($xmrAtomic);

        // Monero URI for QR code
        $uri = 'monero:' . $address . '?tx_amount=' . $xmrDisplay;

        // Callback URL for AJAX polling
        $callbackUrl = $this->context->link->getModuleLink('monero', 'callback', [], true);

        // Assign to Smarty — these exist as PHP vars and HTML output only
        $this->context->smarty->assign([
            'monero_subaddress'     => $address,
            'monero_amount'         => $xmrDisplay,
            'monero_uri'            => $uri,
            'monero_token'          => $token,
            'monero_callback_url'   => $callbackUrl,
            'monero_status'         => 'awaiting',
            'monero_status_message' => 'Awaiting payment...',
        ]);

        $this->setTemplate('module:monero/views/templates/front/payment_execution.tpl');
    }

    /**
     * Render an error state on the payment page.
     *
     * Assigns empty/zeroed template variables and the given error message,
     * then renders the payment_execution template in error mode. The template
     * hides payment details and shows only the error alert.
     *
     * @param string $message Human-readable error message to display
     * @return void
     */
    private function renderError($message)
    {
        $this->context->smarty->assign([
            'monero_subaddress'     => '',
            'monero_amount'         => '0',
            'monero_uri'            => '',
            'monero_token'          => '',
            'monero_callback_url'   => '',
            'monero_status'         => 'error',
            'monero_status_message' => $message,
        ]);

        $this->setTemplate('module:monero/views/templates/front/payment_execution.tpl');
    }
}
