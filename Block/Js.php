<?php
/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Block;

use Bolt\Boltpay\Helper\Config;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Bolt\Boltpay\Helper\Cart as CartHelper;

/**
 * Js Block. The block class used in replace.phtml and track.phtml blocks.
 *
 * @SuppressWarnings(PHPMD.DepthOfInheritance)
 */
class Js extends Template
{
    /**
     * @var Config
     */
    private $configHelper;

    /** @var CheckoutSession */
    private $checkoutSession;

    /**
     * @var CartHelper
     */
    private $cartHelper;

    /**
     * @param Context $context
     * @param Config $configHelper
     * @param CheckoutSession $checkoutSession
     * @param CartHelper $cartHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $configHelper,
        CheckoutSession $checkoutSession,
        CartHelper $cartHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->configHelper = $configHelper;
        $this->checkoutSession = $checkoutSession;
        $this->cartHelper = $cartHelper;
    }

    /**
     * Check if guest checkout is allowed
     *
     * @return bool
     */
    public function isCheckoutAllowed(){
        return $this->cartHelper->isCheckoutAllowed();
    }

    /**
     * Get track js url
     *
     * @return  string
     */
    public function getTrackJsUrl()
    {
        //Get cdn url
        $cdnUrl = $this->configHelper->getCdnUrl();
        return $cdnUrl.'/track.js';
    }

    /**
     * Get connect js url
     *
     * @return  string
     */
    public function getConnectJsUrl()
    {
        //Get cdn url
        $cdnUrl = $this->configHelper->getCdnUrl();
        return $cdnUrl.'/connect.js';
    }

    /**
     * Get checkout key. Any of the defined publishable keys for use with track.js.
     *
     * @return  string
     */
    public function getCheckoutKey()
    {
        return $this->configHelper->getAnyPublishableKey();
    }

    /**
     * Get Replace Button Selectors.
     *
     * @return string
     */
    public function getReplaceSelectors()
    {
        return array_filter(explode(',', preg_replace('/\s+/', ' ', trim($this->configHelper->getReplaceSelectors()))));
    }

    /**
     * Get Totals Change Selectors.
     *
     * @return string
     */
    public function getTotalsChangeSelectors()
    {
        return array_filter(explode(',', preg_replace('/\s+/', ' ', trim($this->configHelper->getTotalsChangeSelectors()))));
    }

    /**
     * Get Additional button class.
     *
     * @return string
     */
    public function getAdditionalCheckoutButtonClass()
    {
        return trim($this->configHelper->getAdditionalCheckoutButtonClass());
    }

    /**
     * Get Replace Button Selectors.
     *
     * @return string
     */
    public function getGlobalCSS()
    {
        return $this->configHelper->getGlobalCSS();
    }

    /**
     * Get Javascript function call on success.
     *
     * @return string
     */
    public function getJavascriptSuccess()
    {
        return $this->configHelper->getJavascriptSuccess();
    }

    /**
     * Get Additional Javascript.
     *
     * @return string
     */
    public function getAdditionalJavascript()
    {
        return $this->configHelper->getAdditionalJS();
    }

    /**
     * Gets the auto-open Bolt checkout session flag, and then unsets it so that it is only used once.
     *
     * @return bool
     */
    public function getInitiateCheckout()
    {
        $flag = $this->checkoutSession->getBoltInitiateCheckout();
        $this->checkoutSession->unsBoltInitiateCheckout();
        return (bool)$flag;
    }

    /**
     * Get Javascript page settings.
     * @return string
     */
    public function getSettings()
    {
        return json_encode([
            'connect_url'              => $this->getConnectJsUrl(),
            'publishable_key_payment'  => $this->configHelper->getPublishableKeyPayment(),
            'publishable_key_checkout' => $this->configHelper->getPublishableKeyCheckout(),
            'publishable_key_back_office' => $this->configHelper->getPublishableKeyBackOffice(),
            'create_order_url'         => $this->getUrl(Config::CREATE_ORDER_ACTION),
            'save_order_url'           => $this->getUrl(Config::SAVE_ORDER_ACTION),
            'selectors'                => $this->getReplaceSelectors(),
            'shipping_prefetch_url'    => $this->getUrl(Config::SHIPPING_PREFETCH_ACTION),
            'prefetch_shipping'        => $this->configHelper->getPrefetchShipping(),
            'save_email_url'           => $this->getUrl(Config::SAVE_EMAIL_ACTION),
            'quote_is_virtual'         => $this->getQuoteIsVirtual(),
            'totals_change_selectors'  => $this->getTotalsChangeSelectors(),
            'additional_checkout_button_class' => $this->getAdditionalCheckoutButtonClass(),
            'initiate_checkout'        => $this->getInitiateCheckout(),
            'toggle_checkout'          => $this->getToggleCheckout(),
        ]);
    }

