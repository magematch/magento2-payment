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
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;


/**
 * Order Reset field resolver, used for GraphQL request to activate and deactivate cart for payment retry
 */
class CartReset implements ResolverInterface
{
    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private $quoteIdToMaskedQuoteId;

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
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;


    public function __construct(
        LoggerInterface $logger,
        OrderRepositoryInterface $order,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CartRepositoryInterface $quoteRepository,
        QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
    ) {
        $this->logger = $logger;
        $this->order = $order;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->quoteRepository = $quoteRepository;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
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
            if (!isset($args['input']['cart_status'])) {
                throw new GraphQlInputException(__('Cart Status can not be empty'));
            }
            $orderId = $args['input']['order_id'];
            $cartStatus = $args['input']['cart_status'];

            $orders = $this->getOrderIdByIncrementId($orderId);
            $maskedQuoteId = "";
            foreach ($orders as $order) {
                $quoteId = (int)$order->getQuoteId();
                $this->logger->critical($quoteId);
                $quote = $this->quoteRepository->get($quoteId);
                if($cartStatus == 'activate') {
                    $quote->setIsActive(1);
                }elseif ($cartStatus == 'deactivate'){
                    $quote->setIsActive(0);
                }
                $maskedQuoteId = $this->getQuoteMaskId($quoteId);
                $this->quoteRepository->save($quote);
            }
            $pageData = [
                'status' => "success",
                'cart_id' => $maskedQuoteId
            ];
        } catch (\GraphQlNoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());
            $pageData = [
                'status' => "failed",
                'cart_id' => ""
            ];
        }
        return $pageData;
    }

    /** get Masked id by Quote Id
    * @param int $quoteId
    * @return string|null
    * @throws LocalizedException
    */
    public function getQuoteMaskId(int $quoteId): ?string
    {
        $maskedId = null;
        try {
            $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);
        } catch (NoSuchEntityException $exception) {
            $this->logger->critical($exception->getMessage());
            throw new LocalizedException(__('Current user does not have an active cart.'));
        }

        return $maskedId;
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
