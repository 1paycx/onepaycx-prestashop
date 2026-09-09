<?php
/**
 * Stub-parent compile harness for the 1pay.cx PrestaShop module.
 *
 * Loads minimal PrestaShop stand-ins (stubs.php), then requires the module and
 * its three front controllers, instantiates them and drives the payment flow
 * with fake requests. Anything that would be a compile-time fatal on a real
 * shop (abstract mismatch, visibility narrowing, bad signature) fails here.
 *
 * Usage: php test/compile-check.php   (exit code 0 = pass)
 *
 * @license MIT
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/stubs.php';

$moduleDir = dirname(__DIR__) . '/onepaycx';

$failures = 0;
function check($label, $cond)
{
    global $failures;
    if ($cond) {
        echo "  ok   $label\n";
    } else {
        ++$failures;
        echo "  FAIL $label\n";
    }
}

echo "1. Loading module + controllers against stub parents\n";
require_once $moduleDir . '/onepaycx.php';
require_once $moduleDir . '/controllers/front/redirect.php';
require_once $moduleDir . '/controllers/front/webhook.php';
require_once $moduleDir . '/controllers/front/return.php';
check('classes declared', class_exists('Onepaycx') && class_exists('OnepaycxApi')
    && class_exists('OnepaycxRedirectModuleFrontController')
    && class_exists('OnepaycxWebhookModuleFrontController')
    && class_exists('OnepaycxReturnModuleFrontController'));

foreach (array('Onepaycx', 'OnepaycxRedirectModuleFrontController', 'OnepaycxWebhookModuleFrontController', 'OnepaycxReturnModuleFrontController') as $cls) {
    $ref = new ReflectionClass($cls);
    check("$cls is concrete (no unimplemented abstract methods)", !$ref->isAbstract());
}

/** Harness double for OnepaycxApi (same method surface, canned answers). */
class FakeApi
{
    public static $payment = array('id' => 'k3Xm9Qp2', 'checkoutUrl' => '/pay/k3Xm9Qp2');
    public static $status = 'pending';
    /** @var array<string,string> per-payment status overriding $status */
    public static $statuses = array();
    /** @var float what GET /v1/payments/{id} reports as amount (surcharged on the server) */
    public static $amount = 12.5;
    public static $createOk = true;
    public static $lastBody = null;
    /** @var string[] ids polled, in order */
    public static $polled = array();

    public function me()
    {
        return array('ok' => true, 'status' => 200, 'data' => array('id' => 'm_1', 'webhookSecret' => 'whsec_test'), 'error' => '');
    }

    public function createPayment(array $body)
    {
        self::$lastBody = $body;
        if (!self::$createOk) {
            return array('ok' => false, 'status' => 400, 'data' => array('error' => 'invalid'), 'error' => 'invalid');
        }

        return array('ok' => true, 'status' => 200, 'data' => self::$payment, 'error' => '');
    }

    public function getPayment($id)
    {
        self::$polled[] = $id;
        $status = isset(self::$statuses[$id]) ? self::$statuses[$id] : self::$status;

        return array('ok' => true, 'status' => 200, 'data' => array('id' => $id, 'status' => $status, 'amount' => self::$amount, 'currency' => 'USD'), 'error' => '');
    }
}

class TestOnepaycx extends Onepaycx
{
    protected function makeApi($key)
    {
        return new FakeApi();
    }
}

class TestWebhookController extends OnepaycxWebhookModuleFrontController
{
    public $body = '';

    protected function rawBody()
    {
        return $this->body;
    }

    protected function respond($status, $text)
    {
        throw new StubResponse($status, $text);
    }
}

echo "2. Module instantiation, install, configuration\n";
$module = new TestOnepaycx();
check('module name/version', $module->name === 'onepaycx' && $module->version === '1.0.0');
check('install() registers both hooks', $module->install() && in_array('paymentOptions', $module->registeredHooks, true) && in_array('displayPaymentReturn', $module->registeredHooks, true));
check('not configured before a key is saved', !$module->isConfigured());

