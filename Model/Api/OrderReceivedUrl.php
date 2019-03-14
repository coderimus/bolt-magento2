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
 * @copyright  Copyright (c) 2019 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Model\Api;

use Bolt\Boltpay\Api\OrderReceivedUrlInterface;

class OrderReceivedUrl implements OrderReceivedUrlInterface
{
    /**
     * @param null $blt_signature
     * @param null $blt_payload
     */
    public function execute(
        $blt_signature = null,
        $blt_payload = null
    ) {
        // TODO: Implement execute() method.
    }
}