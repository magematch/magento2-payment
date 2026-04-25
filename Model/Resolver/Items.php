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
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;


/**
 * Retrieves the Items information object
 */
class Items implements ResolverInterface
{
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManagerInterface;

    /**
     * @var ProductFactory
     */
    private ProductFactory $productFactory;

    /**
     * @var ProductResource
     */
    private ProductResource $productResource;

    /**
     * @param StoreManagerInterface $storeManagerInterface
     * @param ProductFactory $productFactory
     * @param ProductResource $productResource
     */
    public function __construct(
        StoreManagerInterface $storeManagerInterface,
        ProductFactory        $productFactory,
        ProductResource       $productResource
    )
    {
        $this->storeManagerInterface = $storeManagerInterface;
        $this->productFactory = $productFactory;
        $this->productResource = $productResource;
    }

    /**
     * Get All Product Items of Order.
     * Items are an array Visible Order Item
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!isset($value['items'])) {
            return null;
        }
        $itemArray = [];
        foreach ($value['items'] as $key => $item) {
            $product = $this->productFactory->create()->loadByAttribute('sku', $item['sku']);
            $image = $this->getProductThumbnailImage($product);
            $colorName = $product->getAttributeText('color');
            $sizeName = $product->getAttributeText('size');

            $itemArray[$key]["size"] = $sizeName ?? '';
            $itemArray[$key]['color'] = $colorName ?? '';
            $itemArray[$key]['sku'] = $item['sku'];
            $itemArray[$key]['visible_in_frontend'] = $product->isVisibleInSiteVisibility();
            $itemArray[$key]['parent_sku'] = $this->getParentProductSku($item);
            $itemArray[$key]['title'] = $item['name'];
            $itemArray[$key]['price'] = $item['price'];
            $itemArray[$key]['quantity'] = $item['qty_ordered'];
            $itemArray[$key]['special_price'] = $item['price_incl_tax'];
            $itemArray[$key]['original_price'] = $item['original_price'];
            $itemArray[$key]['thumbnail'] = json_encode($image);
            $itemArray[$key]['url_key'] = $product->getUrlKey();
            $itemArray[$key]['row_total'] = $item['row_total'];
            $itemArray[$key]['row_total_incl_tax'] = $item['row_total_incl_tax'];
        }
        return $itemArray;
    }

    /**
     * @param $product
     * @return array
     * @throws NoSuchEntityException
     */
    private function getProductThumbnailImage($product): array
    {
        $mediaUrl = $this->storeManagerInterface->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $thumbnailImage[] = $mediaUrl . 'catalog/product' . $product->getData('thumbnail');
        return $thumbnailImage;
    }

    /**
     * @param array $orderItem
     * @return string|null
     */
    private function getParentProductSku (array $orderItem) : ?string
    {
        $parentProductSkus = $this->productResource->getProductsSku([$orderItem['product_id']]);
        return $parentProductSkus[0]['sku'] ?? null;
    }
}
