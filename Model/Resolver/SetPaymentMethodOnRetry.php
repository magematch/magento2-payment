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

use Rameera\Payment\Model\Resolver\OrderPayment;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Set Payment Method on Retry, used for GraphQL request processing for set payment method from frontend
 */
class SetPaymentMethodOnRetry implements ResolverInterface
{

    const XML_ADYEN_CC_TITLE  = 'payment/adyen_cc/title';
    const XML_ADYEN_HPP_TITLE  = 'payment/adyen_hpp/title';
    /**
     * @var ScopeConfigInterface
     */
    private  $scopeConfig;
    /**
     * @var OrderPayment
     */
    private  $orderPayment;

    /**
     * @var LoggerInterface
     */
    private  $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $order;

    /**
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $order
     * @param OrderPayment $orderPayment
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $order,
        OrderPayment $orderPayment,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->order = $order;
        $this->orderPayment = $orderPayment;
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
            if (!isset($args['input']['orderId'])) {
                throw new GraphQlInputException(__('Order id needs to be specified'));
            }
            if (!isset($args['input']['payMethod'])) {
                throw new GraphQlInputException(__('Payment Method Needs to be Specified'));
            }
            $pageData = ['status' => "failed"];
            $orderId = $args['input']['orderId'];
            $retryPayMethod = $args['input']['payMethod']??'';
            $retryBrandCode = $args['input']['brandCode']??'';
            $orders = $this->orderPayment->getOrderIdByIncrementId($orderId);
            if (count($orders) == 0) {
                $this->logger->info('No Orders with set payment method on Retry found.');
                return $pageData;
            }
            foreach ($orders as $order) {
                $payment = $order->getPayment();
                $payment->setMethod($retryPayMethod);
                if($retryBrandCode && $retryPayMethod == 'adyen_hpp') {
                    $payment->setAdditionalInformation("brand_code", $retryBrandCode);
                    $methodTitle = $this->getAdyenHppTitle((int)$order->getStoreId());
                }else{
                    $methodTitle = $this->getAdyenCcTitle((int)$order->getStoreId());
                }
                $payment->setAdditionalInformation("method_title", $methodTitle);
                $this->orderPayment->setOrderComment($order, "Set Payment Method on Retry Request : $retryPayMethod : $retryBrandCode");
                $this->order->save($order);
            }
            $pageData = [
                'status' => "success"
            ];
        } catch (\GraphQlNoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());
        }
        return $pageData;
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getAdyenCcTitle (int $storeId): string
    {
        return $this->scopeConfig->getValue(self::XML_ADYEN_CC_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getAdyenCcTitle()

    /**
     * @param int $storeId
     * @return string
     */
    public function getAdyenHppTitle (int $storeId): string
    {
        return $this->scopeConfig->getValue(self::XML_ADYEN_HPP_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }//end getAdyenHppTitle()
}