Tools::$request = array('submitOnepaycx' => '1', 'ONEPAYCX_API_KEY' => 'pk_test.secret', 'ONEPAYCX_TITLE' => 'Pay by card or crypto');
$html = $module->getContent();
check('getContent() verifies the key and stores the webhook secret', $module->isConfigured() && Configuration::get('ONEPAYCX_WEBHOOK_SECRET') === 'whsec_test');
check('getContent() never echoes the API key', strpos($html, 'pk_test.secret') === false);
check('getContent() renders the form', strpos($html, '<form>') !== false);

Tools::$request = array('submitOnepaycx' => '1', 'ONEPAYCX_API_KEY' => '', 'ONEPAYCX_TITLE' => 'Pay');
$module->getContent();
check('blank key on re-save keeps the stored key', Configuration::get('ONEPAYCX_API_KEY') === 'pk_test.secret');

Tools::$request = array('submitOnepaycx' => '1', 'ONEPAYCX_API_KEY' => '', 'ONEPAYCX_TITLE' => 'Card/Crypto <fast>');
$html = $module->getContent();
check('title with < > = { } is rejected and the stored title kept', strpos($html, 'alert-danger') !== false && Configuration::get('ONEPAYCX_TITLE') === 'Pay');
Tools::$request = array('submitOnepaycx' => '1', 'ONEPAYCX_API_KEY' => '', 'ONEPAYCX_TITLE' => str_repeat('x', 256));
$module->getContent();
check('title longer than 255 characters is rejected', Configuration::get('ONEPAYCX_TITLE') === 'Pay');
check('isValidTitle allows unicode punctuation and rejects what isGenericName rejects', Onepaycx::isValidTitle('Pay by card — crypto ok') && !Onepaycx::isValidTitle('a=b') && !Onepaycx::isValidTitle('{x}'));

echo "3. Signature verification\n";
$secret = 'whsec_test';
$raw = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '7', 'amount' => 12.5, 'valueForwarded' => 12.5, 'status' => 'paid'), 'at' => '2026-09-08T00:00:00.000Z'));
$sig = hash_hmac('sha256', $raw, $secret);
check('valid signature accepted', Onepaycx::verifySignature($raw, $sig, $secret));
check('upper-case hex accepted', Onepaycx::verifySignature($raw, strtoupper($sig), $secret));
check('tampered body rejected', !Onepaycx::verifySignature($raw . ' ', $sig, $secret));
check('wrong secret rejected', !Onepaycx::verifySignature($raw, $sig, 'other'));
check('malformed signature rejected', !Onepaycx::verifySignature($raw, 'nope', $secret));
check('empty secret rejected', !Onepaycx::verifySignature($raw, $sig, ''));
check('formatAmount gives two decimals', Onepaycx::formatAmount(12.5) === '12.50' && Onepaycx::formatAmount('3') === '3.00' && Onepaycx::formatAmount(0.105) === '0.11');
check('reportedAmountCovers: surcharged/equal ok, lower rejected, absent ignored',
    Onepaycx::reportedAmountCovers(12.88, '12.50') && Onepaycx::reportedAmountCovers(12.5, '12.50')
    && Onepaycx::reportedAmountCovers('12.50', '12.50') && !Onepaycx::reportedAmountCovers(12.49, '12.50')
    && Onepaycx::reportedAmountCovers(null, '12.50') && Onepaycx::reportedAmountCovers('abc', '12.50'));
check('absoluteCheckoutUrl only allows 1pay.cx', OnepaycxApi::absoluteCheckoutUrl('/pay/abc') === 'https://1pay.cx/pay/abc'
    && OnepaycxApi::absoluteCheckoutUrl('https://1pay.cx/pay/abc') === 'https://1pay.cx/pay/abc'
    && OnepaycxApi::absoluteCheckoutUrl('https://evil.example/pay') === null
    && OnepaycxApi::absoluteCheckoutUrl('//evil.example/pay') === null
    && OnepaycxApi::absoluteCheckoutUrl('') === null);

