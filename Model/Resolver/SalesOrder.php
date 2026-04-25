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

namespace MageMatch\Payment\Model\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use MageMatch\Payment\Api\Data\AdyenPaymentMethodsMappingInterface as APMI;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Psr\Log\LoggerInterface;
use Adyen\Payment\Helper\Data as AdyenHelperData;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Sales Order field resolver, used for GraphQL request processing
 */
class SalesOrder implements ResolverInterface
{
    /**
     * @var AdyenHelperData
     */
    protected AdyenHelperData $adyenHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /** @var OrderInterfaceFactory */
    private OrderInterfaceFactory $orderFactory;

    /**
     * @var CartRepositoryInterface
     */
    protected CartRepositoryInterface $quoteRepository;

    /**
     * @param OrderInterfaceFactory $orderFactory
     * @param LoggerInterface $logger
     * @param AdyenHelperData $adyenHelper
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        OrderInterfaceFactory   $orderFactory,
        LoggerInterface         $logger,
        AdyenHelperData                    $adyenHelper,
        CartRepositoryInterface $quoteRepository
    )
    {
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->adyenHelper = $adyenHelper;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field       $field,
                    $context,
        ResolveInfo $info,
        array       $value = null,
        array       $args = null
    )
    {
        $salesId = $this->getSalesId($args);
        return $this->getSalesData($salesId);
    }

    /**
     * @param array $args
     * @return string
     * @throws GraphQlInputException
     */
    private function getSalesId(array $args): string
    {
        if (!isset($args['id'])) {
            throw new GraphQlInputException(__('"sales id should be specified'));
        }

        return $args['id'];
    }

    /**
     * @param String $orderId
     * @return array
     * @throws GraphQlNoSuchEntityException
     */
    private function getSalesData(string $orderId): array
    {
        try {
            $order = $this->orderFactory->create()->loadByIncrementId($orderId);
            $shippingAddress = $order->getShippingAddress();
            $billingAddress = $order->getBillingAddress();
            $paymentMethod = $order->getPayment()->getMethod();
            $ccType = $order->getPayment()->getCcType();
            $brand = $order->getPayment()->getAdditionalInformation('brand') ?? '';
            $additionalData = json_decode($order->getPayment()->getAdditionalInformation('additionalData') ?? '');
            $brandCode = $additionalData->adyen->paymentMethod->type ?? $order->getPayment()->getAdditionalInformation('brand_code');

            if ($paymentMethod == 'adyen_hpp') {
                $paymentMethod = $this->getHppPaymentMethodName($brandCode, $brand);

            } elseif ($paymentMethod == 'adyen_cc') {
                $paymentMethod = "Credit Card";
                if ($ccType) {
                    $ccName = $this->getCcNameByCode($ccType);
                    $paymentMethod = "Credit Card ($ccName)";
                }
            }

            $itemsData = [];
            foreach ($order->getAllItems() as $item) {
                $itemsData[] = $item->getData();
            }

            $quote = $this->quoteRepository->get($order->getQuoteId());
            $quote->setIsActive(0);
            $this->quoteRepository->save($quote);

            $pageData = [
                'increment_id' => $order->getIncrementId(),
                'grand_total' => $order->getGrandTotal(),
                'customer_name' => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
                'customer_email' => $order->getCustomerEmail(),
                'order_status' => $order->getStatus(),
                'created_at' => $order->getCreatedAt(),
                'is_guest_customer' => !empty($order->getCustomerIsGuest()) ? 1 : 0,
                'shipping_method' => $order->getShippingMethod(),
                'payment_method' => $paymentMethod,
                'subtotal' => $order->getSubtotal(),
                'subtotal_incl_tax' => $order->getSubtotalInclTax(),
                'discount_amount' => $order->getDiscountAmount(),
                'tax_amount' => $order->getTaxAmount(),
                'shipping_amount' => $order->getShippingAmount(),
                'shipping_incl_tax' => $order->getShippingInclTax(),
                'total' => $order->getGrandTotal(),
                'shipping_address' => json_encode($this->getAddress($shippingAddress)),
                'billing_address' => json_encode($this->getAddress($billingAddress)),
                'items' => $itemsData
            ];
        } catch (NoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());
            throw new GraphQlNoSuchEntityException(__($e->getMessage()), $e);
        }
        return $pageData;
    }

    /**
     * Retrieve credit card name by code
     * @param $brand_code
     * @return String
     */
    protected function getCcNameByCode($brand_code): string
    {
        $ccTypes = $this->adyenHelper->getAdyenCcTypes();
        return $ccTypes[$brand_code]['name'] ?? '';
    }

    /**
     * @param $address
     * @return array
     */

    protected function getAddress($address): array
    {
        $userAddress = [];
        $userAddress['name'] = $address->getFirstname() . ' ' . $address->getLastname();
        $userAddress['street'] = count($address->getStreet()) > 1 ? implode(" , ", $address->getStreet()) : $address->getStreet();
        $userAddress['city'] = $address->getCity();
        $userAddress['region'] = $address->getRegion();
        $userAddress['country_id'] = $address->getCountryId();
        $userAddress['postcode'] = $address->getPostcode();
        $userAddress['telephone'] = $address->getTelephone();
        $userAddress['fax'] = $address->getFax();
        $userAddress['company'] = $address->getCompany();
        return $userAddress;
    }

    /**
     * @param $brandCode
     * @param $brand
     * @return string
     */
    protected function getHppPaymentMethodName($brandCode, $brand): string
    {
        $paymentMethod = $brandCode;
        if (isset(APMI::PAYMENT_NAME_FOR_SCHEME[$brandCode])) {
            $paymentMethod = APMI::PAYMENT_NAME_FOR_SCHEME[$brandCode];
        }

        if ($brand && $brand != '') {
            $ccName = $this->getCcNameByCode($brand);
            if ($ccName && $ccName != '') {
                $paymentMethod = "$paymentMethod ($ccName)";
            }
        }
        return $paymentMethod;
    }
}
