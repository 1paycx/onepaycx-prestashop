<?php
/**
 * Minimal stand-ins for the PrestaShop classes the 1pay.cx module touches.
 *
 * Only the members the module actually calls are defined. The point is to load
 * the module and its controllers against *some* parent so that a compile-time
 * mismatch (abstract method not implemented, property visibility narrowed,
 * signature incompatibility) fails here instead of on a live shop. Behaviour is
 * the simplest thing that lets the harness drive the code paths.
 *
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/stubs-payment-option.php';

/** PrestaShop global SQL-escaping helper. */
function pSQL($string, $htmlOK = false)
{
    return addslashes((string) $string);
}

/** Thrown by Tools::redirect() so the harness can observe redirects. */
class StubRedirect extends Exception
{
    public $url;

    public function __construct($url)
    {
        parent::__construct('redirect: ' . $url);
        $this->url = $url;
    }
}

/** PrestaShop 8.x throws this from validateOrder() when the cart already has an order. */
class PrestaShopException extends Exception
{
}

/** Thrown by the test webhook controller instead of exit(). */
class StubResponse extends Exception
{
    public $status;
    public $text;

    public function __construct($status, $text)
    {
        parent::__construct($status . ' ' . $text);
        $this->status = (int) $status;
        $this->text = (string) $text;
    }
}

class StubSmarty
{
    public $vars = array();

    public function assign($a, $b = null)
    {
        if (is_array($a)) {
            $this->vars = array_merge($this->vars, $a);
        } else {
            $this->vars[$a] = $b;
        }
    }
}

class Link
{
    public function getModuleLink($module, $controller = 'default', array $params = array(), $ssl = null)
    {
        $url = 'https://shop.example/module/' . $module . '/' . $controller;

        return $params ? $url . '?' . http_build_query($params) : $url;
    }

    public function getPageLink($controller, $ssl = null, $idLang = null, $request = null)
    {
        $url = 'https://shop.example/index.php?controller=' . $controller;

        return is_array($request) && $request ? $url . '&' . http_build_query($request) : $url;
    }
}

class Context
{
    private static $instance;

    public $smarty;
    public $link;
    public $cart;
    public $customer;
    public $controller;

    public static function getContext()
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->smarty = new StubSmarty();
            self::$instance->link = new Link();
        }

        return self::$instance;
    }
}

class Configuration
{
    public static $store = array();

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return array_key_exists($key, self::$store) ? self::$store[$key] : $default;
    }

    public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null)
    {
        self::$store[$key] = $values;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$store[$key]);

        return true;
    }
}

class Tools
{
    public static $request = array();

    public static function getValue($key, $default = false)
    {
        return array_key_exists($key, self::$request) ? self::$request[$key] : $default;
    }

    public static function isSubmit($submit)
    {
        return array_key_exists($submit, self::$request);
    }

    public static function redirect($url, $baseUri = null, $link = null, $headers = null)
    {
        throw new StubRedirect($url);
    }

    public static function getAdminTokenLite($tab, $context = null)
    {
        return 'token';
    }
}

class Db
{
    private static $instance;

    /** @var array<string,string> executed SQL, for assertions */
    public $executed = array();

    /**
     * @var array rows keyed by id_payment (the primary key) for the
     *            onepaycx_payment table, in insertion order
     */
    public $paymentRows = array();

    /** @var array<string,int> GET_LOCK / RELEASE_LOCK calls, for balance assertions */
    public $locks = array('get' => 0, 'release' => 0);

    public static function getInstance($master = true)
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function execute($sql, $useCache = true)
    {
        $this->executed[] = $sql;
        // Emulate just enough of INSERT/UPDATE on the payment table for the harness,
        // including MySQL's duplicate-primary-key failure.
        if (preg_match('/^INSERT INTO `ps_onepaycx_payment`.*VALUES \(\'([^\']+)\', (\d+), \'([^\']+)\', \'([^\']+)\', \'([^\']+)\'/s', $sql, $m)) {
            if (isset($this->paymentRows[$m[1]])) {
                return false; // Duplicate entry for key PRIMARY
            }
            $this->paymentRows[$m[1]] = array(
                'id_payment' => $m[1], 'id_cart' => (int) $m[2], 'amount' => $m[3], 'currency' => $m[4], 'status' => $m[5],
            );
        } elseif (preg_match('/^UPDATE `ps_onepaycx_payment`.*`status` = \'([^\']+)\'.*WHERE `id_payment` = \'([^\']+)\'/s', $sql, $m)) {
            if (isset($this->paymentRows[$m[2]])) {
                $this->paymentRows[$m[2]]['status'] = $m[1];
            }
        }

        return true;
    }

    public function getRow($sql, $useCache = true)
    {
        if (preg_match('/WHERE `id_payment` = \'([^\']+)\'/', $sql, $m) && isset($this->paymentRows[$m[1]])) {
            return $this->paymentRows[$m[1]];
        }

        return false;
    }

    /** Rows for a cart; ORDER BY date_add DESC is emulated as newest-inserted first. */
    public function executeS($sql, $array = true, $useCache = true)
    {
        if (preg_match('/WHERE `id_cart` = (\d+)/', $sql, $m)) {
            $rows = array();
            foreach ($this->paymentRows as $row) {
                if ((int) $row['id_cart'] === (int) $m[1]) {
                    $rows[] = $row;
                }
            }

            return array_reverse($rows);
        }

        return array();
    }

    public function getValue($sql, $useCache = true)
    {
        if (strpos($sql, 'GET_LOCK(') !== false) {
            ++$this->locks['get'];

            return '1';
        }
        if (strpos($sql, 'RELEASE_LOCK(') !== false) {
            ++$this->locks['release'];

            return '1';
        }

        return false;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && isset($object->id) && (int) $object->id > 0;
    }

