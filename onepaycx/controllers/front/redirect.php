<?php
/**
 * 1pay.cx for PrestaShop — checkout -> hosted payment page.
 *
 * Creates the payment on 1pay.cx (POST /v1/payments) for the current cart and
 * redirects the buyer to https://1pay.cx{checkoutUrl}. No order is created yet:
 * the order is validated when the paid notify arrives (webhook.php) or when the
 * return page sees the payment as paid (return.php).
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OnepaycxRedirectModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active
            || !$cart
            || (int) $cart->id_customer === 0
            || (int) $cart->id_address_delivery === 0
            || (int) $cart->id_address_invoice === 0) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        // The module may be restricted per country/group/carrier — only proceed if
        // PrestaShop still lists it as an available payment module.
        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if (isset($paymentModule['name']) && $paymentModule['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized || !$this->module->isConfigured() || !$this->module->checkCurrency($cart)) {
            $this->errors[] = $this->module->l('This payment method is not available. Please choose another one.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=1');

            return;
        }

        // A cart that already turned into an order must not be paid twice.
        $existingOrder = Onepaycx::orderIdForCart($cart);
        if ($existingOrder > 0) {
            Tools::redirect($this->orderConfirmationUrl($cart, $existingOrder));

            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $currency = new Currency((int) $cart->id_currency);
        $iso = strtoupper((string) $currency->iso_code);
        $amount = Onepaycx::formatAmount($cart->getOrderTotal(true, Cart::BOTH));
        if ((float) $amount <= 0 || strlen($iso) !== 3) {
            $this->errors[] = $this->module->l('This cart cannot be paid online.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=1');

            return;
        }

        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $body = array(
            // The API takes a JSON number; the two-decimal string is what we store.
            'amount' => (float) $amount,
            'currency' => $iso,
            'productName' => ($shopName !== '' ? $shopName : 'Store') . ' — ' . sprintf($this->module->l('cart #%d', 'redirect'), (int) $cart->id),
            'reference' => (string) (int) $cart->id,
            'notifyUrl' => $this->context->link->getModuleLink($this->module->name, 'webhook', array(), true),
            'redirectUrl' => $this->context->link->getModuleLink(
                $this->module->name,
                'return',
                array('id_cart' => (int) $cart->id, 'key' => (string) $cart->secure_key),
                true
            ),
        );
        if (!empty($customer->email) && Validate::isEmail($customer->email)) {
            $body['email'] = (string) $customer->email;
        }

        $res = $this->module->api()->createPayment($body);
        $url = null;
        $paymentId = '';
        if ($res['ok'] && is_array($res['data'])) {
            $url = OnepaycxApi::absoluteCheckoutUrl(isset($res['data']['checkoutUrl']) ? $res['data']['checkoutUrl'] : '');
            $paymentId = isset($res['data']['id']) ? (string) $res['data']['id'] : '';
        }
        if ($url === null || $paymentId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentId)) {
            $this->module->log('create payment failed for cart ' . (int) $cart->id . ' (' . ($res['error'] !== '' ? $res['error'] : 'bad response') . ')', 3);
            $this->errors[] = $this->module->l('The payment could not be started. Please try again or choose another payment method.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=1');

            return;
        }

        // Without this row the paid notify could never be matched to the cart, so
        // a buyer must not be sent to pay before it is safely stored.
        if (!$this->module->savePayment((int) $cart->id, $paymentId, $amount, $iso)) {
            $this->module->log('could not store payment for cart ' . (int) $cart->id, 3);
            $this->errors[] = $this->module->l('The payment could not be started. Please try again or choose another payment method.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=1');

            return;
        }

        Tools::redirect($url);
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
