<?php
/**
 * 1pay.cx for PrestaShop — accept card, Bitcoin and Monero payments.
 *
 * Flow: checkout -> "Pay by card or crypto" -> controllers/front/redirect.php
 * creates the payment on 1pay.cx and sends the buyer to the hosted checkout ->
 * 1pay.cx POSTs a signed notify to controllers/front/webhook.php -> the order is
 * validated (Payment accepted). The return page (controllers/front/return.php)
 * polls the payment as a fallback so a delayed notify never strands the buyer.
 *
 * All outbound traffic goes to https://api.1pay.cx and https://1pay.cx only.
 * The API key and webhook secret are stored in Configuration and never logged.
 *
 * Targets PrestaShop 1.7.x and 8.x, PHP 7.4 – 8.1.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/OnepaycxApi.php';

class Onepaycx extends PaymentModule
{
    const VERSION = '1.1.0';

    const CFG_API_KEY = 'ONEPAYCX_API_KEY';
    const CFG_WEBHOOK_SECRET = 'ONEPAYCX_WEBHOOK_SECRET';
    const CFG_MERCHANT_ID = 'ONEPAYCX_MERCHANT_ID';
    const CFG_TITLE = 'ONEPAYCX_TITLE';

    const TABLE = 'onepaycx_payment';

    /**
     * Fixed value written to Order::$payment. The merchant-configurable title is
     * display-only: Order validates `payment` with Validate::isGenericName, so a
     * free-form title must never reach validateOrder().
     */
    const PAYMENT_METHOD_NAME = '1pay.cx';

    /** Default checkout title (also the fallback when the stored one is empty). */
    const DEFAULT_TITLE = 'Pay by card or crypto';

    /** @var array<string,string> */
    private $postErrors = array();

    public function __construct()
    {
        $this->name = 'onepaycx';
        $this->tab = 'payments_gateways';
        $this->version = self::VERSION;
        $this->author = '1pay.cx';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = array('redirect', 'webhook', 'return');
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('1pay.cx — pay by card or crypto');
        $this->description = $this->l('Accept card, Bitcoin and Monero payments. Buyers pay on a hosted checkout; orders are confirmed automatically by a signed webhook. Paste your API key to go live.');
        $this->confirmUninstall = $this->l('Remove 1pay.cx? Orders already placed are kept; only the module settings are deleted.');
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                 */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        if (!function_exists('curl_init')) {
            $this->_errors[] = $this->l('The PHP cURL extension is required.');

            return false;
        }

        return parent::install()
            && $this->installTable()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && Configuration::updateValue(self::CFG_TITLE, self::DEFAULT_TITLE);
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CFG_API_KEY);
        Configuration::deleteByName(self::CFG_WEBHOOK_SECRET);
        Configuration::deleteByName(self::CFG_MERCHANT_ID);
        Configuration::deleteByName(self::CFG_TITLE);

        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`')
            && parent::uninstall();
    }

    private function installTable()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';

        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_payment` VARCHAR(64) NOT NULL,
                `id_cart` INT UNSIGNED NOT NULL,
                `amount` VARCHAR(32) NOT NULL,
                `currency` VARCHAR(3) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_payment`),
                KEY `id_cart` (`id_cart`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Configuration page                                                  */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitOnepaycx')) {
            $this->postProcessConfig();
            foreach ($this->postErrors as $err) {
                $output .= $this->displayError($err);
            }
            if (empty($this->postErrors)) {
                $output .= $this->displayConfirmation($this->l('Settings saved. API key verified — you are ready to accept payments.'));
            }
        }

        $connected = Configuration::get(self::CFG_WEBHOOK_SECRET) ? true : false;
        $this->context->smarty->assign(array(
            'onepaycx_connected' => $connected,
            'onepaycx_merchant_id' => (string) Configuration::get(self::CFG_MERCHANT_ID),
            'onepaycx_webhook_url' => $this->context->link->getModuleLink($this->name, 'webhook', array(), true),
        ));
        $output .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
    }

    /**
     * Save settings. A new API key is validated against GET /v1/me and the
     * webhook secret it returns is cached; an empty key field keeps the current key.
     */
    private function postProcessConfig()
    {
        $this->postErrors = array();

        $title = trim((string) Tools::getValue(self::CFG_TITLE));
        if ($title === '') {
            $title = self::DEFAULT_TITLE;
        }
        if (!self::isValidTitle($title)) {
            $this->postErrors[] = $this->l('The checkout title may not contain < > = { } and must be at most 255 characters.');
        } else {
            Configuration::updateValue(self::CFG_TITLE, $title);
        }

        $key = trim((string) Tools::getValue(self::CFG_API_KEY));
        if ($key === '') {
            // Password fields post empty when untouched — keep the stored key.
            if (!Configuration::get(self::CFG_API_KEY)) {
                $this->postErrors[] = $this->l('Enter your 1pay.cx API key (from the dashboard: Settings -> API keys).');
            }

            return;
        }

        if (!preg_match('/^[A-Za-z0-9_.\-]{8,200}$/', $key)) {
            $this->postErrors[] = $this->l('That does not look like a 1pay.cx API key. It looks like pk_xxx.yyy.');

            return;
        }

        $res = $this->makeApi($key)->me();
        if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['webhookSecret'])) {
            $this->postErrors[] = sprintf(
                $this->l('Could not verify the API key (%s). Double-check it in your 1pay.cx dashboard.'),
                $res['error'] !== '' ? $res['error'] : $this->l('unknown error')
            );

            return;
        }

        Configuration::updateValue(self::CFG_API_KEY, $key);
        Configuration::updateValue(self::CFG_WEBHOOK_SECRET, (string) $res['data']['webhookSecret']);
        Configuration::updateValue(self::CFG_MERCHANT_ID, isset($res['data']['id']) ? (string) $res['data']['id'] : '');
    }

    private function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('1pay.cx settings'),
                    'icon' => 'icon-credit-card',
                ),
                'input' => array(
                    array(
                        'type' => 'password',
                        'label' => $this->l('API key'),
                        'name' => self::CFG_API_KEY,
                        'desc' => $this->l('From your 1pay.cx dashboard: Settings -> API keys. Looks like pk_xxx.yyy. Leave blank to keep the current key.'),
                        'autocomplete' => false,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title at checkout'),
                        'name' => self::CFG_TITLE,
                        'desc' => $this->l('What buyers see as the payment option.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitOnepaycx';
        $helper->fields_value = array(
            // Never echo the stored key back into the page.
            self::CFG_API_KEY => '',
            self::CFG_TITLE => (string) Configuration::get(self::CFG_TITLE),
        );

        return $helper->generateForm(array($fields_form));
    }

    /* ------------------------------------------------------------------ */
    /* Hooks                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Offer "Pay by card or crypto" on the checkout payment step.
     *
     * @param array $params ['cart' => Cart]
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->isConfigured()) {
            return null;
        }
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        if (!$cart || !$this->checkCurrency($cart)) {
            return null;
        }
        if (!class_exists('PrestaShop\\PrestaShop\\Core\\Payment\\PaymentOption')) {
            return null;
        }

        $this->context->smarty->assign(array(
            'onepaycx_info' => $this->l('Pay securely by card, Bitcoin or Monero. You will be redirected to complete your payment.'),
        ));

        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setModuleName($this->name);
        $option->setCallToActionText($this->paymentTitle());
        $option->setAction($this->context->link->getModuleLink($this->name, 'redirect', array(), true));
        $option->setAdditionalInformation($this->fetch('module:onepaycx/views/templates/hook/payment_info.tpl'));
        if (class_exists('Media') && defined('_PS_MODULE_DIR_') && file_exists(_PS_MODULE_DIR_ . $this->name . '/logo.png')) {
            $option->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'));
        }

        return array($option);
    }

    /**
     * Order-confirmation page block for orders paid through this module.
     *
     * @param array $params ['order' => Order] (1.7+) or ['objOrder' => Order]
     *
     * @return string
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }
        $order = isset($params['order']) ? $params['order'] : (isset($params['objOrder']) ? $params['objOrder'] : null);
        if (!$order || !isset($order->module) || $order->module !== $this->name) {
            return '';
        }

        $paid = isset($order->valid) ? (bool) $order->valid : false;
        $this->context->smarty->assign(array(
            'onepaycx_paid' => $paid,
            'onepaycx_reference' => isset($order->reference) ? (string) $order->reference : '',
            'onepaycx_shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
        ));

        return $this->fetch('module:onepaycx/views/templates/hook/payment_return.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers shared by the controllers                                   */
    /* ------------------------------------------------------------------ */

    /** True once an API key has been verified and the webhook secret cached. */
    public function isConfigured()
    {
        return (bool) Configuration::get(self::CFG_API_KEY) && (bool) Configuration::get(self::CFG_WEBHOOK_SECRET);
    }

    public function paymentTitle()
    {
        $title = (string) Configuration::get(self::CFG_TITLE);

        return $title !== '' && self::isValidTitle($title) ? $title : $this->l('Pay by card or crypto');
    }

    /**
     * A checkout title is display-only, but it is still kept to what PrestaShop's
     * own isGenericName accepts (no < > = { }) and 255 characters.
     *
     * @param string $title
     *
     * @return bool
     */
    public static function isValidTitle($title)
    {
        $title = (string) $title;
        if ($title === '' || strlen($title) > 255) {
            return false;
        }
        if (class_exists('Validate') && method_exists('Validate', 'isGenericName')) {
            return (bool) Validate::isGenericName($title);
        }

        return (bool) preg_match('/^[^<>={}]*$/u', $title);
    }

    public function api()
    {
        return $this->makeApi((string) Configuration::get(self::CFG_API_KEY));
    }

    /**
     * Single place that builds the HTTP client (overridable, e.g. by the compile
     * harness, so no code path talks to the network during tests).
     *
     * @param string $key
     *
     * @return OnepaycxApi
     */
    protected function makeApi($key)
    {
        return new OnepaycxApi($key);
    }

    /**
     * The module accepts a cart's currency only if it is enabled for this module
     * (Payment -> Preferences -> currency restrictions).
     *
     * @param Cart $cart
     *
     * @return bool
     */
    public function checkCurrency($cart)
    {
        $currencyOrder = new Currency((int) $cart->id_currency);
        $currenciesModule = $this->getCurrency((int) $cart->id_currency);
        if (is_array($currenciesModule)) {
            foreach ($currenciesModule as $currencyModule) {
                if ((int) $currencyOrder->id === (int) $currencyModule['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Format a cart total as a two-decimal string ("12.50").
     *
     * @param float|int|string $amount
     *
     * @return string
     */
    public static function formatAmount($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * Constant-time check of X-1pay-Signature: hex HMAC-SHA256 of the RAW body.
     *
     * @param string $rawBody
     * @param string $signature
     * @param string $secret
     *
     * @return bool
     */
    public static function verifySignature($rawBody, $signature, $secret)
    {
        $signature = strtolower(trim((string) $signature));
        $secret = (string) $secret;
        if ($secret === '' || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', (string) $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /** Maximum age (either direction) of a timestamped X-1pay-Signature-V2, in seconds. */
    const SIGNATURE_TOLERANCE = 300;

    /**
     * Constant-time check of X-1pay-Signature-V2: "t=<unix>,v1=<hex>" where v1 is
     * the hex HMAC-SHA256 of "<t>.<raw body>". The timestamp must be within
     * SIGNATURE_TOLERANCE seconds of $now so a captured notify cannot be replayed.
     *
     * @param string $rawBody
     * @param string $header
     * @param string $secret
     * @param int|null $now
     *
     * @return bool
     */
    public static function verifySignatureV2($rawBody, $header, $secret, $now = null)
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return false;
        }
        $t = null;
        $v1 = array();
        foreach (explode(',', (string) $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && preg_match('/^[0-9]{1,12}$/', $kv[1])) {
                $t = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1[] = strtolower($kv[1]);
            }
        }
        if ($t === null || count($v1) === 0) {
            return false;
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $t) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . (string) $rawBody, $secret);
        $ok = false;
        foreach ($v1 as $candidate) {
            if (preg_match('/^[0-9a-f]{64}$/', $candidate) && hash_equals($expected, $candidate)) {
                $ok = true;
            }
        }

        return $ok;
    }

    /**
     * Verify a notify: the timestamped V2 header when present (it then decides
     * alone, no fallback), otherwise the legacy body-only signature.
     *
     * @return bool
     */
    public static function verifyWebhook($rawBody, $v2Header, $legacyHeader, $secret, $now = null)
    {
        if (trim((string) $v2Header) !== '') {
            return self::verifySignatureV2($rawBody, $v2Header, $secret, $now);
        }

        return (string) $legacyHeader !== '' && self::verifySignature($rawBody, $legacyHeader, $secret);
    }

    /**
     * Test keys are pk_test_...; everything else is a live key.
     *
     * @param string $key
     *
     * @return bool
     */
    public static function isTestKey($key)
    {
        return strpos((string) $key, 'pk_test_') === 0;
    }

    /**
     * Strict form of reportedAmountCovers() for the webhook: amount and currency
     * must both be present, the currency equal, and the amount no lower than the
     * quote minus 0.01 (a customer surcharge on top is fine).
     *
     * @return bool
     */
    public static function coversQuote($amount, $currency, $quotedAmount, $quotedCurrency)
    {
        if (!is_numeric($amount) || !is_string($currency)) {
            return false;
        }
        if (strtoupper($currency) !== strtoupper((string) $quotedCurrency)) {
            return false;
        }

        return (int) round((float) $amount * 100) >= (int) round((float) $quotedAmount * 100) - 1;
    }

    /**
     * Remember which 1pay.cx payment belongs to a cart. One row per payment: a
     * buyer who restarts checkout gets a second link, and both stay payable for
     * up to 24 hours, so every one of them must be able to complete the order.
     *
     * @return bool false when the row could not be written (the payment must not
     *              be started then, or it could never be confirmed)
     */
    public function savePayment($idCart, $paymentId, $amount, $currency)
    {
        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
                (`id_payment`, `id_cart`, `amount`, `currency`, `status`, `date_add`, `date_upd`)
             VALUES (\''
                . pSQL((string) $paymentId) . '\', '
                . (int) $idCart . ', \''
                . pSQL(self::formatAmount($amount)) . '\', \''
                . pSQL(strtoupper((string) $currency)) . '\', \'pending\', \''
                . pSQL($now) . '\', \'' . pSQL($now) . '\')'
        );
    }

    /**
     * @param string $paymentId
     *
     * @return array|null row with id_payment, id_cart, amount, currency, status
     */
    public function getPaymentById($paymentId)
    {
        $paymentId = (string) $paymentId;
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentId)) {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_payment` = \'' . pSQL($paymentId) . '\''
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Every payment started for a cart, newest first.
     *
     * @param int $idCart
     *
     * @return array<int,array> rows with id_payment, id_cart, amount, currency, status
     */
    public function getPaymentsByCart($idCart)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_cart` = ' . (int) $idCart . '
             ORDER BY `date_add` DESC'
        );

        return is_array($rows) ? array_values($rows) : array();
    }

    /**
     * The most recent payment started for a cart, or null.
     *
     * @param int $idCart
     *
     * @return array|null
     */
    public function getPaymentByCart($idCart)
    {
        $rows = $this->getPaymentsByCart($idCart);

        return count($rows) > 0 ? $rows[0] : null;
    }

    /**
     * @param string $paymentId
     * @param string $status pending | paid | failed | expired
     *
     * @return bool
     */
    public function setPaymentStatus($paymentId, $status)
    {
        $status = preg_replace('/[^a-z]/', '', strtolower((string) $status));

        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = \'' . pSQL($status) . '\',
                 `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             WHERE `id_payment` = \'' . pSQL((string) $paymentId) . '\''
        );
    }

    /**
     * The amount 1pay.cx reports for a payment may exceed what this shop quoted
     * (a merchant-side customer surcharge is added to the buyer's invoice), but
     * it must never be lower. Absent or non-numeric figures are not judged.
     *
     * @param mixed $reported amount from the notify / GET /v1/payments/{id}
     * @param string $quoted two-decimal string stored when the payment was started
     *
     * @return bool
     */
    public static function reportedAmountCovers($reported, $quoted)
    {
        if ($reported === null || !is_numeric($reported)) {
            return true;
        }

        return (float) self::formatAmount($reported) >= (float) self::formatAmount($quoted);
    }

    /**
     * The order id already created for this cart, or 0.
     *
     * @param Cart $cart
     *
     * @return int
     */
    public static function orderIdForCart($cart)
    {
        if (!$cart || !(int) $cart->id) {
            return 0;
        }
        if (method_exists('Order', 'getIdByCartId')) {
            return (int) Order::getIdByCartId((int) $cart->id);
        }
        if (method_exists('Order', 'getOrderByCartId')) {
            return (int) Order::getOrderByCartId((int) $cart->id);
        }

        return 0;
    }

    /**
     * Mark a cart as paid by creating its order (idempotent: a cart that already
     * has an order is left untouched and that order id is returned).
     *
     * $amount must be the total this shop quoted when the payment was started
     * (the stored row's amount), never the figure 1pay.cx reports: a merchant-side
     * customer surcharge makes the reported amount larger, and validateOrder()
     * files any difference from the cart total under "Payment error".
     *
     * The webhook and the buyer's return page run concurrently right after the
     * payment, so the check-then-validate is serialised with a MySQL advisory
     * lock per cart, and a validateOrder() failure or exception is followed by a
     * second look for an order created meanwhile.
     *
     * @param Cart $cart
     * @param string $paymentId
     * @param string $amount two-decimal string, the shop-quoted total
     * @param string $source 'webhook' | 'return'
     *
     * @return int order id, or 0 on failure
     */
    public function confirmPaidCart($cart, $paymentId, $amount, $source)
    {
        $existing = self::orderIdForCart($cart);
        if ($existing > 0) {
            return $existing;
        }

        $paymentId = (string) $paymentId;
        $lock = 'onepaycx_cart_' . (int) $cart->id;
        $locked = $this->acquireLock($lock);

        try {
            // Re-check under the lock: the other party may have just finished.
            $existing = self::orderIdForCart($cart);
            if ($existing > 0) {
                return $existing;
            }

            $ok = false;
            $failure = '';
            try {
                $ok = $this->validateOrder(
                    (int) $cart->id,
                    (int) Configuration::get('PS_OS_PAYMENT'),
                    (float) $amount,
                    self::PAYMENT_METHOD_NAME,
                    null,
                    array('transaction_id' => $paymentId),
                    (int) $cart->id_currency,
                    false,
                    $cart->secure_key
                );
            } catch (Throwable $e) {
                // 8.x throws when the cart already has an order; the message is
                // PrestaShop's own and carries no secrets.
                $failure = get_class($e) . ': ' . $e->getMessage();
            }

            if ($ok && (int) $this->currentOrder > 0) {
                $idOrder = (int) $this->currentOrder;
            } else {
                // Lost the race (or validateOrder refused): an order may exist now.
                $idOrder = self::orderIdForCart($cart);
                if ($idOrder <= 0) {
                    $this->log('order validation failed for cart ' . (int) $cart->id . ' (' . $source . ')'
                        . ($failure !== '' ? ': ' . $failure : ''), 3);

                    return 0;
                }
                $this->log('order ' . $idOrder . ' for cart ' . (int) $cart->id . ' was created concurrently (' . $source . ')', 1);
            }
        } finally {
            if ($locked) {
                $this->releaseLock($lock);
            }
        }

        $this->setPaymentStatus($paymentId, 'paid');
        $this->log('order ' . $idOrder . ' confirmed for cart ' . (int) $cart->id . ' via ' . $source, 1);

        return $idOrder;
    }

    /**
     * MySQL advisory lock (GET_LOCK) shared by every PHP process of this shop.
     * Best effort: when the server refuses or times out we proceed unlocked and
     * rely on the post-failure re-check.
     *
     * @param string $name
     *
     * @return bool true when the lock was obtained (and must be released)
     */
    protected function acquireLock($name)
    {
        try {
            $got = Db::getInstance()->getValue('SELECT GET_LOCK(\'' . pSQL($name) . '\', 10)');

            return (string) $got === '1';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param string $name
     */
    protected function releaseLock($name)
    {
        try {
            Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL($name) . '\')');
        } catch (Exception $e) {
            // Released anyway when the connection closes.
        }
    }

    /**
     * Log to Advanced Parameters -> Logs. Callers must never pass the key/secret.
     *
     * @param string $message
     * @param int $severity 1 info, 2 warning, 3 error
     */
    public function log($message, $severity = 1)
    {
        if (class_exists('PrestaShopLogger')) {
            try {
                PrestaShopLogger::addLog('1pay.cx: ' . $message, (int) $severity, null, 'Onepaycx', 0, true);
            } catch (Exception $e) {
                // Logging must never break a payment.
            }
        }
    }
}
