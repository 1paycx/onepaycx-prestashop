<?php
/**
 * 1pay.cx for PrestaShop — the page the buyer lands on after the hosted checkout.
 *
 * If the notify already created the order, the buyer is sent straight to the
 * standard order-confirmation page. Otherwise every payment still pending for
 * the cart is polled (GET /v1/payments/{id}) — a buyer who restarted checkout
 * may have paid an earlier link: paid -> validate the order here and confirm;
 * pending -> show a waiting page that refreshes; failed/expired -> offer to retry.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OnepaycxReturnModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** Upper bound on GET /v1/payments/{id} calls per page load. */
    const MAX_POLL = 5;

    public function initContent()
    {
        parent::initContent();

        $idCart = (int) Tools::getValue('id_cart');
        $key = (string) Tools::getValue('key');

        $cart = $idCart > 0 ? new Cart($idCart) : null;
        if (!$cart || !Validate::isLoadedObject($cart) || $key === ''
            || !hash_equals((string) $cart->secure_key, $key)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $idOrder = Onepaycx::orderIdForCart($cart);
        if ($idOrder > 0) {
            Tools::redirect($this->orderConfirmationUrl($cart, $idOrder));

            return;
        }

        $state = 'pending';
        $rows = $this->module->getPaymentsByCart((int) $cart->id);
        if (count($rows) === 0) {
            $state = 'unknown';
        } elseif ($this->module->isConfigured()) {
            $state = $this->pollPayments($cart, $rows);
            if ($state === 'redirected') {
                return;
            }
        }

        $this->context->smarty->assign(array(
            'onepaycx_state' => $state,
            'onepaycx_refresh_url' => $this->context->link->getModuleLink(
                $this->module->name,
                'return',
                array('id_cart' => (int) $cart->id, 'key' => (string) $cart->secure_key),
                true
            ),
            'onepaycx_retry_url' => $this->context->link->getPageLink('order', true),
            'onepaycx_refresh_seconds' => 5,
        ));

        $this->setTemplate('module:onepaycx/views/templates/front/return.tpl');
    }

    /**
     * Poll the cart's payments, newest first, and complete the order on the first
     * one that is paid. Rows already known as failed/expired are not re-polled;
     * at most MAX_POLL still-pending links are asked about per page load.
     *
     * @param Cart $cart
     * @param array<int,array> $rows
     *
     * @return string 'redirected' | 'pending' | 'error' | 'failed' | 'expired'
     */
    private function pollPayments($cart, array $rows)
    {
        $api = $this->module->api();
        $anyPending = false;
        $latestFinal = '';
        $polled = 0;

        foreach ($rows as $row) {
            $paymentId = (string) $row['id_payment'];
            $stored = (string) $row['status'];
            if ($stored === 'failed' || $stored === 'expired') {
                if ($latestFinal === '') {
                    $latestFinal = $stored;
                }
                continue;
            }
            if ($polled >= self::MAX_POLL) {
                $anyPending = true;
                break;
            }
            ++$polled;

            $res = $api->getPayment($paymentId);
            if (!$res['ok'] || !is_array($res['data']) || !isset($res['data']['status'])) {
                // A transient API error keeps the page in "pending"; the refresh retries.
                $anyPending = true;
                continue;
            }
            $status = (string) $res['data']['status'];
            if ($status === 'paid') {
                if (isset($res['data']['currency']) && is_string($res['data']['currency'])
                    && strtoupper($res['data']['currency']) !== strtoupper((string) $row['currency'])) {
                    $this->module->log('return: currency mismatch on payment for cart ' . (int) $cart->id, 3);

                    return 'error';
                }
                // The order is validated with the total this shop quoted; the
                // reported amount may include a customer surcharge but never less.
                $reported = isset($res['data']['amount']) ? $res['data']['amount'] : null;
                if (!Onepaycx::reportedAmountCovers($reported, $row['amount'])) {
                    $this->module->log('return: amount ' . Onepaycx::formatAmount($reported)
                        . ' below quoted ' . (string) $row['amount'] . ' for cart ' . (int) $cart->id, 3);

                    return 'error';
                }
                $idOrder = $this->module->confirmPaidCart($cart, $paymentId, (string) $row['amount'], 'return');
                if ($idOrder <= 0) {
                    // The webhook may have created the order in the meantime.
                    $idOrder = Onepaycx::orderIdForCart($cart);
                }
                if ($idOrder > 0) {
                    Tools::redirect($this->orderConfirmationUrl($cart, $idOrder));

                    return 'redirected';
                }

                return 'error';
            }
            if ($status === 'failed' || $status === 'expired') {
                $this->module->setPaymentStatus($paymentId, $status);
                if ($latestFinal === '') {
                    $latestFinal = $status;
                }
                continue;
            }
            $anyPending = true;
        }

        if ($anyPending) {
            return 'pending';
        }

        return $latestFinal !== '' ? $latestFinal : 'pending';
    }

    /**
     * @param Cart $cart
     * @param int $idOrder
     *
     * @return string
     */
    private function orderConfirmationUrl($cart, $idOrder)
    {
        return $this->context->link->getPageLink('order-confirmation', true, null, array(
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $idOrder,
            'key' => (string) $cart->secure_key,
        ));
    }
}
