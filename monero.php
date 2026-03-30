<?php
/**
 * Monero Payment Module for PrestaShop
 *
 * Zero-knowledge ephemeral payment architecture:
 *   - No crypto data persists in the database
 *   - Orders are obfuscated to look like "Bank wire" payments
 *   - HMAC tokens live only in browser JS memory
 *   - Wallet subaddress labels are always empty
 *   - Customer-side receipt is the only order-to-subaddress link
 *
 * @author karlokr
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class monero extends PaymentModule
{
    /** @var string Accumulated HTML output for the admin configuration page */
    private $_html = '';

    /** @var array Validation errors collected during admin configuration form submission */
    private $_postErrors = array();

    /**
     * Module constructor.
     *
     * Sets module metadata (name, version, tab, PS version compatibility),
     * calls parent constructor, and configures display strings.
     * Emits a warning if wallet RPC credentials are not yet configured.
     */
    public function __construct()
    {
        $this->name = 'monero';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'karlokr';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
        $this->controllers = array('payment', 'callback');

        parent::__construct();

        $this->displayName = $this->trans('Monero Payments', array(), 'Modules.Monero.Admin');
        $this->description = $this->trans('Accept payments in Monero (XMR) cryptocurrency', array(), 'Modules.Monero.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall the Monero payment module?', array(), 'Modules.Monero.Admin');

        if (!Configuration::get('MONERO_WALLET') || !Configuration::get('MONERO_RPC_USER') || !Configuration::get('MONERO_RPC_PASS')) {
            $this->warning = $this->trans('Monero Wallet RPC host and RPC credentials must be configured before accepting payments.', array(), 'Modules.Monero.Admin');
        }
    }

    /**
     * Install the module.
     *
     * Checks for required PHP extensions (cURL, bcmath), auto-generates
     * the HMAC signing key, sets default configuration values, creates
     * the "Awaiting Payment Confirmation" order state, registers hooks
     * for paymentOptions and actionFrontControllerSetMedia.
     *
     * NOTE: paymentReturn and displayPDFInvoice hooks are intentionally
     * NOT registered to avoid leaking payment identity.
     *
     * @return bool True on successful installation
     */
    public function install()
    {
        if (!function_exists('curl_version')) {
            $this->_errors[] = $this->trans('This module requires the cURL PHP extension which is not enabled on your server.', array(), 'Modules.Monero.Admin');
            return false;
        }

        if (!function_exists('bcadd')) {
            $this->_errors[] = $this->trans('This module requires the bcmath PHP extension which is not enabled on your server.', array(), 'Modules.Monero.Admin');
            return false;
        }

        // Auto-generate HMAC signing key (64-char hex = 32 bytes entropy)
        if (!Configuration::get('MONERO_HMAC_KEY')) {
            Configuration::updateValue('MONERO_HMAC_KEY', bin2hex(random_bytes(32)));
        }

        // Default config values
        if (!Configuration::get('MONERO_CONFIRMATIONS')) {
            Configuration::updateValue('MONERO_CONFIRMATIONS', '1');
        }
        if (!Configuration::get('MONERO_RATE_CACHE_TTL')) {
            Configuration::updateValue('MONERO_RATE_CACHE_TTL', '300');
        }
        if (!Configuration::get('MONERO_TOKEN_TTL')) {
            Configuration::updateValue('MONERO_TOKEN_TTL', '1800');
        }

        // Create custom order state: "Awaiting Payment Confirmation"
        $this->createOrderState();

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('actionFrontControllerSetMedia');
        // NOTE: paymentReturn and displayPDFInvoice hooks are intentionally
        // NOT registered — they would leak payment identity on confirmation
        // pages and invoices. Orders are obfuscated to look like "Bank wire" so
        // PrestaShop's built-in wire payment hooks handle display.
    }

    /**
     * Uninstall the module.
     *
     * Removes all module-specific configuration values from ps_configuration,
     * including RPC credentials, HMAC key, rate cache, and order state ID.
     *
     * @return bool True on successful uninstallation
     */
    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('MONERO_WALLET')
            && Configuration::deleteByName('MONERO_RPC_USER')
            && Configuration::deleteByName('MONERO_RPC_PASS')
            && Configuration::deleteByName('MONERO_HMAC_KEY')
            && Configuration::deleteByName('MONERO_CONFIRMATIONS')
            && Configuration::deleteByName('MONERO_RATE_CACHE_TTL')
            && Configuration::deleteByName('MONERO_RATE_CACHE')
            && Configuration::deleteByName('MONERO_TOKEN_TTL')
            && Configuration::deleteByName('MONERO_OS_WAITING');
    }

    /**
     * Create the "Awaiting Payment Confirmation" order state.
     * Used as the initial state for new orders before blockchain confirms.
     */
    private function createOrderState()
    {
        $existingId = (int) Configuration::get('MONERO_OS_WAITING');
        if ($existingId > 0) {
            $existing = new OrderState($existingId);
            if (Validate::isLoadedObject($existing)) {
                return; // already exists
            }
        }

        $orderState = new OrderState();
        $orderState->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $orderState->name[$lang['id_lang']] = 'Awaiting Payment Confirmation';
        }
        $orderState->send_email = false;
        $orderState->color = '#4169E1';
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = false;
        $orderState->invoice = false;
        $orderState->module_name = $this->name;

        if ($orderState->add()) {
            Configuration::updateValue('MONERO_OS_WAITING', (int) $orderState->id);
        }
    }

    /**
     * Hook: paymentOptions — register Monero as a checkout payment option.
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }

        if (!Configuration::get('MONERO_WALLET') || !Configuration::get('MONERO_RPC_USER') || !Configuration::get('MONERO_RPC_PASS')) {
            return [];
        }

        // Require HMAC key to be available
        require_once dirname(__FILE__) . '/classes/MoneroToken.php';
        if (!MoneroToken::isKeyAvailable()) {
            return [];
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay with Monero (XMR)', array(), 'Modules.Monero.Shop'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setAdditionalInformation(
                $this->fetch('module:monero/views/templates/front/payment_infos.tpl')
            );

        $logoPath = $this->getPathUri() . 'views/img/monero-logo.png';
        $paymentOption->setLogo($logoPath);

        return [$paymentOption];
    }

    /**
     * Hook: actionFrontControllerSetMedia — register front-end stylesheets.
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        $this->context->controller->registerStylesheet(
            'module-monero-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 200]
        );
    }

    /**
     * Render the admin module configuration page.
     *
     * Handles form submission: validates RPC host, username, password,
     * confirmations, rate cache TTL, and token TTL. Persists valid
     * values to ps_configuration. Returns the rendered HelperForm HTML.
     *
     * @return string HTML output for the admin configuration page
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $monero_wallet = (string) Tools::getValue('MONERO_WALLET');
            $rpc_user = (string) Tools::getValue('MONERO_RPC_USER');
            $rpc_pass = (string) Tools::getValue('MONERO_RPC_PASS');
            $confirmations = (int) Tools::getValue('MONERO_CONFIRMATIONS');
            $rateTtl = (int) Tools::getValue('MONERO_RATE_CACHE_TTL');
            $tokenTtl = (int) Tools::getValue('MONERO_TOKEN_TTL');

            if (!$monero_wallet || empty($monero_wallet)) {
                $output .= $this->displayError($this->trans('Invalid Monero Wallet RPC host.', array(), 'Modules.Monero.Admin'));
            } elseif (empty($rpc_user) || empty($rpc_pass)) {
                $output .= $this->displayError($this->trans('RPC username and password are required.', array(), 'Modules.Monero.Admin'));
            } else {
                Configuration::updateValue('MONERO_WALLET', $monero_wallet);
                Configuration::updateValue('MONERO_RPC_USER', $rpc_user);
                Configuration::updateValue('MONERO_RPC_PASS', $rpc_pass);
                Configuration::updateValue('MONERO_CONFIRMATIONS', max(0, $confirmations));
                Configuration::updateValue('MONERO_RATE_CACHE_TTL', max(60, $rateTtl));
                Configuration::updateValue('MONERO_TOKEN_TTL', max(300, $tokenTtl));
                $output .= $this->displayConfirmation($this->trans('Settings updated successfully.', array(), 'Modules.Monero.Admin'));
            }
        }

        return $output . $this->displayForm();
    }

    /**
     * Build and render the HelperForm for module settings.
     *
     * Defines form fields for: Monero wallet RPC host, RPC username,
     * RPC password, required confirmations, exchange rate cache TTL,
     * and payment token TTL. Pre-populates field values from ps_configuration.
     *
     * @return string Rendered HTML form
     */
    public function displayForm()
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->trans('Monero Payment Settings', array(), 'Modules.Monero.Admin'),
                'icon' => 'icon-cogs',
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->trans('Monero Wallet RPC Host', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_WALLET',
                    'desc' => $this->trans('The host:port of your monero-wallet-rpc instance (e.g., http://monero-wallet-rpc:18082).', array(), 'Modules.Monero.Admin'),
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->trans('RPC Username', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_RPC_USER',
                    'desc' => $this->trans('Username for monero-wallet-rpc digest authentication.', array(), 'Modules.Monero.Admin'),
                    'required' => true,
                ),
                array(
                    'type' => 'password',
                    'label' => $this->trans('RPC Password', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_RPC_PASS',
                    'desc' => $this->trans('Password for monero-wallet-rpc digest authentication.', array(), 'Modules.Monero.Admin'),
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->trans('Required Confirmations', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_CONFIRMATIONS',
                    'desc' => $this->trans('Number of blockchain confirmations required before accepting payment (0 = accept unconfirmed/mempool).', array(), 'Modules.Monero.Admin'),
                    'required' => false,
                    'class' => 'fixed-width-sm',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->trans('Exchange Rate Cache TTL (seconds)', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_RATE_CACHE_TTL',
                    'desc' => $this->trans('How long to cache the XMR exchange rate (minimum 60 seconds). Default: 300.', array(), 'Modules.Monero.Admin'),
                    'required' => false,
                    'class' => 'fixed-width-sm',
                ),
                array(
                    'type' => 'text',
                    'label' => $this->trans('Payment Token TTL (seconds)', array(), 'Modules.Monero.Admin'),
                    'name' => 'MONERO_TOKEN_TTL',
                    'desc' => $this->trans('How long an HMAC payment token remains valid (minimum 300 seconds). Default: 1800 (30 min).', array(), 'Modules.Monero.Admin'),
                    'required' => false,
                    'class' => 'fixed-width-sm',
                ),
            ),
            'submit' => array(
                'title' => $this->trans('Save', array(), 'Admin.Actions'),
                'class' => 'btn btn-default pull-right',
            ),
        );

        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->trans('Save', array(), 'Admin.Actions'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->trans('Back to list', array(), 'Admin.Actions'),
            ),
        );

        $helper->fields_value['MONERO_WALLET'] = Configuration::get('MONERO_WALLET');
        $helper->fields_value['MONERO_RPC_USER'] = Configuration::get('MONERO_RPC_USER');
        $helper->fields_value['MONERO_RPC_PASS'] = Configuration::get('MONERO_RPC_PASS');
        $helper->fields_value['MONERO_CONFIRMATIONS'] = Configuration::get('MONERO_CONFIRMATIONS');
        $helper->fields_value['MONERO_RATE_CACHE_TTL'] = Configuration::get('MONERO_RATE_CACHE_TTL');
        $helper->fields_value['MONERO_TOKEN_TTL'] = Configuration::get('MONERO_TOKEN_TTL');

        return $helper->generateForm($fields_form);
    }
}
