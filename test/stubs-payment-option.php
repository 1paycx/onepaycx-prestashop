<?php
/**
 * Stub of PrestaShop\PrestaShop\Core\Payment\PaymentOption for the compile
 * harness (only the setters the module calls). Lives in its own file because a
 * namespaced stub cannot share a file with the global-namespace ones.
 *
 * @license MIT
 */

namespace PrestaShop\PrestaShop\Core\Payment;

class PaymentOption
{
    public $moduleName;
    public $callToActionText;
    public $action;
    public $additionalInformation;
    public $logo;

    public function setModuleName($v)
    {
        $this->moduleName = $v;

        return $this;
    }

    public function setCallToActionText($v)
    {
        $this->callToActionText = $v;

        return $this;
    }

    public function setAction($v)
    {
        $this->action = $v;

        return $this;
    }

    public function setAdditionalInformation($v)
    {
        $this->additionalInformation = $v;

        return $this;
    }

    public function setLogo($v)
    {
        $this->logo = $v;

        return $this;
    }
}
