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

namespace Rameera\Payment\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

class CancelPendingOrder
{
    const XML_PATH_PENDING_PAYMENT_THRESHOLD = 'payment/payment_additional_configurations/pending_payment_threshold';
    const XML_PATH_PAYMENT_RETRY_THRESHOLD = 'payment/payment_additional_configurations/allowed_threshold_to_attempt_retry';
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var OrderManagementInterface
     */
    protected $orderManagement;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $orderStatusRepository;

    /**
     * @var TimezoneInterface
     */
    private $dateTime;

    /**
     * @param LoggerInterface $loggerInterface
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderManagementInterface $orderManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderStatusHistoryRepositoryInterface $orderStatusRepository
     * @param TimezoneInterface $dateTime
     */

    public function __construct(
        LoggerInterface $loggerInterface,
        ScopeConfigInterface $scopeConfig,
        OrderManagementInterface $orderManagement,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderStatusHistoryRepositoryInterface $orderStatusRepository,
        TimezoneInterface $dateTime
    ) {
        $this->logger = $loggerInterface;
        $this->scopeConfig = $scopeConfig;
        $this->orderManagement = $orderManagement;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->dateTime = $dateTime;
    }

    /**
     * @return void
     */
    public function execute() {
        try {
            $prevDate = new \DateTime();
            $prevDate->modify('-2 hours');
            $prevDate = $this->dateTime->date($prevDate)->format('Y-m-d H:i:s');
            $pendingPaymentThreshold = ($this->getPendingPaymentThreshold() + $this->getRetryThreshold())*60;
            $thresholdTime = new \DateTime();
            $thresholdTime->modify("-$pendingPaymentThreshold second");
            $thresholdTime = $this->dateTime->date($thresholdTime)->format('Y-m-d H:i:s');
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(
                    'status',
                    'pending_payment',
                    'eq'
                )->addFilter(
                    'created_at',
                    $prevDate,
                    'gt'
                )->create();

            $orders = $this->orderRepository->getList($searchCriteria);
            if (count($orders->getItems()) == 0) {
                $this->logger->info('No Orders with pending payment found.');
                return;
            }
            foreach ($orders->getItems() as $order) {
                $createdDate = new \DateTime($order->getCreatedAt());
                $createdDate = $this->dateTime->date($createdDate)->format('Y-m-d H:i:s');
                if ($thresholdTime < $createdDate
                    || !$this->cancelOrder((int)$order->getId())
                ) {
                    continue;
                }
                $message = "Order Cancelled due to Pending Payment Cron :: " . $order->getId();
                $this->setOrderComment($order, $message);
                $this->logger->debug($message);
            }
        }catch ( \Exception $ex) {
            $this->logger->error($ex->getMessage());
        }
    }

    /**
     * @return int
     */
    public function getPendingPaymentThreshold () : int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_PENDING_PAYMENT_THRESHOLD,
            ScopeInterface::SCOPE_STORE
        );
    }//end getPendingPaymentThreshold()

    /**
     * @return int
     */
    public function getRetryThreshold () : int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_PAYMENT_RETRY_THRESHOLD,
            ScopeInterface::SCOPE_STORE
        );
    }//end getRetryThreshold()

    /**
     * @param int $orderId
     * @return bool
     */
    public function cancelOrder (int $orderId) : bool
    {
        return $this->orderManagement->cancel($orderId);
    }//end cancelOrder()

    /**
     * @param object $order
     * @param string $comment
     * @return void
     * @throws CouldNotSaveException
     */
    private function setOrderComment(object $order, string $comment): void
    {
        $comment = $order->addStatusHistoryComment($comment);
        $this->orderStatusRepository->save($comment);
    }
}
