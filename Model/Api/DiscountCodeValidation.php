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

namespace Bolt\Boltpay\Model\Api;

use Bolt\Boltpay\Api\DiscountCodeValidationInterface;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Shared\CurrencyUtils;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebApiException;

/**
 * Discount Code Validation class
 * @api
 */
class DiscountCodeValidation extends UpdateCartCommon implements DiscountCodeValidationInterface
{
    use UpdateDiscountTrait {
        __construct as private UpdateDiscountTraitConstructor;
        applyingGiftCardCode as private UpdateDiscountTraitApplyingGiftCardCode;
    }
    
    /**
     * @var array
     */
    private $requestArray;

    /**
     * DiscountCodeValidation constructor.
     *
     * @param UpdateCartContext          $updateCartContext
     */
    public function __construct(
        UpdateCartContext $updateCartContext
    ) {
        parent::__construct($updateCartContext);
        $this->UpdateDiscountTraitConstructor($updateCartContext);
    }

    /**
     * @api
     * @return bool
     * @throws \Exception
     */
    public function validate()
    {
        try {
            $request = $this->getRequestContent();

            $requestArray = json_decode(json_encode($request), true);
            if (isset($requestArray['cart']['order_reference'])) {
                $parentQuoteId = $requestArray['cart']['order_reference'];
                $immutableQuoteId = $this->cartHelper->getImmutableQuoteIdFromBoltCartArray($requestArray['cart']);
                if (!$immutableQuoteId) {
                    $immutableQuoteId = $parentQuoteId;
                }                
            } else {
                $this->sendErrorResponse(
                    BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                    'The cart.order_reference is not set or empty.',
                    404
                );
                return false;
            }
            
            // Get the coupon code
            $discount_code = $requestArray['discount_code'] ?? $requestArray['cart']['discount_code'] ?? null;
            $couponCode = trim($discount_code);

            // Check if empty coupon was sent
            if ($couponCode === '') {
                $this->sendErrorResponse(
                    BoltErrorResponse::ERR_CODE_INVALID,
                    'No coupon code provided',
                    422
                );

                return false;
            }
            
            $this->requestArray = $requestArray;

            $result = $this->validateQuote($immutableQuoteId);
            if(!$result){
                // Already sent a response with error, so just return.
                return false;
            }
                
            list($parentQuote, $immutableQuote) = $result;
            
            $storeId = $parentQuote->getStoreId();
            $websiteId = $parentQuote->getStore()->getWebsiteId();

            $this->preProcessWebhook($storeId);
            
            $parentQuote->getStore()->setCurrentCurrencyCode($parentQuote->getQuoteCurrencyCode());
            
            // Set the shipment if request payload has that info.
            if (!empty($requestArray['cart']['shipments'][0]['reference'])) {
                $this->setShipment($requestArray['cart']['shipments'][0], $immutableQuote);
            }

            // Verify if the code is coupon or gift card and return proper object
            $result = $this->verifyCouponCode($couponCode, $websiteId, $storeId);
            
            if( ! $result ){
                // Already sent a response with error, so just return.
                return false;
            }
            
            list($coupon, $giftCard) = $result;

            $this->eventsForThirdPartyModules->dispatchEvent("beforeApplyDiscount", $parentQuote);

            if ($coupon && $coupon->getCouponId()) {
                if ($this->shouldUseParentQuoteShippingAddressDiscount($couponCode, $immutableQuote, $parentQuote)) {
                    $result = $this->getParentQuoteDiscountResult($couponCode, $coupon, $parentQuote);
                } else {
                    $result = $this->applyingCouponCode($couponCode, $coupon, $immutableQuote, $parentQuote);
                }
            } elseif ($giftCard && $giftCard->getId()) {
                $result = $this->applyingGiftCardCode($couponCode, $giftCard, $immutableQuote, $parentQuote);
            } else {
                throw new WebApiException(__('Something happened with current code.'));
            }

            if (!$result || (isset($result['status']) && $result['status'] === 'error')) {
                // Already sent a response with error, so just return.
                return false;
            }

            //remove previously cached Bolt order since we altered related immutable quote by applying a discount
            $this->cache->clean([CartHelper::BOLT_ORDER_TAG . '_' . $parentQuoteId]);

            $this->sendSuccessResponse($result, $immutableQuote);
        } catch (WebApiException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                $e->getHttpCode(),
                ($immutableQuote) ? $immutableQuote : null
            );

            return false;
        } catch (LocalizedException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        } catch (\Exception $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        }

