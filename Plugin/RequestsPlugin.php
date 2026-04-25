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

use Adyen\Payment\Helper\Requests;
use Psr\Log\LoggerInterface;

class RequestsPlugin {

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
     * @param Requests $subject
     * @param Int $storeId
     * @param $payment
     * @return array
     */
    public function beforeBuildCardRecurringData(Requests $subject, Int $storeId, $payment) {
        try {
            if($payment->getMethod() == 'adyen_cc') {
               $payment->setCcNumber("");
            }
        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
        }
        return null;
    }
}
