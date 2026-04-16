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
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\QuoteGraphQl\Model\Resolver\PlaceOrder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class AfterPlaceOrder
{

    /**
     * @var LoggerInterface
     */
    private  $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $order;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;


    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $order,
        SearchCriteriaBuilder $searchCriteriaBuilder
    )
    {
        $this->logger = $logger;
        $this->order = $order;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * This function adds the masked cart_id to the output of PlaceOrder::resolve
     *
     * @param PlaceOrder $placeOrder
     * @param array $result
     * @return array
     */
    public function afterResolve(PlaceOrder $placeOrder, array $result): array
    {
        try {
            $orderNumber = ($result['order']['order_number'] ?? '');
            $orders = $this->getOrderIdByIncrementId($orderNumber);
            foreach ($orders as $order) {
               $paymentMethod = $order->getPayment()->getMethod();
                $resultCode = $order->getPayment()->getAdditionalInformation('resultCode');
                $pspReference = $order->getPayment()->getLastTransId();
                if(($paymentMethod == "adyen_cc" || $paymentMethod == "adyen_hpp") && $resultCode == "Pending" && $pspReference =="") {
                     $order->setStatus("pending_payment");
                     $order->setState("pending_payment");
                }
                $this->order->save($order);
            }

        } catch (NoSuchEntityException $exception) {
            $this->adyenLogger->error($exception->getMessage());
        }

        return $result;
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
}