echo "4. Fixtures\n";
Cart::$fixtures[7] = array('id' => 7, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('a', 32), 'total' => 12.5);
Customer::$fixtures[3] = array('id' => 3, 'email' => 'buyer@example.com');
Currency::$fixtures[1] = array('id' => 1, 'iso_code' => 'usd');
Currency::$fixtures[9] = array('id' => 9, 'iso_code' => 'xyz');
Configuration::updateValue('PS_OS_PAYMENT', 2);
Configuration::updateValue('PS_SHOP_NAME', 'Demo shop');
$ctx = Context::getContext();
$ctx->cart = new Cart(7);
$ctx->customer = new Customer(3);
check('fixtures loaded', $ctx->cart->id === 7);

echo "5. hookPaymentOptions\n";
$options = $module->hookPaymentOptions(array('cart' => $ctx->cart));
check('returns one PaymentOption', is_array($options) && count($options) === 1 && $options[0] instanceof PrestaShop\PrestaShop\Core\Payment\PaymentOption);
check('option points at the redirect controller', $options[0]->action === 'https://shop.example/module/onepaycx/redirect');
check('option carries the configured title', $options[0]->callToActionText === 'Pay');
$otherCart = new Cart(7);
$otherCart->id_currency = 9;
check('unsupported currency hides the option', $module->hookPaymentOptions(array('cart' => $otherCart)) === null);
$module->active = false;
check('disabled module hides the option', $module->hookPaymentOptions(array('cart' => $ctx->cart)) === null);
$module->active = true;

echo "6. Redirect controller\n";
$redirect = new OnepaycxRedirectModuleFrontController($module);
$url = null;
try {
    $redirect->postProcess();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('redirects to https://1pay.cx{checkoutUrl}', $url === 'https://1pay.cx/pay/k3Xm9Qp2');
$b = FakeApi::$lastBody;
check('amount is a two-decimal number, currency upper-case ISO', is_float($b['amount']) && $b['amount'] === 12.5 && $b['currency'] === 'USD');
check('reference = cart id, email forwarded', $b['reference'] === '7' && $b['email'] === 'buyer@example.com');
check('notifyUrl/redirectUrl point back at this shop over https', strpos($b['notifyUrl'], 'https://shop.example/module/onepaycx/webhook') === 0
    && strpos($b['redirectUrl'], 'https://shop.example/module/onepaycx/return?id_cart=7&key=') === 0);
$row = $module->getPaymentByCart(7);
check('payment stored against the cart', $row !== null && $row['id_payment'] === 'k3Xm9Qp2' && $row['amount'] === '12.50' && $row['currency'] === 'USD');

FakeApi::$createOk = false;
$url = null;
try {
    $redirect->postProcess();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('API failure returns the buyer to checkout with an error', $url === 'index.php?controller=order&step=1' && count($redirect->errors) === 1);
FakeApi::$createOk = true;

FakeApi::$payment = array('id' => 'k3Xm9Qp2', 'checkoutUrl' => 'https://evil.example/pay');
$url = null;
try {
    $redirect->postProcess();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('foreign checkoutUrl is never followed', $url === 'index.php?controller=order&step=1');
FakeApi::$payment = array('id' => 'k3Xm9Qp2', 'checkoutUrl' => '/pay/k3Xm9Qp2');
$url = null;
try {
    $redirect->postProcess();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('a payment row that cannot be stored never sends the buyer to pay', $url === 'index.php?controller=order&step=1');
check('payment row for the cart is still the original one', $module->getPaymentByCart(7)['id_payment'] === 'k3Xm9Qp2' && count($module->getPaymentsByCart(7)) === 1);

echo "7. Webhook controller\n";
function webhook($module, $body, $sig)
{
    $c = new TestWebhookController($module);
    $c->body = $body;
    $_SERVER['HTTP_X_1PAY_SIGNATURE'] = $sig;
    try {
        $c->postProcess();
    } catch (StubResponse $r) {
        return $r;
    }

    return null;
}
$r = webhook($module, $raw, 'deadbeef');
check('bad signature -> 401', $r && $r->status === 401);
$r = webhook($module, $raw, '');
check('missing signature -> 400', $r && $r->status === 400);
$r = webhook($module, $raw . ' ', $sig);
check('signature must match the RAW body -> 401', $r && $r->status === 401);

$other = json_encode(array('event' => 'card.issued', 'data' => array(), 'at' => 'x'));
$r = webhook($module, $other, hash_hmac('sha256', $other, $secret));
check('other events acknowledged and ignored -> 200', $r && $r->status === 200 && $r->text === 'ignored');

$unknown = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'zzz', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $unknown, hash_hmac('sha256', $unknown, $secret));
check('paid event for a payment this shop did not create -> 404', $r && $r->status === 404 && empty($module->validated));

$missing = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '999', 'amount' => 1, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $missing, hash_hmac('sha256', $missing, $secret));
check('unknown cart -> 404', $r && $r->status === 404);

$wrongCart = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '8', 'amount' => 12.5, 'status' => 'paid'), 'at' => 'x'));
Cart::$fixtures[8] = array('id' => 8, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('b', 32), 'total' => 12.5);
$r = webhook($module, $wrongCart, hash_hmac('sha256', $wrongCart, $secret));
check('known payment but for another cart -> 404, no validateOrder', $r && $r->status === 404 && empty($module->validated));

