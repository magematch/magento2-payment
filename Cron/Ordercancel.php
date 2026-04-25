<?php

namespace MageMatch\Payment\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

class Ordercancel
{
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
     * @param LoggerInterface $loggerInterface
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderManagementInterface $orderManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderStatusHistoryRepositoryInterface $orderStatusRepository
     */

    public function __construct(
        LoggerInterface $loggerInterface,
        ScopeConfigInterface $scopeConfig,
        OrderManagementInterface $orderManagement,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderStatusHistoryRepositoryInterface $orderStatusRepository
    ) {
        $this->logger = $loggerInterface;
        $this->scopeConfig = $scopeConfig;
        $this->orderManagement = $orderManagement;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderStatusRepository = $orderStatusRepository;

    }

    /**
     * @throws CouldNotSaveException
     */
    public function execute() {
        $prev_date = date('Y-m-d H:i:s', strtotime('-2 hours'));
       $searchCriteria = $this->searchCriteriaBuilder
           ->addFilter(
               'status',
               'payment_failed',
               'eq'
           )->addFilter(
               'created_at',
               $prev_date,
               'gt'
           )->create();

       $orders = $this->orderRepository->getList($searchCriteria);
       if(count($orders->getItems())>0) {
            foreach ($orders->getItems() as $order) {
                $thresHold = $this->getRetryThreshold($order->getStoreId());
                $thresHoldTime = ($thresHold * 60) + 60;
                $thresholdTime = strtotime("-$thresHoldTime second");
                if ($thresholdTime > strtotime($order->getCreatedAt())) {
                    $message = "Order Cancelled due to Payment Retry Cron :: " . $order->getId();
                    $this->cancelOrder($order->getId());
                    $this->setOrderComment($order, $message);
                    $this->logger->debug($message);
                }
            }
       }
    }

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
     * @param int $order_id
     * @return bool
     */
    public function cancelOrder (int $order_id) : bool
    {
        return $this->orderManagement->cancel($order_id);
    }//end cancelOrder()

    /**
     * @param object $order
     * @param string $comment
     * @retrun bool
     * @throws CouldNotSaveException
     */
    private function setOrderComment(object $order, string $comment): \Magento\Sales\Api\Data\OrderStatusHistoryInterface
    {
        $comment = $order->addStatusHistoryComment($comment);
        return $this->orderStatusRepository->save($comment);
    }
}
