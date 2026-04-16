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

namespace Rameera\Payment\Model\Resolver;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Psr\Log\LoggerInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Exception;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Payment Retry field resolver, used for GraphQL request processing
 */
class PaymentRetry implements ResolverInterface
{
    /**
     * Const for XML Path of store configurations
     */
    const XML_PATH_PAYMENT_RETRY_THRESHOLD = 'payment/payment_additional_configurations/allowed_threshold_to_attempt_retry';
    const XML_PATH_PAYMENT_RETRY_ATTEMPTS  = 'payment/payment_additional_configurations/allowed_retry_attempts';
    const FAILED_PAYMENT_STATUS  = 'payment_failed';
    const ELIGIBILITY_FAIL_CART_UPDATE_FAIL = 'User is not eligible for retry payment and cart state is unchanged. To change the state of cart please try again without eligibility check';
    const ELIGIBILITY_PASS_CART_UPDATE_PASS = 'User is eligible for payment retry and cart has been activated successfully';
    const PAYMENT_ORDER_CANCELLED_WITH_EXHAUSTED = 'Maximum attempt of Payment Retry or Threshold exhausted, So Order has been Cancelled';
    const PAYMENT_SUCCESS_ORDER_CONFIRMED = "Payment is successfully done for current order, so not eligible for payment retry.";
    const ERROR_PAYMENT_RETRY_NOT_ALLOWED = 405;
    const SUCCESS_PAYMENT_RETRY_ALLOWED = 200;
    const STATUS_SUCCESS = "success";
    const STATUS_FAIL    = "error";

    /**
     * @var OrderRepositoryInterface
     */
    protected $order;

    /**
     * @var ScopeConfigInterface
     */
    private  $scopeConfig;

    /**
     * @var OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * @var LoggerInterface
     */
    private  $logger;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $orderStatusRepository;

