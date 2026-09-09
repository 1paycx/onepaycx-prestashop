<?php
/**
 * 1pay.cx for PrestaShop — tiny HTTP client for the 1pay.cx REST API.
 *
 * All outbound traffic goes to https://api.1pay.cx only. The API key is sent as
 * a bearer token and is never logged, echoed or included in error strings.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OnepaycxApi
{
    const API_BASE = 'https://api.1pay.cx';
    const CHECKOUT_BASE = 'https://1pay.cx';
    const USER_AGENT = 'onepaycx-prestashop/1.0.0';

    /** @var string */
    private $key;

    /** @var int seconds */
    private $timeout = 20;

    /**
     * @param string $key the merchant's 1pay.cx API key (pk_xxx.yyy)
     */
    public function __construct($key)
    {
        $this->key = (string) $key;
    }

    /**
     * GET /v1/me — validates the key and returns { id, email, webhookSecret, ... }.
     *
     * @return array{ok:bool,status:int,data:mixed,error:string}
     */
    public function me()
    {
        return $this->request('GET', '/v1/me');
    }

    /**
     * POST /v1/payments — create a hosted-checkout payment.
     * Returns { id, checkoutUrl } on success; checkoutUrl is a path on https://1pay.cx.
     *
     * @return array{ok:bool,status:int,data:mixed,error:string}
     */
    public function createPayment(array $body)
    {
        return $this->request('POST', '/v1/payments', $body);
    }

    /**
     * GET /v1/payments/{id} — status is one of pending | paid | failed | expired.
     *
     * @return array{ok:bool,status:int,data:mixed,error:string}
     */
    public function getPayment($id)
    {
        $id = (string) $id;
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'invalid_payment_id');
        }

        return $this->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /**
     * Turn the checkoutUrl returned by the API into an absolute URL on 1pay.cx.
     * Anything that is not a relative path on 1pay.cx is rejected so a buyer can
     * never be redirected anywhere but the hosted checkout.
     *
     * @param string $checkoutUrl
     *
     * @return string|null absolute https://1pay.cx/... URL, or null when unusable
     */
    public static function absoluteCheckoutUrl($checkoutUrl)
    {
        $checkoutUrl = (string) $checkoutUrl;
        if ($checkoutUrl === '') {
            return null;
        }
        if (strpos($checkoutUrl, '/') === 0 && strpos($checkoutUrl, '//') !== 0) {
            return self::CHECKOUT_BASE . $checkoutUrl;
        }
        if (strpos($checkoutUrl, self::CHECKOUT_BASE . '/') === 0) {
            return $checkoutUrl;
        }

        return null;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $body
     *
     * @return array{ok:bool,status:int,data:mixed,error:string}
     */
    private function request($method, $path, $body = null)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl_missing');
        }

        $headers = array(
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT,
        );

        $ch = curl_init(self::API_BASE . $path);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            // curl's message never contains the key (it is only in a header).
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => 'network_error');
        }

        $data = json_decode((string) $raw, true);
        $ok = $status >= 200 && $status < 300;
        $error = '';
        if (!$ok) {
            $error = (is_array($data) && isset($data['error']) && is_string($data['error']))
                ? $data['error']
                : ('HTTP ' . $status);
        }

        return array('ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error);
    }
}
