<?php
declare(strict_types=1);

/**
 * MageMatch_Payment
 *
 * @category  MageMatch
 * @package   MageMatch\Payment
 * @author    MageMatch <arjundhiman90@gmail.com>
 * @copyright 2024 MageMatch
 * @license   MIT
 */

namespace MageMatch\Payment\Plugin;
use Adyen\Payment\Helper\StateData;

class StateDataPlugin
{
    /**
     * @param StateData $stateData
     * @param callable $proceed
     * @param int $quoteId
     * @return string
     */
    public function aroundGetPaymentMethodVariant(StateData $stateData, Callable $proceed, int $quoteId):string
    {
        $stateData = $stateData->getStateData($quoteId);
        $paymentType = $stateData['paymentMethod']['type'] ?? '';
        if (($paymentType == '')) {
            return $paymentType;
        }else {
            return $proceed($quoteId);
        }
    }
}