    /**
     * @var OrderPaymentRepositoryInterface
     */
    protected $orderPaymentRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderManagementInterface $orderManagement
     * @param OrderStatusHistoryRepositoryInterface $orderStatusRepository
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     */

    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        OrderManagementInterface $orderManagement,
        OrderStatusHistoryRepositoryInterface $orderStatusRepository,
        OrderPaymentRepositoryInterface $orderPaymentRepository,
        OrderRepositoryInterface $order,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderStatusRepository = $orderStatusRepository;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->orderManagement = $orderManagement;
        $this->orderPaymentRepository = $orderPaymentRepository;
        $this->order = $order;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
              $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
            if (!isset($args['input']['orderId'])) {
                throw new GraphQlInputException(__('Order id needs to be specified'));
            }
            $availableRetryAttempts = 0;
            $burstTime = 0;
            $orderIncrementId = $args['input']['orderId'];
            $payResponse = $args['input']['payResponse'] ?? '';
            $paymentAttempt = $args['input']['paymentSuccess'] ?? '';
            try {
                $orders = $this->getOrderIdByIncrementId($orderIncrementId);
                foreach ($orders as $order) {
                    $allowedRetryAttempts = $this->getRetryAttempts((int)$order->getStoreId());
                    $burstTime = $this->getBurstTime($order->getCreatedAt(), (int)$order->getStoreId());
                    $retryAttempt = (int)$order->getPayment()->getAdditionalInformation('retry_attempts');
                    $availableRetryAttempts = $allowedRetryAttempts - $retryAttempt;

                    if ($paymentAttempt && $paymentAttempt != '' && $payResponse && $payResponse != '') {
                        $payment = $order->getPayment();
                        if (!$retryAttempt) {
                            $retryAttempt = $allowedRetryAttempts;
                        }
                        $retryAttempt = $retryAttempt - 1;
                        $availableRetryAttempts = $retryAttempt;
                        $payment->setAdditionalInformation("retry_attempts", $retryAttempt);
                        $message = __("User has tried retry payment and received response: " . json_encode($payResponse));
                        $this->setOrderComment($order, (string)$message);
                        $this->orderPaymentRepository->save($payment);
                    }
                   return $this->paymentRetry($order, $paymentAttempt, $availableRetryAttempts, $burstTime);
                }
                $message = self::ELIGIBILITY_PASS_CART_UPDATE_PASS;
            } catch (\GraphQlNoSuchEntityException $e) {
                $this->logger->critical($e->getMessage());
            }
            return $this->prepareResponse(
                self::SUCCESS_PAYMENT_RETRY_ALLOWED,
                self::STATUS_SUCCESS,
                $message,
                $availableRetryAttempts,
                $burstTime
            );
    }

    /**
     * @param Int $code
     * @param String $status
     * @param String $message
     * @param Int $availableRetryAttempt
     * @param Int $burstTime
     * @return array
     */

    private function prepareResponse(Int $code, String $status, String $message, Int $availableRetryAttempt, Int $burstTime)
    {
        $pageData = [
            'code' => $code,
            'status' => $status,
            'burstTime' => $burstTime,
            'availableRetryAttempt'=> $availableRetryAttempt,
            'message' => $message
        ];
        return $pageData;
    }

    /**
     * @param int $storeId
     * @return int
     */
    public function getRetryAttempts (int $storeId) : int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_PAYMENT_RETRY_ATTEMPTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getRetryAttempts()

    /**
     * @param string $orderCreatedDate
     * @param int $storeId
     * @return int
     */
    private function getBurstTime (string $orderCreatedDate, int $storeId) : int
    {
        $currentDateTime  = date("Y-m-d H:i:s");
        $thresholdTime = $this->getRetryThreshold($storeId);
        $timeAvailable = ((strtotime($currentDateTime) - strtotime($orderCreatedDate)));
        $burstTime = (($thresholdTime*60) - $timeAvailable);
        if ($timeAvailable > 0 && ($burstTime > 0)) {
            return $burstTime;
        } else {
            return 0;
        }
    }//end getBurstTime()

    /**
     * @param int $storeId
     * @return int
     */
    public function getRetryThreshold (int $storeId) : int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_PAYMENT_RETRY_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getRetryThreshold()

    /**
     * @param int $storeId
     * @param string $orderCreatedDate
     * @param int $availableRetryAttempts
     * @return bool
     */
    public function checkValidity (int $storeId, string $orderCreatedDate, int $availableRetryAttempts) : bool
    {
        try {
            $currentDateTime  = date("Y-m-d H:i:s");
            $thresholdTime = $this->getRetryThreshold($storeId);

            if ($availableRetryAttempts <= 0) {
                return false;
            }

            $timeDifference = (strtotime($currentDateTime) - strtotime($orderCreatedDate)) / 60;
            if ($timeDifference > $thresholdTime) {
                return false;
            }
        } catch (\Exception $exception) {
            $this->logger->critical($exception->getMessage());
            return false;
        }
        return true;
    }//end checkValidity()

    /**
     * @param string $order_id
     * @return bool
     */
    public function cancelOrder (string $order_id) : bool
    {
        return $this->orderManagement->cancel($order_id);
    }//end cancelOrder()

    /**
     * @param object $order
     * @param string $comment
     * @retrun bool
     */
    private function setOrderComment(object $order, string $comment): \Magento\Sales\Api\Data\OrderStatusHistoryInterface
    {
        $currentDateTime  = date("Y-m-d H:i:s");
        $comment = "$currentDateTime :  Retry Payment : $comment";
        $comment = $order->addStatusHistoryComment($comment);
        return $this->orderStatusRepository->save($comment);
    }

    /**
     * Get Order data by Order Increment Id
     *
     * @param $incrementId
     * @return \Magento\Sales\Api\Data\OrderInterface[]|null
     */
    public function getOrderIdByIncrementId($incrementId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)->create();
        $orderData = null;
        try {
            $order = $this->order->getList($searchCriteria);
            if ($order->getTotalCount()) {
                $orderData = $order->getItems();
            }
        } catch (\Exception $exception) {
            $this->logger->critical($exception->getMessage());
        }
        return $orderData;
    }

    /**
     * @param $order
     * @param $paymentSuccess
     * @param $availableRetryAttempts
     * @param $burstTime
     * @return array|void
     */

    public function paymentRetry($order, $paymentSuccess,  $availableRetryAttempts, $burstTime){

        try {
            $orderStatus = $order->getStatus();

            /**
             * Check if user status is not payment failed
             */
            if ($orderStatus != self::FAILED_PAYMENT_STATUS) {
                $message = "Payment Status changed to $orderStatus so not eligible for Payment Retry";
                $this->setOrderComment($order, $message);
                return $this->prepareResponse(
                    self::ERROR_PAYMENT_RETRY_NOT_ALLOWED,
                    self::STATUS_SUCCESS,
                    $message,
                    $availableRetryAttempts,
                    $burstTime
                );
            }

            /**
             * Check if user is eligible to attempt retry payment.
             */
            $isRetryAllowed = $this->checkValidity((int)$order->getStoreId(), $order->getCreatedAt(), $availableRetryAttempts);
            if (!$isRetryAllowed) {
                $isCancelled = $this->cancelOrder($order->getId());
                if ($isCancelled) {
                    $message = self::PAYMENT_ORDER_CANCELLED_WITH_EXHAUSTED;
                } else {
                    $message = self::ELIGIBILITY_FAIL_CART_UPDATE_FAIL;
                }
                $this->setOrderComment($order, $message);
                return $this->prepareResponse(
                    self::ERROR_PAYMENT_RETRY_NOT_ALLOWED,
                    self::STATUS_FAIL,
                    $message,
                    $availableRetryAttempts,
                    $burstTime
                );
            }
            $message = self::ELIGIBILITY_PASS_CART_UPDATE_PASS;
            return $this->prepareResponse(
                self::SUCCESS_PAYMENT_RETRY_ALLOWED,
                self::STATUS_SUCCESS,
                $message,
                $availableRetryAttempts,
                $burstTime
            );

        } catch (\GraphQlNoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());
        }
    }
}
