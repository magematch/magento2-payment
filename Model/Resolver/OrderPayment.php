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

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Adyen\Payment\Helper\Data;
use Psr\Log\LoggerInterface;
use Rameera\Payment\Model\Api\PaymentWebhook;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Order Payment field resolver, used for GraphQL request processing for payment from frontend
 */
class OrderPayment implements ResolverInterface
{
    const XML_PAYMENT_AUTHORIZED_STATUS  = 'payment/adyen_abstract/payment_authorized';
    const XML_SAVE_ORDER_COMMENT  = 'payment/payment_additional_configurations/save_order_comment';
    /**
     * @var ScopeConfigInterface
     */
    private  $scopeConfig;
    /**
     * @var Data
     */
    protected $_adyenHelper;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $orderStatusRepository;

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

    /**
     * @param OrderStatusHistoryRepositoryInterface $orderStatusRepository
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $order
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Data $adyenHelper
     * @param OrderSender $orderSender
     */
    public function __construct(
        OrderStatusHistoryRepositoryInterface $orderStatusRepository,
        LoggerInterface $logger,
        OrderRepositoryInterface $order,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Data $adyenHelper,
        OrderSender $orderSender,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->orderStatusRepository = $orderStatusRepository;
        $this->logger = $logger;
        $this->order = $order;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_adyenHelper = $adyenHelper;
        $this->orderSender = $orderSender;
        $this->scopeConfig = $scopeConfig;
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
        try {

            if (!isset($args['input']['order_id'])) {
                throw new GraphQlInputException(__('Order id needs to be specified'));
            }
            if (!isset($args['input']['pay_response'])) {
                throw new GraphQlInputException(__('Adyen Response can not be empty'));
            }
            $state = Order::STATE_PENDING_PAYMENT;
            $status = "pending_payment";
            $orderId = $args['input']['order_id'];
            $paymentStatus = $args['input']['status']??'';
            $payResponse = json_decode($args['input']['pay_response']);
            $payResponse = json_decode(json_encode($payResponse), true);
            $orders = $this->getOrderIdByIncrementId($orderId);
            $code = '';

            if (count($orders) == 0) {
                $this->logger->info('No Orders with pending payment found.');
                return;
            }

            foreach ($orders as $order) {
                $pspReference = $payResponse['adyen']['pspReference'] ?? '';
                $resultCode = $payResponse['adyen']['resultCode'] ?? '';
                $refusalReason = $payResponse['adyen']['refusalReason'] ?? '';
                $refusalReasonCode = $payResponse['adyen']['refusalReasonCode'] ?? '';
                $brandCode = $payResponse['adyen']['additionalData']['paymentMethod'] ?? '';
                $currentOrderStatus = $order->getStatus();

                if ($paymentStatus == "success") {
                    $state = $status = $this->getPaymentAuthorizedStatus((int)$order->getStoreId());
                } elseif ($resultCode == "Refused" || ($resultCode == "Error")) {
                    $state = $status = PaymentWebhook::PAYMENT_FAILED;
                }

                $this->setOrderComment($order, json_encode($payResponse));
                $payment = $order->getPayment();
                $payment->setAdditionalInformation("resultCode", $resultCode);
                $payment->setAdditionalInformation("brand", $code);
                $payment->setAdditionalInformation("pspReference", $pspReference);
                $payment->setAdditionalInformation("refusalReason", $refusalReason);
                $payment->setAdditionalInformation("refusalReasonCode", $refusalReasonCode);
                $payment->setAdditionalInformation("additionalData", json_encode($payResponse));
                $payment->setCcTransId($pspReference);
                $payment->setLastTransId($pspReference);
                $order->setState($state);
                $order->setStatus($status);

                if($brandCode) {
                    $code = $this->getCcTypesByAlt($brandCode);
                    $payment->setCcType($code);
                }

                if($currentOrderStatus == PaymentWebhook::PAYMENT_FAILED || $currentOrderStatus == PaymentWebhook::PENDING_PAYMENT || $currentOrderStatus == PaymentWebhook::PAYMENT_REVIEW) {
                    $this->order->save($order);
                }

                if ($paymentStatus == "success") {
                    $this->orderSender->send($order);
                }
            }
            $pageData = [
                'status' => "success"
            ];
        } catch (\GraphQlNoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());
            $pageData = [
                'status' => "failed"
            ];
        }
        return $pageData;
    }

    /**
     * @param object $order
     * @param string $comment
     * @return bool|\Magento\Sales\Api\Data\OrderStatusHistoryInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function setOrderComment(object $order, string $comment)
    {
        if($this->getSaveOrderComment((int)$order->getStoreId()) == 1) {
            $comment = $order->addStatusHistoryComment("Payment Response: " . $comment);
            return $this->orderStatusRepository->save($comment);
        }
        return true;
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getPaymentAuthorizedStatus (int $storeId): string
    {
        return $this->scopeConfig->getValue(self::XML_PAYMENT_AUTHORIZED_STATUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getPaymentAuthorizedStatus()

    /**
     * @param int $storeId
     * @return mixed
     */
    public function getSaveOrderComment(int $storeId)
    {
        return $this->scopeConfig->getValue(self::XML_SAVE_ORDER_COMMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getSaveOrderComment()

    /**
     * @param $order
     * @return void
     */
    public function saveOrderInfo($order)
    {
        $this->order->save($order);
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
     * @param $brandCode
     * @return string
     */
    public function getCcTypesByAlt($brandCode): string
    {
        $types = [];
        $ccTypes = $this->_adyenHelper->getAdyenCcTypes();
        foreach ($ccTypes as $key => $data) {
            $types[$data['code_alt']] = $data;
            $types[$data['code_alt']]['code'] = $key;
        }
        return $types[$brandCode]['code'];
    }
}
