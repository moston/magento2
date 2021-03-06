<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Attribute\ScopeOverriddenValue;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Option\Repository as OptionRepository;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;

/**
 * Catalog product copier.
 *
 * Creates product duplicate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Copier
{
    /**
     * @var Option\Repository
     */
    protected $optionRepository;

    /**
     * @var CopyConstructorInterface
     */
    protected $copyConstructor;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var MetadataPool
     */
    protected $metadataPool;

    /**
     * @var ScopeOverriddenValue
     */
    private $scopeOverriddenValue;

    /**
     * @param CopyConstructorInterface $copyConstructor
     * @param ProductFactory $productFactory
     * @param ScopeOverriddenValue $scopeOverriddenValue
     * @param OptionRepository|null $optionRepository
     * @param MetadataPool|null $metadataPool
     */
    public function __construct(
        CopyConstructorInterface $copyConstructor,
        ProductFactory $productFactory,
        ScopeOverriddenValue $scopeOverriddenValue,
        OptionRepository $optionRepository,
        MetadataPool $metadataPool
    ) {
        $this->productFactory = $productFactory;
        $this->copyConstructor = $copyConstructor;
        $this->scopeOverriddenValue = $scopeOverriddenValue;
        $this->optionRepository = $optionRepository;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Create product duplicate
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return \Magento\Catalog\Model\Product
     *
     * @throws \Exception
     */
    public function copy(Product $product)
    {
        $product->getWebsiteIds();
        $product->getCategoryIds();

        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);

        /** @var \Magento\Catalog\Model\Product $duplicate */
        $duplicate = $this->productFactory->create();
        $productData = $product->getData();
        $productData = $this->removeStockItem($productData);
        $duplicate->setData($productData);
        $duplicate->setOptions([]);
        $duplicate->setIsDuplicate(true);
        $duplicate->setOriginalLinkId($product->getData($metadata->getLinkField()));
        $duplicate->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED);
        $duplicate->setCreatedAt(null);
        $duplicate->setUpdatedAt(null);
        $duplicate->setId(null);
        $duplicate->setStoreId(\Magento\Store\Model\Store::DEFAULT_STORE_ID);
        $this->copyConstructor->build($product, $duplicate);
        $this->setDefaultUrl($product, $duplicate);
        $this->setStoresUrl($product, $duplicate);
        $this->optionRepository->duplicate($product, $duplicate);
        $product->getResource()->duplicate(
            $product->getData($metadata->getLinkField()),
            $duplicate->getData($metadata->getLinkField())
        );
        return $duplicate;
    }

    /**
     * Set default URL.
     *
     * @param Product $product
     * @param Product $duplicate
     * @return void
     */
    private function setDefaultUrl(Product $product, Product $duplicate) : void
    {
        $duplicate->setStoreId(\Magento\Store\Model\Store::DEFAULT_STORE_ID);
        $resource = $product->getResource();
        $attribute = $resource->getAttribute('url_key');
        $productId = $product->getId();
        $urlKey = $resource->getAttributeRawValue($productId, 'url_key', \Magento\Store\Model\Store::DEFAULT_STORE_ID);
        do {
            $urlKey = $this->modifyUrl($urlKey);
            $duplicate->setUrlKey($urlKey);
        } while (!$attribute->getEntity()->checkAttributeUniqueValue($attribute, $duplicate));
        $duplicate->setData('url_path', null);
        $duplicate->save();
    }

    /**
     * Set URL for each store.
     *
     * @param Product $product
     * @param Product $duplicate
     *
     * @return void
     * @throws UrlAlreadyExistsException
     */
    private function setStoresUrl(Product $product, Product $duplicate) : void
    {
        $storeIds = $duplicate->getStoreIds();
        $productId = $product->getId();
        $productResource = $product->getResource();
        $attribute = $productResource->getAttribute('url_key');
        $duplicate->setData('save_rewrites_history', false);
        foreach ($storeIds as $storeId) {
            $useDefault = !$this->scopeOverriddenValue->containsValue(
                ProductInterface::class,
                $product,
                'url_key',
                $storeId
            );
            if ($useDefault) {
                continue;
            }

            $duplicate->setStoreId($storeId);
            $urlKey = $productResource->getAttributeRawValue($productId, 'url_key', $storeId);
            $iteration = 0;

            do {
                if ($iteration === 10) {
                    throw new UrlAlreadyExistsException();
                }

                $urlKey = $this->modifyUrl($urlKey);
                $duplicate->setUrlKey($urlKey);
                $iteration++;
            } while (!$attribute->getEntity()->checkAttributeUniqueValue($attribute, $duplicate));
            $duplicate->setData('url_path', null);
            $productResource->saveAttribute($duplicate, 'url_path');
            $productResource->saveAttribute($duplicate, 'url_key');
        }
        $duplicate->setStoreId(\Magento\Store\Model\Store::DEFAULT_STORE_ID);
    }

    /**
     * Modify URL key.
     *
     * @param string $urlKey
     * @return string
     */
    private function modifyUrl(string $urlKey) : string
    {
        return preg_match('/(.*)-(\d+)$/', $urlKey, $matches)
            ? $matches[1] . '-' . ($matches[2] + 1)
            : $urlKey . '-1';
    }

    /**
     * Remove stock item
     *
     * @param array $productData
     * @return array
     */
    private function removeStockItem(array $productData)
    {
        if (isset($productData[ProductInterface::EXTENSION_ATTRIBUTES_KEY])) {
            $extensionAttributes = $productData[ProductInterface::EXTENSION_ATTRIBUTES_KEY];
            if (null !== $extensionAttributes->getStockItem()) {
                $extensionAttributes->setData('stock_item', null);
            }
        }
        return $productData;
    }
}
