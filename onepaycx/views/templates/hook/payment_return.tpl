{*
 * 1pay.cx for PrestaShop — block on the order-confirmation page.
 *
 * @author    1pay.cx
 * @license   MIT (see LICENSE)
 *}
<section class="onepaycx-payment-return">
  {if $onepaycx_paid}
    <p>{l s='Your payment was received. Thank you for your order at %s.' sprintf=[$onepaycx_shop_name] mod='onepaycx'}</p>
  {else}
    <p>{l s='Your order has been recorded. Its status will update as soon as your payment is confirmed.' mod='onepaycx'}</p>
  {/if}
  {if $onepaycx_reference}
    <p>{l s='Order reference:' mod='onepaycx'} <strong>{$onepaycx_reference|escape:'html':'UTF-8'}</strong></p>
  {/if}
</section>
