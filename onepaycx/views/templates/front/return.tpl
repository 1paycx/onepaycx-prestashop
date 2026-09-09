{*
 * 1pay.cx for PrestaShop — return page while the payment is not yet confirmed.
 *
 * @author    1pay.cx
 * @license   MIT (see LICENSE)
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {if $onepaycx_state == 'pending'}
    {l s='Confirming your payment' mod='onepaycx'}
  {elseif $onepaycx_state == 'failed' || $onepaycx_state == 'expired'}
    {l s='Payment not completed' mod='onepaycx'}
  {else}
    {l s='Payment status' mod='onepaycx'}
  {/if}
{/block}

{block name='page_content'}
  <section class="onepaycx-return">
    {if $onepaycx_state == 'pending'}
      <p>{l s='We are waiting for your payment to be confirmed. This page refreshes automatically; crypto payments may take a few minutes.' mod='onepaycx'}</p>
      <p><a class="btn btn-primary" href="{$onepaycx_refresh_url|escape:'html':'UTF-8'}">{l s='Refresh now' mod='onepaycx'}</a></p>
      <script>
        setTimeout(function () { window.location.replace('{$onepaycx_refresh_url|escape:'javascript'}'); }, {$onepaycx_refresh_seconds|intval} * 1000);
      </script>
    {elseif $onepaycx_state == 'expired'}
      <p class="alert alert-warning">{l s='The payment link expired before the payment was completed. Your cart is still here — you can try again.' mod='onepaycx'}</p>
      <p><a class="btn btn-primary" href="{$onepaycx_retry_url|escape:'html':'UTF-8'}">{l s='Back to checkout' mod='onepaycx'}</a></p>
    {elseif $onepaycx_state == 'failed'}
      <p class="alert alert-danger">{l s='The payment did not go through. Your cart is still here — you can try again or choose another payment method.' mod='onepaycx'}</p>
      <p><a class="btn btn-primary" href="{$onepaycx_retry_url|escape:'html':'UTF-8'}">{l s='Back to checkout' mod='onepaycx'}</a></p>
    {elseif $onepaycx_state == 'error'}
      <p class="alert alert-danger">{l s='Your payment was received but the order could not be created automatically. Please contact us and quote your cart number so we can finish it for you.' mod='onepaycx'}</p>
    {else}
      <p class="alert alert-warning">{l s='We could not find a payment for this cart.' mod='onepaycx'}</p>
      <p><a class="btn btn-primary" href="{$onepaycx_retry_url|escape:'html':'UTF-8'}">{l s='Back to checkout' mod='onepaycx'}</a></p>
    {/if}
  </section>
{/block}
