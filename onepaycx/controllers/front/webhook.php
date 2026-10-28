<?php
/**
 * 1pay.cx for PrestaShop — signed notify receiver.
 *
 * 1pay.cx POSTs { event, data, at } here, signed with the merchant's webhook
 * secret (fetched from GET /v1/me when the API key was saved):
 * X-1pay-Signature-V2 = "t=<unix>,v1=<hex HMAC-SHA256 of '<t>.<raw body>'>"
 * (checked, with a 5-minute window, whenever present) or the legacy
 * X-1pay-Signature = hex HMAC-SHA256 of the RAW body.
 *
 * Only a verified "payment.paid" event creates the order, and only when the
 * payment id is one this shop stored for that cart, the notify's amount and
 * currency cover the stored quote, a test payment is not paying under a live
 * key, and GET /v1/payments/{id} with the configured key confirms "paid" with a
 * matching amount/currency. Any failed check answers non-2xx and creates
 * nothing; the buyer's return page finishes a genuine payment.
 *
 * Deliveries are at-least-once: a cart that already has an order answers 200
 * without touching anything. The order is validated with the total this shop
 * quoted when the payment was started; the notify's amount is only checked to be
 * no lower than that (a merchant-side customer surcharge makes it higher).
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OnepaycxWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function postProcess()
    {
        $raw = $this->rawBody();
        $signature = isset($_SERVER['HTTP_X_1PAY_SIGNATURE']) ? (string) $_SERVER['HTTP_X_1PAY_SIGNATURE'] : '';
        $signatureV2 = isset($_SERVER['HTTP_X_1PAY_SIGNATURE_V2']) ? (string) $_SERVER['HTTP_X_1PAY_SIGNATURE_V2'] : '';
        $apiKey = (string) Configuration::get(Onepaycx::CFG_API_KEY);
        $secret = (string) Configuration::get(Onepaycx::CFG_WEBHOOK_SECRET);

        if (!$this->module->active) {
            $this->respond(503, 'module disabled');
        }
        if ($secret === '' || ($signature === '' && $signatureV2 === '') || $raw === '') {
            $this->respond(400, 'missing signature');
        }
        if (!Onepaycx::verifyWebhook($raw, $signatureV2, $signature, $secret, $this->now())) {
            $this->module->log('webhook rejected: bad signature', 2);
            $this->respond(401, 'bad signature');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->respond(400, 'bad json');
        }
        $event = isset($payload['event']) ? (string) $payload['event'] : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

        if ($event !== 'payment.paid') {
            $this->respond(200, 'ignored');
        }
        if (isset($data['status']) && (string) $data['status'] !== 'paid') {
            $this->respond(200, 'ignored');
        }

        $reference = isset($data['reference']) ? (string) $data['reference'] : '';
        $paymentId = isset($data['paymentId']) ? (string) $data['paymentId'] : '';
        if (!preg_match('/^[1-9][0-9]{0,11}$/', $reference) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentId)) {
            $this->respond(400, 'bad reference');
        }

        // A test payment must never create an order in a live shop.
        $liveKey = !Onepaycx::isTestKey($apiKey);
        if ($liveKey && array_key_exists('livemode', $data) && $data['livemode'] === false) {
            $this->module->log('webhook rejected: test payment under a live key', 2);
            $this->respond(400, 'test payment');
        }

        $cart = new Cart((int) $reference);
        if (!Validate::isLoadedObject($cart)) {
            $this->respond(404, 'cart not found');
        }

        // Idempotent: a repeated notify for a cart that already has an order is a no-op.
        if (Onepaycx::orderIdForCart($cart) > 0) {
            $this->respond(200, 'already paid');
        }

        // Only honour notifies for a payment this store actually started for this
        // cart. A buyer who restarted checkout has several payable links for the
        // same cart; each has its own row, so whichever one they pay is found.
        $known = $this->module->getPaymentById($paymentId);
        if ($known === null || (int) $known['id_cart'] !== (int) $reference) {
            $this->module->log('webhook rejected: unknown payment for cart ' . (int) $cart->id, 2);
            $this->respond(404, 'unknown payment');
        }
        // The notify must name the quoted currency and at least the quoted amount
        // (a customer surcharge may make it higher; 0.01 rounding tolerance).
        if (!Onepaycx::coversQuote(isset($data['amount']) ? $data['amount'] : null, isset($data['currency']) ? $data['currency'] : null, $known['amount'], $known['currency'])) {
            $this->module->log('webhook rejected: amount/currency mismatch for cart ' . (int) $cart->id, 3);
            $this->respond(400, 'amount mismatch');
        }

        // Trust, but verify: ask 1pay.cx directly with this shop's own key.
        if ($apiKey === '') {
            $this->respond(503, 'not configured');
        }
        $res = $this->module->api()->getPayment($paymentId);
        if (empty($res['ok']) || !isset($res['data']) || !is_array($res['data'])) {
            $this->respond(503, 'verification unavailable');
        }
        $remote = $res['data'];
        if (!isset($remote['status']) || (string) $remote['status'] !== 'paid') {
            $this->module->log('webhook rejected: API does not report cart ' . (int) $cart->id . ' as paid', 2);
            $this->respond(409, 'not paid');
        }
        if (!Onepaycx::coversQuote(isset($remote['amount']) ? $remote['amount'] : null, isset($remote['currency']) ? $remote['currency'] : null, $known['amount'], $known['currency'])) {
            $this->module->log('webhook rejected: API amount/currency mismatch for cart ' . (int) $cart->id, 3);
            $this->respond(400, 'amount mismatch');
        }
        if ($liveKey && array_key_exists('livemode', $remote) && $remote['livemode'] === false) {
            $this->respond(400, 'test payment');
        }

        $idOrder = $this->module->confirmPaidCart($cart, $paymentId, (string) $known['amount'], 'webhook');
        if ($idOrder > 0) {
            $this->respond(200, 'ok');
        }

        $this->respond(500, 'order validation failed');
    }

    /**
     * Current unix time (overridable by the compile harness).
     *
     * @return int
     */
    protected function now()
    {
        return time();
    }

    /**
     * The raw request body — the signature is computed over these exact bytes.
     *
     * @return string
     */
    protected function rawBody()
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Plain-text response and stop; the notify sender only cares about the status.
     *
     * @param int $status
     * @param string $text
     */
    protected function respond($status, $text)
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo $text;
        exit;
    }
}
