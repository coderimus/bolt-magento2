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
 * @copyright  Copyright (c) 2017-2020 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Plugin\Mirasvit\Credit\Model\Total\Quote;

use Magento\Checkout\Model\Session as CheckoutSession;
use Bolt\Boltpay\Helper\Discount as DiscountHelper;

/**
 * Class CreditPlugin
 *
 * @package Bolt\Boltpay\Plugin
 */
class BoltMSCCreditPlugin
{
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    
    /**
     * @var DiscountHelper
     */
    private $discountHelper;

    /**
     * MirasvitCreditQuotePaymentImportDataBeforePlugin constructor.
     *
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        DiscountHelper $discountHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->discountHelper = $discountHelper;
    }

    /**
     * @param ObserverInterface $subject
     * @param Observer          $observer
     *
     * @return mixed
     */
    public function beforeCollect(
        \Mirasvit\Credit\Model\Total\Quote\Credit $subject,
        $quote,
        $shippingAssignment,
        $total   
    ) {
        $miravitCalculationConfig = $this->discountHelper->getMirasvitStoreCreditCalculationConfigInstance();

        if ($miravitCalculationConfig->IsShippingIncluded($quote->getStore())) {
            $address = $shippingAssignment->getShipping()->getAddress();
            $beforeShippingDiscountAmount = $address->getShippingDiscountAmount();
            $this->checkoutSession->setBeforeMirasvitStoreCreditShippingDiscountAmount($beforeShippingDiscountAmount);
        }

        return [$quote, $shippingAssignment, $total];
    }
    
    public function afterCollect(
        \Mirasvit\Credit\Model\Total\Quote\Credit $subject,
        $result,
        $quote,
        $shippingAssignment,
        $total   
    ) {
        $miravitCalculationConfig = $this->discountHelper->getMirasvitStoreCreditCalculationConfigInstance();

        if ($miravitCalculationConfig->IsShippingIncluded($quote->getStore())) {
            $address = $shippingAssignment->getShipping()->getAddress();
            $afterShippingDiscountAmount = $address->getShippingDiscountAmount();
            $beforeShippingDiscountAmount = $this->checkoutSession->getBeforeMirasvitStoreCreditShippingDiscountAmount();
            $mirasvitStoreCreditShippingDiscountAmount = $afterShippingDiscountAmount - $beforeShippingDiscountAmount;
            if($mirasvitStoreCreditShippingDiscountAmount > 0) {
                $this->checkoutSession->setMirasvitStoreCreditShippingDiscountAmount($mirasvitStoreCreditShippingDiscountAmount);
            }            
        }

        return $result;
    }
}