$under = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '7', 'amount' => 12.49, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $under, hash_hmac('sha256', $under, $secret));
check('reported amount below the quoted total -> 400, no validateOrder', $r && $r->status === 400 && $r->text === 'amount mismatch' && empty($module->validated));

$badCur = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '7', 'amount' => 12.5, 'currency' => 'EUR', 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $badCur, hash_hmac('sha256', $badCur, $secret));
check('currency mismatch -> 400, no validateOrder', $r && $r->status === 400 && empty($module->validated));

// The server stores amount * (1 + customer surcharge) and reports that figure;
// the order must still be validated with the total this shop quoted.
$surcharged = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'k3Xm9Qp2', 'reference' => '7', 'amount' => 12.88, 'valueForwarded' => 12.88, 'status' => 'paid'), 'at' => '2026-09-08T00:00:00.000Z'));
$r = webhook($module, $surcharged, hash_hmac('sha256', $surcharged, $secret));
check('valid paid event (surcharged amount 12.88 for a 12.50 cart) -> 200 ok and validateOrder called once', $r && $r->status === 200 && $r->text === 'ok' && count($module->validated) === 1);
$v = $module->validated[0];
check('validateOrder gets cart, Payment accepted state, the QUOTED amount 12.5, transaction id, secure key',
    $v['id_cart'] === 7 && $v['id_order_state'] === 2 && $v['amount_paid'] === 12.5
    && $v['extra_vars'] === array('transaction_id' => 'k3Xm9Qp2') && $v['secure_key'] === str_repeat('a', 32));
check('Order::$payment is the fixed safe name, not the configurable title', $v['payment_method'] === '1pay.cx' && $v['payment_method'] !== $module->paymentTitle());
check('payment row marked paid', $module->getPaymentByCart(7)['status'] === 'paid');
check('advisory lock taken and released', Db::getInstance()->locks['get'] === 1 && Db::getInstance()->locks['release'] === 1);

$r = webhook($module, $raw, $sig);
check('redelivery is idempotent -> 200 already paid, no second validateOrder', $r && $r->status === 200 && $r->text === 'already paid' && count($module->validated) === 1);

$module->active = false;
$r = webhook($module, $raw, $sig);
check('disabled module -> 503', $r && $r->status === 503);
$module->active = true;

// Restarted checkout: the buyer clicked Pay twice (payments pA then pB for cart 9)
// and completed the EARLIER link. Both rows exist; the notify for pA must succeed.
Cart::$fixtures[9] = array('id' => 9, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('c', 32), 'total' => 12.5);
check('two payments stored for one cart', $module->savePayment(9, 'pA', 12.5, 'USD') && $module->savePayment(9, 'pB', 12.5, 'USD')
    && count($module->getPaymentsByCart(9)) === 2 && $module->getPaymentByCart(9)['id_payment'] === 'pB');
