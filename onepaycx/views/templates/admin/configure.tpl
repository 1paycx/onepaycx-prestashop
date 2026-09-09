{*
 * 1pay.cx for PrestaShop — configuration page intro panel.
 *
 * @author    1pay.cx
 * @license   MIT (see LICENSE)
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-credit-card"></i> {l s='1pay.cx — pay by card or crypto' mod='onepaycx'}
  </div>
  <div class="panel-body">
    <p>{l s='Buyers pay on a hosted checkout by card, Bitcoin or Monero. Orders are confirmed automatically by a signed webhook — nothing to reconcile by hand.' mod='onepaycx'}</p>
    {if $onepaycx_connected}
      <p class="alert alert-success">
        {l s='Connected.' mod='onepaycx'}
        {if $onepaycx_merchant_id}{l s='Merchant ID:' mod='onepaycx'} <code>{$onepaycx_merchant_id|escape:'html':'UTF-8'}</code>{/if}
      </p>
    {else}
      <p class="alert alert-warning">{l s='Not connected yet. Paste your API key below and save — the key is verified and the webhook is configured automatically.' mod='onepaycx'}</p>
    {/if}
    <ol>
      <li>{l s='Sign in at 1pay.cx, open Settings -> API keys and copy your key (pk_...).' mod='onepaycx'}</li>
      <li>{l s='Paste it below and click Save.' mod='onepaycx'}</li>
      <li>{l s='Enable the module for your currencies under Payment -> Preferences if it is not already.' mod='onepaycx'}</li>
    </ol>
    <p class="help-block">
      {l s='Webhook URL (passed with each payment automatically; no manual setup):' mod='onepaycx'}
      <code>{$onepaycx_webhook_url|escape:'html':'UTF-8'}</code>
    </p>
  </div>
</div>
