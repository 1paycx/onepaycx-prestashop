<?php
/**
 * 1pay.cx for PrestaShop — signed notify receiver.
 *
 * 1pay.cx POSTs { event, data, at } here with X-1pay-Signature = hex HMAC-SHA256
 * of the RAW request body, keyed with the merchant's webhook secret (fetched
 * from GET /v1/me when the API key was saved). Only a verified "payment.paid"
 * event creates the order; everything else is acknowledged and ignored.
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
        $secret = (string) Configuration::get(Onepaycx::CFG_WEBHOOK_SECRET);

        if (!$this->module->active) {
            $this->respond(503, 'module disabled');
        }
        if ($secret === '' || $signature === '' || $raw === '') {
            $this->respond(400, 'missing signature');
        }
        if (!Onepaycx::verifySignature($raw, $signature, $secret)) {
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
        if (isset($data['currency']) && is_string($data['currency'])
            && strtoupper($data['currency']) !== strtoupper((string) $known['currency'])) {
            $this->module->log('webhook rejected: currency mismatch for cart ' . (int) $cart->id, 2);
            $this->respond(400, 'currency mismatch');
        }

        // The order is validated with the amount this shop quoted (the cart total
        // stored with the payment). The notify's amount may be higher (customer
        // surcharge) but never lower.
        $reported = isset($data['amount']) ? $data['amount'] : null;
        if (!Onepaycx::reportedAmountCovers($reported, $known['amount'])) {
            $this->module->log('webhook rejected: amount ' . Onepaycx::formatAmount($reported)
                . ' below quoted ' . (string) $known['amount'] . ' for cart ' . (int) $cart->id, 3);
            $this->respond(400, 'amount mismatch');
        }

        $idOrder = $this->module->confirmPaidCart($cart, $paymentId, (string) $known['amount'], 'webhook');
        if ($idOrder > 0) {
            $this->respond(200, 'ok');
        }

        $this->respond(500, 'order validation failed');
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