$earlier = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'pA', 'reference' => '9', 'amount' => 12.5, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $earlier, hash_hmac('sha256', $earlier, $secret));
check('paid notify for the earlier of two links -> 200 ok and the order is created', $r && $r->status === 200 && $r->text === 'ok'
    && count($module->validated) === 2 && $module->validated[1]['id_cart'] === 9 && $module->validated[1]['extra_vars'] === array('transaction_id' => 'pA'));
check('only the paid row is marked paid', $module->getPaymentById('pA')['status'] === 'paid' && $module->getPaymentById('pB')['status'] === 'pending');
$later = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'pB', 'reference' => '9', 'amount' => 12.5, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $later, hash_hmac('sha256', $later, $secret));
check('a second link paid for an already-ordered cart -> 200 already paid, no second order', $r && $r->status === 200 && $r->text === 'already paid' && count($module->validated) === 2);

// Concurrency: the return page created the order between our check and validateOrder.
Cart::$fixtures[11] = array('id' => 11, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('e', 32), 'total' => 12.5);
$module->savePayment(11, 'p11', 12.5, 'USD');
$module->throwValidate = 777;
$race = json_encode(array('event' => 'payment.paid', 'data' => array('paymentId' => 'p11', 'reference' => '11', 'amount' => 12.5, 'status' => 'paid'), 'at' => 'x'));
$r = webhook($module, $race, hash_hmac('sha256', $race, $secret));
check('validateOrder throwing because the order was just created elsewhere -> 200 ok (order 777 found), no 500', $r && $r->status === 200 && $r->text === 'ok'
    && $module->getPaymentById('p11')['status'] === 'paid');
$module->throwValidate = 0;
check('advisory lock balanced after the exception', Db::getInstance()->locks['get'] === Db::getInstance()->locks['release']);

