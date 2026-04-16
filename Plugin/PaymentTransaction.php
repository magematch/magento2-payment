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

use Adyen\Payment\Gateway\Http\Client\TransactionPayment;
use Psr\Log\LoggerInterface;

class PaymentTransaction {

    /**
     * @var LoggerInterface
     */
    private  $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * @param TransactionPayment $subject
     * @param $result
     * @param \Magento\Payment\Gateway\Http\TransferInterface $transferObject
     * @return mixed
     */

    public function afterPlaceRequest(TransactionPayment $subject, $result, \Magento\Payment\Gateway\Http\TransferInterface $transferObject) {
        try {
            $errorCode = $result['errorCode']??'';
            if($errorCode && $errorCode!="") {
                $result['resultCode'] = "Pending";
            }
            return $result;

        } catch (LocalizedException $e) {
            $this->logger->critical($e->getMessage());
        }
    }
}