        return true;
    }

    /**
     * @param $code
     * @param \Magento\GiftCardAccount\Model\Giftcardaccount|\Unirgy\Giftcert\Model\Cert $giftCard
     * @param Quote $immutableQuote
     * @param Quote $parentQuote
     * @return array
     * @throws \Exception
     */
    private function applyingGiftCardCode($code, $giftCard, $immutableQuote, $parentQuote)
    {
        $result = [];
        try {
            if ($giftCard instanceof \Amasty\GiftCard\Model\Account || $giftCard instanceof \Amasty\GiftCardAccount\Model\GiftCardAccount\Account) {
                // Remove Amasty Gift Card if already applied
                // to avoid errors on multiple calls to discount validation API
                // from the Bolt checkout (changing the address, going back and forth)
                $this->discountHelper->removeAmastyGiftCard($giftCard->getCodeId(), $parentQuote);
                // Apply Amasty Gift Card to the parent quote
                $giftAmount = $this->discountHelper->applyAmastyGiftCard($code, $giftCard, $parentQuote);
                // Reset and apply Amasty Gift Cards to the immutable quote
                $this->discountHelper->cloneAmastyGiftCards($parentQuote->getId(), $immutableQuote->getId());
            } elseif ($giftCard instanceof \Unirgy\Giftcert\Model\Cert) {
                if (empty($immutableQuote->getData($giftCard::GIFTCERT_CODE))) {
                    $this->discountHelper->addUnirgyGiftCertToQuote($immutableQuote, $giftCard);
                }

                if (empty($parentQuote->getData($giftCard::GIFTCERT_CODE))) {
                    $this->discountHelper->addUnirgyGiftCertToQuote($parentQuote, $giftCard);
                }

                // The Unirgy_GiftCert require double call the function addCertificate().
                // Look on Unirgy/Giftcert/Controller/Checkout/Add::execute()
                $this->discountHelper->addUnirgyGiftCertToQuote($this->checkoutSession->getQuote(), $giftCard);

                $giftAmount = $giftCard->getBalance();
            } elseif ($giftCard instanceof \Magento\GiftCardAccount\Model\Giftcardaccount) {
                if ($immutableQuote->getGiftCardsAmountUsed() == 0) {
                    try {
                        // on subsequest validation calls from Bolt checkout
                        // try removing the gift card before adding it
                        $giftCard->removeFromCart(true, $immutableQuote);
                    } catch (\Exception $e) {
                        // gift card not added yet
                    } finally {
                        $giftCard->addToCart(true, $immutableQuote);
                    }
                }

                if ($parentQuote->getGiftCardsAmountUsed() == 0) {
                    try {
                        // on subsequest validation calls from Bolt checkout
                        // try removing the gift card before adding it
                        $giftCard->removeFromCart(true, $parentQuote);
                    } catch (\Exception $e) {
                        // gift card not added yet
                    } finally {
                        $giftCard->addToCart(true, $parentQuote);
                    }
                }

                // Send the whole GiftCard Amount.
                $giftAmount = $parentQuote->getGiftCardsAmount();
            } else {
                // TODO: move all cases above into filter
                $result = $this->eventsForThirdPartyModules->runFilter("applyGiftcard", null, $code, $giftCard, $immutableQuote, $parentQuote);
                if (empty($result)) {
                    throw new \Exception('Unknown giftCard class');
                }
                if ($result['status']=='failure') {
                    throw new \Exception($result['error_message']);
                }
            }
        } catch (\Exception $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                422,
                $immutableQuote
            );

            return false;
        }

        if (!$result) {
            $result = [
                'status'          => 'success',
                'discount_code'   => $code,
                'discount_amount' => abs(CurrencyUtils::toMinor($giftAmount, $immutableQuote->getQuoteCurrencyCode())),
                'description'     =>  __('Gift Card'),
                'discount_type'   => $this->discountHelper->getBoltDiscountType('by_fixed'),
            ];
        }

        $this->logHelper->addInfoLog('### Gift Card Result');
        $this->logHelper->addInfoLog(json_encode($result));

        return $result;
    }

    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    private function getCartTotals($quote)
    {
        $is_has_shipment = !empty($this->requestArray['cart']['shipments'][0]['reference']);
        $cart = $this->cartHelper->getCartData($is_has_shipment, null, $quote);
        return [
            'total_amount' => $cart['total_amount'],
            'tax_amount'   => $cart['tax_amount'],
            'discounts'    => $cart['discounts'],
        ];
    }

    /**
     * @param int        $errCode
     * @param string     $message
     * @param int        $httpStatusCode
     * @param null|Quote $quote
     *
     * @return void
     * @throws \Exception
     */
    protected function sendErrorResponse($errCode, $message, $httpStatusCode, $quote = null)
    {
        $additionalErrorResponseData = [];
        if ($quote) {
            $additionalErrorResponseData['cart'] = $this->getCartTotals($quote);
        }

        $encodeErrorResult = $this->errorResponse
            ->prepareErrorMessage($errCode, $message, $additionalErrorResponseData);

        $this->logHelper->addInfoLog('### sendErrorResponse');
        $this->logHelper->addInfoLog($encodeErrorResult);
        
        $this->bugsnag->notifyException(new \Exception($message));

        $this->response->setHttpResponseCode($httpStatusCode);
        $this->response->setBody($encodeErrorResult);
        $this->response->sendResponse();
    }

    /**
     * @param array $result
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function sendSuccessResponse($result, $quote = null)
    {
        $result['cart'] = $this->getCartTotals($quote);

        $this->response->setBody(json_encode($result));
        $this->response->sendResponse();

        $this->logHelper->addInfoLog('### sendSuccessResponse');
        $this->logHelper->addInfoLog(json_encode($result));
        $this->logHelper->addInfoLog('=== END ===');

        return $result;
    }

    

    /**
     * @param string $couponCode
     * @param Quote  $immutableQuote
     * @param Quote  $parentQuote
     *
     * @return bool
     */
    protected function shouldUseParentQuoteShippingAddressDiscount(
        $couponCode,
        \Magento\Quote\Model\Quote $immutableQuote,
        \Magento\Quote\Model\Quote $parentQuote
    ) {
        $ignoredShippingAddressCoupons = $this->configHelper->getIgnoredShippingAddressCoupons(
            $parentQuote->getStoreId()
        );

        return $immutableQuote->getCouponCode() == $couponCode &&
               $immutableQuote->getCouponCode() == $parentQuote->getCouponCode() &&
               in_array($couponCode, $ignoredShippingAddressCoupons);
    }

    /**
     * @param string $couponCode
     * @param Quote  $parentQuote
     * @param Coupon $coupon
     *
     * @return array|false
     * @throws \Exception
     */
    protected function getParentQuoteDiscountResult($couponCode, $coupon, $parentQuote)
    {
        try {
            // Load the coupon discount rule
            $rule = $this->ruleRepository->getById($coupon->getRuleId());
        } catch (NoSuchEntityException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_CODE_INVALID,
                sprintf('The coupon code %s is not found', $couponCode),
                404
            );

            return false;
        }

        $address = $parentQuote->isVirtual() ? $parentQuote->getBillingAddress() : $parentQuote->getShippingAddress();

        return $result = [
            'status'          => 'success',
            'discount_code'   => $couponCode,
            'discount_amount' => abs(CurrencyUtils::toMinor($address->getDiscountAmount(), $parentQuote->getQuoteCurrencyCode())),
            'description'     =>  __('Discount ') . $address->getDiscountDescription(),
            'discount_type'   => $this->discountHelper->convertToBoltDiscountType($couponCode),
        ];
    }
}
