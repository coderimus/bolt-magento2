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

namespace Bolt\Boltpay\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Hook as HookHelper;
use Magento\Quote\Model\Quote;
use Magento\Framework\Webapi\Exception as WebApiException;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Directory\Model\Region as RegionModel;
use Bolt\Boltpay\Helper\Order as OrderHelper;
use Bolt\Boltpay\Model\Api\UpdateCartContext;

/**
 * Class UpdateCartCommon
 * 
 * @package Bolt\Boltpay\Model\Api
 */
abstract class UpdateCartCommon
{   
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var LogHelper
     */
    protected $logHelper;

    /**
     * @var Bugsnag
     */
    protected $bugsnag;

    /**
     * @var CartHelper
     */
    protected $cartHelper;

    /**
     * @var HookHelper
     */
    protected $hookHelper;

    /**
     * @var BoltErrorResponse
     */
    protected $errorResponse;

    /**
     * @var RegionModel
     */
    protected $regionModel;

    /**
     * @var OrderHelper
     */
    protected $orderHelper;
    
    /**
     * UpdateCartCommon constructor.
     *
     * @param UpdateCartContext $updateCartContext
     */
    public function __construct(
        UpdateCartContext $updateCartContext
    ) {
        $this->request = $updateCartContext->getRequest();
        $this->response = $updateCartContext->getResponse();
        $this->hookHelper = $updateCartContext->getHookHelper();
        $this->errorResponse = $updateCartContext->getBoltErrorResponse();
        $this->logHelper = $updateCartContext->getLogHelper();   
        $this->bugsnag = $updateCartContext->getBugsnag();
        $this->regionModel = $updateCartContext->getRegionModel();
        $this->orderHelper = $updateCartContext->getOrderHelper();
        $this->cartHelper = $updateCartContext->getCartHelper();
    }
    
    /**
     * Get the quote id from request payload and validate the related quote.
     *
     * @param  object $request
     * @return bool
     */
    protected function validateQuote( $request ){
        if (!empty($request->cart->order_reference)) {
            $parentQuoteId = $request->cart->order_reference;
            $displayId = !empty($request->cart->display_id) ? $request->cart->display_id : '';
            // check if the cart / quote exists and it is active
            try {
                // get parent quote id, order increment id and child quote id
                // the latter two are transmitted as display_id field, separated by " / "
                list($incrementId, $immutableQuoteId) = array_pad(
                    explode(' / ', $displayId),
                    2,
                    null
                );

                if (!$immutableQuoteId) {
                    $immutableQuoteId = $parentQuoteId;
                }

                /** @var Quote $parentQuote */
                if ($immutableQuoteId == $parentQuoteId) {
                    // Product Page Checkout - quotes are created as inactive
                    $parentQuote = $this->cartHelper->getQuoteById($parentQuoteId);
                } else {
                    $parentQuote = $this->cartHelper->getActiveQuoteById($parentQuoteId);
                }

                // check if cart identification data is sent
                if (empty($parentQuoteId) || empty($incrementId) || empty($immutableQuoteId)) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                        'The order reference is invalid.',
                        422
                    );

                    return false;
                }
                
                // check if the order has already been created
                if ($this->orderHelper->getExistingOrder($incrementId)) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                        sprintf('The order #%s has already been created.', $incrementId),
                        422
                    );
                    return false;
                }
    
                // check the existence of child quote
                $immutableQuote = $this->cartHelper->getQuoteById($immutableQuoteId);
                if (!$immutableQuote) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                        sprintf('The cart reference [%s] is not found.', $immutableQuoteId),
                        404
                    );
                    return false;
                }
    
                // check if cart is empty
                if (!$immutableQuote->getItemsCount()) {
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                        sprintf('The cart for order reference [%s] is empty.', $immutableQuoteId),
                        422
                    );
    
                    return false;
                }
                
                return [
                    $parentQuoteId,
                    $incrementId,
                    $immutableQuoteId,
                    $parentQuote,
                    $immutableQuote,
                ];

            } catch (\Exception $e) {
                $this->bugsnag->notifyException($e);
                $this->sendErrorResponse(
                    BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                    sprintf('The cart reference [%s] is not found.', $parentQuoteId),
                    404
                );
                return false;
            }
        } else {
            $this->bugsnag->notifyError(
                BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                'The cart.order_reference is not set or empty.'
            );
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_INSUFFICIENT_INFORMATION,
                'The cart reference is not found.',
                404
            );
            return false;
        }
    }
    
    // Set the shipment if request payload has that info.
    protected function setShipment($request, $immutableQuote)
    {        
        if (isset($request->cart->shipments[0]->reference)) {
            $shippingAddress = $immutableQuote->getShippingAddress();
            $address = $request->cart->shipments[0]->shipping_address;
            $address = $this->cartHelper->handleSpecialAddressCases($address);
            $region = $this->regionModel->loadByName(@$address->region, @$address->country_code);
            $addressData = [
                        'firstname'    => @$address->first_name,
                        'lastname'     => @$address->last_name,
                        'street'       => trim(@$address->street_address1 . "\n" . @$address->street_address2),
                        'city'         => @$address->locality,
                        'country_id'   => @$address->country_code,
                        'region'       => @$address->region,
                        'postcode'     => @$address->postal_code,
                        'telephone'    => @$address->phone_number,
                        'region_id'    => $region ? $region->getId() : null,
                        'company'      => @$address->company,
                    ];
            if ($this->cartHelper->validateEmail(@$address->email_address)) {
                $addressData['email'] = $address->email_address;
            }
    
            $shippingAddress->setShouldIgnoreValidation(true);
            $shippingAddress->addData($addressData);
    
            $shippingAddress
                ->setShippingMethod($request->cart->shipments[0]->reference)
                ->setCollectShippingRates(true)
                ->collectShippingRates()
                ->save();
        }
    }

    /**
     * @param null|int $storeId
     * @throws LocalizedException
     * @throws WebApiException
     */
    public function preProcessWebhook($storeId = null)
    {
        $this->hookHelper->preProcessWebhook($storeId);
    }

    /**
     * @return array
     */
    protected function getRequestContent()
    {
        $this->logHelper->addInfoLog($this->request->getContent());

        return json_decode($this->request->getContent());
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
    abstract protected function sendErrorResponse($errCode, $message, $httpStatusCode, $quote = null);

    /**
     * @param array $result
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    abstract protected function sendSuccessResponse($result, $quote, $request);

}