    /**
     * Get Bolt Payment module active state.
     * @return bool
     */
    public function isEnabled()
    {
        return $this->configHelper->isActive();
    }

    /**
     * Get quote is virtual flag, false if no existing quote
     * @return bool
     */
    private function getQuoteIsVirtual()
    {
        $quote = $this->checkoutSession->getQuote();
        return $quote ? $quote->isVirtual() : false;
    }

    /**
     * @return string
     */
    public function getBoltPopupErrorMessage()
    {
        return __('Your payment was successful and we\'re now processing your order.' .
        'If you don\'t receive order confirmation email in next 30 minutes, please contact us at support@bolt.com');
    }

    /**
     * @return array
     */
    public function getTrackCallbacks()
    {
        return [
            'checkout_start' => $this->getOnCheckoutStart(),
            'email_enter' => $this->getOnEmailEnter(),
            'shipping_details_complete'=> $this->getOnShippingDetailsComplete(),
            'shipping_options_complete'=> $this->getOnShippingOptionsComplete(),
            'payment_submit'=> $this->getOnPaymentSubmit(),
            'success' => $this->getOnSuccess(),
            'close' => $this->getOnClose(),
        ];
    }

    /**
     * @return string
     */
    protected function getOnCheckoutStart()
    {
        return $this->configHelper->getOnCheckoutStart();
    }

    /**
     * @return string
     */
    protected function getOnEmailEnter()
    {
        return $this->configHelper->getOnEmailEnter();
    }

    /**
     * @return string
     */
    protected function getOnShippingDetailsComplete()
    {
        return $this->configHelper->getOnShippingDetailsComplete();
    }

    /**
     * @return string
     */
    protected function getOnShippingOptionsComplete()
    {
        return $this->configHelper->getOnShippingOptionsComplete();
    }

    /**
     * @return string
     */
    protected function getOnPaymentSubmit()
    {
        return $this->configHelper->getOnPaymentSubmit();
    }

    /**
     * @return string
     */
    protected function getOnSuccess()
    {
        return $this->configHelper->getOnSuccess();
    }

    /**
     * @return string
     */
    protected function getOnClose()
    {
        return $this->configHelper->getOnClose();
    }

    /**
     * Get Toggle Checkout configuration
     *
     * @return mixed
     */
    private function getToggleCheckout()
    {
        $toggleCheckout = $this->configHelper->getToggleCheckout();
        return $toggleCheckout && $toggleCheckout->active ? $toggleCheckout : null;
    }

    /**
     * Get blacklisted pages, stored in "pageFilters.blacklist" additional configuration
     * as an array of "Full Action Name" identifiers, [<router_controller_action>]
     *
     * @return array
     */
    private function getPageBlacklist()
    {
        return $this->configHelper->getPageBlacklist();
    }

    /**
     * Get whitelisted pages, the default, non cached, shopping cart and checkout pages,
     * and the pages stored in "pageFilters.whitelist" additional configuration,
     * as an array of "Full Action Name" identifiers, [<router_controller_action>]
     *
     * @return array
     */
    private function getPageWhitelist()
    {
        return array_unique(array_merge(Config::$defaultPageWhitelist, $this->configHelper->getPageWhitelist()));
    }

    /**
     * Check if Bolt checkout is restricted on the current loading page.
     * Takes into account Minicart support and whitelisted / blacklisted pages configuration
     * as well as the IP restriction.
     * "Full Action Name", <router_controller_action>, is used to identify the page.
     *
     * @return bool
     */
    private function isPageRestricted()
    {
        $currentPage = $this->getRequest()->getFullActionName();

        // Check if the page is blacklisted
        if (in_array($currentPage, $this->getPageBlacklist())) {
            return true;
        }

        // If minicart is supported (allowing Bolt on every page)
        // and no IP whitelist is defined there are no additional restrictions.
        if ($this->configHelper->getMinicartSupport() && !$this->configHelper->getIPWhitelistArray()) {
            return false;
        }

        // No minicart support or there is IP whitelist defined. Check if the page is whitelisted.
        // If IP whitelist is defined, the Bolt checkout functionality
        // must be limited to the non cached pages, shopping cart and checkout (internal or 3rd party).
        return ! in_array($currentPage, $this->getPageWhitelist());
    }

    /**
     * Check if the client IP is restricted -
     * there is an IP whitelist and the client IP is not on the list.
     *
     * @return bool
     */
    protected function isIPRestricted()
    {
        return $this->configHelper->isIPRestricted();
    }

    /**
     * Determines if Bolt javascript should be loaded on the current page
     * and Bolt checkout button displayed. Checks whether the module is active,
     * the page is Bolt checkout restricted and if there is an IP restriction.
     *
     * @return bool
     */
    public function shouldDisableBoltCheckout()
    {
        return !$this->isEnabled() || $this->isPageRestricted() || $this->isIPRestricted();
    }
}