echo "8. Return controller\n";
Tools::$request = array('id_cart' => 7, 'key' => str_repeat('a', 32));
$ret = new OnepaycxReturnModuleFrontController($module);
$url = null;
try {
    $ret->initContent();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('cart with an order -> order-confirmation page', $url !== null && strpos($url, 'controller=order-confirmation') !== false && strpos($url, 'id_order=101') !== false);

Tools::$request = array('id_cart' => 7, 'key' => 'wrong');
$url = null;
try {
    $ret->initContent();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('wrong secure key -> back to checkout', $url === 'index.php?controller=order&step=1');

// Fresh cart with a pending payment and no order yet.
$module->savePayment(8, 'p8', 12.5, 'USD');
Tools::$request = array('id_cart' => 8, 'key' => str_repeat('b', 32));
FakeApi::$status = 'pending';
$ret = new OnepaycxReturnModuleFrontController($module);
$ret->initContent();
check('pending payment renders the waiting template', $ret->template === 'module:onepaycx/views/templates/front/return.tpl' && $ctx->smarty->vars['onepaycx_state'] === 'pending');

FakeApi::$status = 'expired';
$ret->initContent();
check('expired payment renders the retry state', $ctx->smarty->vars['onepaycx_state'] === 'expired' && $module->getPaymentByCart(8)['status'] === 'expired');

// The buyer tries again after the expiry: a new link; the expired one is not re-polled.
$module->savePayment(8, 'p8b', 12.5, 'USD');
FakeApi::$status = 'pending';
FakeApi::$polled = array();
$ret->initContent();
check('new attempt after an expired one -> pending, expired row not polled again', $ctx->smarty->vars['onepaycx_state'] === 'pending' && FakeApi::$polled === array('p8b'));

FakeApi::$status = 'paid';
FakeApi::$amount = 12.88; // server-side customer surcharge
$validatedBefore = count($module->validated);
$url = null;
try {
    $ret->initContent();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('paid on poll -> order validated and buyer sent to confirmation', $url !== null && preg_match('/id_order=(\d+)/', $url, $m) && (int) $m[1] > 0
    && count($module->validated) === $validatedBefore + 1 && $module->validated[$validatedBefore]['id_cart'] === 8);
check('poll reporting 12.88 for a 12.50 cart still validates with 12.5', $module->validated[$validatedBefore]['amount_paid'] === 12.5);
FakeApi::$amount = 12.5;

// Restarted checkout, buyer paid the EARLIER link and is polling from the later tab:
// q1 (older, paid) and q2 (newer, pending) for cart 10.
Cart::$fixtures[10] = array('id' => 10, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('d', 32), 'total' => 12.5);
$module->savePayment(10, 'q1', 12.5, 'USD');
$module->savePayment(10, 'q2', 12.5, 'USD');
FakeApi::$statuses = array('q1' => 'paid', 'q2' => 'pending');
FakeApi::$polled = array();
Tools::$request = array('id_cart' => 10, 'key' => str_repeat('d', 32));
$validatedBefore = count($module->validated);
$url = null;
try {
    $ret->initContent();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('return page polls every pending link and completes the order on the paid one', $url !== null && strpos($url, 'controller=order-confirmation') !== false
    && FakeApi::$polled === array('q2', 'q1') && count($module->validated) === $validatedBefore + 1
    && $module->validated[$validatedBefore]['id_cart'] === 10 && $module->validated[$validatedBefore]['extra_vars'] === array('transaction_id' => 'q1'));
FakeApi::$statuses = array();

// Reported amount lower than quoted on poll -> error, no order.
Cart::$fixtures[12] = array('id' => 12, 'id_customer' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'id_currency' => 1, 'secure_key' => str_repeat('f', 32), 'total' => 12.5);
$module->savePayment(12, 'p12', 12.5, 'USD');
FakeApi::$amount = 12.0;
Tools::$request = array('id_cart' => 12, 'key' => str_repeat('f', 32));
$validatedBefore = count($module->validated);
$ret->initContent();
check('poll reporting less than quoted -> error state, no validateOrder', $ctx->smarty->vars['onepaycx_state'] === 'error' && count($module->validated) === $validatedBefore);
FakeApi::$amount = 12.5;

// validateOrder fails outright with no order anywhere -> error state.
$module->failValidate = true;
$ret->initContent();
check('validateOrder failure with no order -> error state', $ctx->smarty->vars['onepaycx_state'] === 'error' && Onepaycx::orderIdForCart(new Cart(12)) === 0);
$module->failValidate = false;

// validateOrder fails but the webhook created the order meanwhile -> confirmation.
$module->throwValidate = 888;
$url = null;
try {
    $ret->initContent();
} catch (StubRedirect $e) {
    $url = $e->url;
}
check('order created concurrently by the webhook -> buyer still reaches confirmation', $url !== null && strpos($url, 'id_order=888') !== false);
$module->throwValidate = 0;
check('advisory locks balanced', Db::getInstance()->locks['get'] === Db::getInstance()->locks['release']);

echo "9. hookDisplayPaymentReturn\n";
$order = new Order();
$order->module = 'onepaycx';
$order->valid = true;
$order->reference = 'ABCDEF';
check('renders for own orders', strpos($module->hookDisplayPaymentReturn(array('order' => $order)), 'payment_return.tpl') !== false && $ctx->smarty->vars['onepaycx_paid'] === true);
$order->module = 'other';
check('silent for other modules', $module->hookDisplayPaymentReturn(array('order' => $order)) === '');

echo "10. Secrets never reach the log\n";
$joined = json_encode(PrestaShopLogger::$logs);
check('log contains neither key nor secret', strpos($joined, 'pk_test.secret') === false && strpos($joined, 'whsec_test') === false);

echo "11. Uninstall\n";
check('uninstall drops config + table', $module->uninstall() && Configuration::get('ONEPAYCX_API_KEY') === false && Configuration::get('ONEPAYCX_WEBHOOK_SECRET') === false);

echo "\n" . ($failures === 0 ? 'PASS' : "FAIL ($failures)") . "\n";
exit($failures === 0 ? 0 : 1);
