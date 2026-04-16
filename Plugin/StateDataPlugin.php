<?php
declare(strict_types=1);

/**
 * Rameera_Payment
 *
 * @category  Rameera
 * @package   Rameera\Payment
 * @author    Rameera <arjundhiman90@gmail.com>
 * @copyright 2024 Rameera
 * @license   MIT
 */

namespace Rameera\Payment\Plugin;
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