    public static function isEmail($email)
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /** Same rule as PrestaShop's: no < > = { } */
    public static function isGenericName($name)
    {
        return (bool) preg_match('/^[^<>={}]*$/u', (string) $name);
    }
}

class Cart
{
    const ONLY_PRODUCTS = 1;
    const ONLY_DISCOUNTS = 2;
    const BOTH = 3;

    public static $fixtures = array();

    public $id = 0;
    public $id_customer = 0;
    public $id_address_delivery = 0;
    public $id_address_invoice = 0;
    public $id_currency = 0;
    public $secure_key = '';
    public $total = 0.0;

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$fixtures[(int) $id])) {
            foreach (self::$fixtures[(int) $id] as $k => $v) {
                $this->$k = $v;
            }
        }
    }

    public function getOrderTotal($withTaxes = true, $type = self::BOTH)
    {
        return $this->total;
    }
}

class Customer
{
    public static $fixtures = array();

    public $id = 0;
    public $email = '';

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$fixtures[(int) $id])) {
            foreach (self::$fixtures[(int) $id] as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

class Currency
{
    public static $fixtures = array();

    public $id = 0;
    public $iso_code = '';

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$fixtures[(int) $id])) {
            foreach (self::$fixtures[(int) $id] as $k => $v) {
                $this->$k = $v;
            }
        }
    }
}

class Order
{
    /** @var array<int,int> id_cart => id_order */
    public static $byCart = array();

    public $id = 0;
    public $module = '';
    public $valid = false;
    public $reference = '';

    public static function getIdByCartId($idCart)
    {
        return isset(self::$byCart[(int) $idCart]) ? self::$byCart[(int) $idCart] : 0;
    }
}

class Media
{
    public static function getMediaPath($mediaUri, $cssMediaType = null)
    {
        return '/modules/onepaycx/logo.png';
    }
}

class PrestaShopLogger
{
    public static $logs = array();

    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null)
    {
        self::$logs[] = array($severity, $message);

        return true;
    }
}

class AdminController
{
    public static $currentIndex = 'index.php?controller=AdminModules';
}

class HelperForm
{
    public $module;
    public $name_controller;
    public $token;
    public $currentIndex;
    public $default_form_language;
    public $allow_employee_form_lang;
    public $title;
    public $show_toolbar;
    public $submit_action;
    public $fields_value = array();

    public function generateForm($fields)
    {
        return '<form>' . count($fields) . '</form>';
    }
}

class Module
{
    public $id = 1;
    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $bootstrap;
    public $ps_versions_compliancy;
    public $displayName;
    public $description;
    public $confirmUninstall;
    public $controllers = array();
    public $active = true;
    public $context;

    /** @var array */
    protected $_errors = array();

    public $registeredHooks = array();

    public function __construct($name = null, ?Context $context = null)
    {
        $this->context = Context::getContext();
    }

    public function l($string, $specific = false, $locale = null)
    {
        return $string;
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function registerHook($hookName, $shopList = null)
    {
        $this->registeredHooks[] = $hookName;

        return true;
    }

    public function display($file, $template, $cacheId = null, $compileId = null)
    {
        return '<!-- ' . $template . ' -->';
    }

    public function fetch($templatePath, $cacheId = null, $compileId = null)
    {
        return '<!-- ' . $templatePath . ' -->';
    }

    public function displayError($error)
    {
        return '<div class="alert alert-danger">' . $error . '</div>';
    }

    public function displayConfirmation($string)
    {
        return '<div class="alert alert-success">' . $string . '</div>';
    }

    public static function getPaymentModules()
    {
        return array(array('name' => 'onepaycx'));
    }

    public function getErrors()
    {
        return $this->_errors;
    }
}

abstract class PaymentModule extends Module
{
    public $currencies = true;
    public $currencies_mode = 'checkbox';
    public $currentOrder = 0;

    /** @var array recorded validateOrder calls */
    public $validated = array();

    /** @var bool make validateOrder fail (harness knob) */
    public $failValidate = false;

    /**
     * @var int when > 0, validateOrder() behaves like PrestaShop 8.x when another
     *          process just created the order for the cart: that order (this id)
     *          appears and a PrestaShopException is thrown
     */
    public $throwValidate = 0;

    public function getCurrency($idCurrency = false)
    {
        return array(array('id_currency' => 1), array('id_currency' => 2));
    }

    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        ?Shop $shop = null,
        $order_reference = null
    ) {
        $this->validated[] = compact('id_cart', 'id_order_state', 'amount_paid', 'payment_method', 'extra_vars', 'currency_special', 'secure_key');
        if ($this->failValidate) {
            return false;
        }
        if ($this->throwValidate > 0) {
            Order::$byCart[(int) $id_cart] = $this->throwValidate;
            throw new PrestaShopException('Cart cannot be loaded or an order has already been placed using this cart');
        }
        $this->currentOrder = 100 + count($this->validated);
        Order::$byCart[(int) $id_cart] = $this->currentOrder;

        return true;
    }
}

class Shop
{
}

class FrontController
{
    public $context;
    public $errors = array();
    public $template;
    public $ssl = false;

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    public function init()
    {
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
    }

    public function setTemplate($template, $params = array(), $locale = null)
    {
        $this->template = $template;
    }

    public function redirectWithNotifications($url)
    {
        throw new StubRedirect($url);
    }
}

class ModuleFrontController extends FrontController
{
    /** @var Module */
    public $module;

    public function __construct($module = null)
    {
        parent::__construct();
        $this->module = $module;
    }
}
