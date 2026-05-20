<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Translates Magento's Quote\Address (and its items) into a CartContext.
 *
 * The matcher operates on the immutable CartContext — never touches the
 * Magento quote model directly — so this builder is the SINGLE bridge
 * between Magento data and our domain logic. Tests can construct
 * CartContext directly without instantiating this class.
 *
 * Two non-trivial bits live here:
 *
 *  1. Subtotal selection: Magento exposes both `getBaseSubtotal()` (pre-
 *     discount) and `getBaseSubtotalWithDiscount()` (post-discount). The
 *     module-wide Config::useDiscountedPrice() flag picks between them.
 *
 *  2. Shipping-type collection: cart items don't carry the shipping_type
 *     attribute (it's loaded on demand). We bulk-load all leaf product IDs
 *     in one query, then read their shipping_type values. Container types
 *     (configurable/bundle/grouped parents) are skipped because their
 *     child items already appear separately in the address items list.
 */
class CartContextBuilder
{
    /** Container product types whose shipping_type lives on the child items. */
    private const CONTAINER_TYPES = [
        ConfigurableType::TYPE_CODE,
        BundleType::TYPE_CODE,
        GroupedType::TYPE_CODE,
    ];

    private const SHIPPING_TYPE_ATTR = 'shipping_type';

    /**
     * Constructor.
     *
     * @param Config                   $config
     * @param ProductCollectionFactory $productCollectionFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Build a CartContext from a Magento shipping address.
     *
     * @param Address $address
     * @return CartContext
     */
    public function build(Address $address): CartContext
    {
        $items = $address->getAllItems();
        if (empty($items) && $address->getQuote() !== null) {
            // Some addresses don't carry items directly until the quote loads
            $items = $address->getQuote()->getAllItems();
        }

        $totalWeight = 0.0;
        $totalQty    = 0;
        $productQtys = [];  // [productId => totalQty] for shipping_type + dimension lookup

        foreach ($items as $item) {
            if (method_exists($item, 'isDeleted') && $item->isDeleted()) {
                continue;
            }

            $productType = (string) $item->getProductType();

            // Skip container parents — their child items appear separately
            if (in_array($productType, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $totalWeight += (float) $item->getRowWeight();
            $itemQty      = (int) $item->getTotalQty();
            $totalQty    += $itemQty;

            $productId = (int) $item->getProductId();
            if ($productId > 0) {
                $productQtys[$productId] = ($productQtys[$productId] ?? 0) + $itemQty;
            }
        }

        $cartProductData = $this->loadCartProductData($productQtys);

        $variants = $this->resolveSubtotalVariantsFromAddress($address);
        $subtotal = $this->config->useDiscountedPrice()
            ? $variants['preTaxPostDiscount']
            : $variants['preTaxPreDiscount'];

        // Magento returns NEGATIVE discount applied; if `WithDiscount` got us
        // a sensible number we use it directly, otherwise fall back to plain.
        if ($subtotal <= 0) {
            $subtotal = $variants['preTaxPreDiscount'];
        }

        $customerGroupId = 0;
        $storeId         = 0;
        $quote = $address->getQuote();
        if ($quote !== null) {
            $customerGroupId = (int) $quote->getCustomerGroupId();
            $storeId         = (int) $quote->getStoreId();
        }

        return new CartContext(
            countryCode:                  strtoupper(trim((string) $address->getCountryId())),
            regionCode:                   (string) ($address->getRegionCode() ?: $address->getRegion()),
            city:                         (string) $address->getCity(),
            postcode:                     (string) $address->getPostcode(),
            weight:                       $totalWeight,
            qty:                          $totalQty,
            subtotal:                     $subtotal,
            customerGroupId:              $customerGroupId,
            shippingTypes:                $cartProductData['shippingTypes'],
            subtotalAfterDiscount:        $variants['preTaxPostDiscount'],
            subtotalInclTax:              $variants['inclTaxPreDiscount'],
            subtotalInclTaxAfterDiscount: $variants['inclTaxPostDiscount'],
            storeId:                      $storeId,
            volumetricCm3:                $cartProductData['volumetricCm3']
        );
    }

    /**
     * Build a CartContext from a Magento shipping RateRequest — the object
     * passed into our carrier's collectRates(). Same domain output as build(),
     * but the input is the shipping subsystem's wire-format rather than a
     * Quote\Address.
     *
     * RateRequest exposes some fields directly (dest_country, package_qty,
     * package_value) but for shipping_type detection we still need to walk
     * the items and read their shipping_type attribute, same as build().
     *
     * Subtotal: RateRequest gives us `package_value` which Magento populates
     * from the address subtotal (discounted or not depending on Magento's
     * Shipping > Tax config). We honour Config::useDiscountedPrice() by
     * picking package_value (discounted) vs package_value_with_discount
     * — but Magento's RateRequest fields are inconsistent across versions,
     * so we fall back to whichever is populated.
     *
     * @param RateRequest $request
     * @return CartContext
     */
    public function buildFromRateRequest(RateRequest $request): CartContext
    {
        $items = $request->getAllItems() ?? [];

        $totalWeight = 0.0;
        $totalQty    = 0;
        $productQtys = [];

        foreach ($items as $item) {
            if (method_exists($item, 'isDeleted') && $item->isDeleted()) {
                continue;
            }

            $productType = (string) $item->getProductType();
            if (in_array($productType, self::CONTAINER_TYPES, true)) {
                continue;
            }

            $totalWeight += (float) ($item->getRowWeight() ?: 0.0);
            $itemQty      = (int) ($item->getTotalQty() ?: $item->getQty());
            $totalQty    += $itemQty;

            $productId = (int) $item->getProductId();
            if ($productId > 0) {
                $productQtys[$productId] = ($productQtys[$productId] ?? 0) + $itemQty;
            }
        }

        // RateRequest carries the package totals already — prefer those when
        // present (they account for Magento's own qty/weight resolution).
        if (($pkgWeight = (float) $request->getPackageWeight()) > 0) {
            $totalWeight = $pkgWeight;
        }
        if (($pkgQty = (int) $request->getPackageQty()) > 0) {
            $totalQty = $pkgQty;
        }

        $cartProductData = $this->loadCartProductData($productQtys);

        $variants = $this->resolveSubtotalVariantsFromRateRequest($request);
        $subtotal = $this->config->useDiscountedPrice()
            ? $variants['preTaxPostDiscount']
            : $variants['preTaxPreDiscount'];

        $customerGroupId = 0;
        $storeId         = (int) $request->getStoreId();  // RateRequest carries store_id natively
        // Magento's RateRequest doesn't carry customer_group_id directly,
        // but the address it was built from does. Items also expose it via
        // their getQuote()->getCustomerGroupId() when a quote is attached.
        if (!empty($items)) {
            $firstItem = reset($items);
            if (method_exists($firstItem, 'getQuote') && $firstItem->getQuote() !== null) {
                $customerGroupId = (int) $firstItem->getQuote()->getCustomerGroupId();
                if ($storeId === 0) {
                    $storeId = (int) $firstItem->getQuote()->getStoreId();
                }
            }
        }

        return new CartContext(
            countryCode:                  strtoupper(trim((string) $request->getDestCountryId())),
            regionCode:                   (string) ($request->getDestRegionCode() ?: $request->getDestRegionId()),
            city:                         (string) $request->getDestCity(),
            postcode:                     (string) $request->getDestPostcode(),
            weight:                       $totalWeight,
            qty:                          $totalQty,
            subtotal:                     $subtotal,
            customerGroupId:              $customerGroupId,
            shippingTypes:                $cartProductData['shippingTypes'],
            subtotalAfterDiscount:        $variants['preTaxPostDiscount'],
            subtotalInclTax:              $variants['inclTaxPreDiscount'],
            subtotalInclTaxAfterDiscount: $variants['inclTaxPostDiscount'],
            storeId:                      $storeId,
            volumetricCm3:                $cartProductData['volumetricCm3']
        );
    }

    /**
     * Compute the four subtotal variants from a Magento Address. Magento's
     * Address exposes a stack of subtotal getters that differ in whether
     * they're pre/post tax and pre/post discount. We probe them in order
     * and fall back to the plain pre-tax pre-discount subtotal when a
     * specific variant is not populated on this store / version.
     *
     * @param Address $address
     * @return array{preTaxPreDiscount: float, preTaxPostDiscount: float, inclTaxPreDiscount: float, inclTaxPostDiscount: float}
     */
    private function resolveSubtotalVariantsFromAddress(Address $address): array
    {
        $preTaxPreDiscount  = (float) $address->getBaseSubtotal();
        $preTaxPostDiscount = (float) $address->getBaseSubtotalWithDiscount();

        // Tax-inclusive: `base_subtotal_incl_tax` exists in modern Magento and
        // carries the tax-inclusive pre-discount subtotal. Older versions or
        // tax-disabled stores may leave it zero/null, in which case we fall
        // back to the pre-tax value (best available approximation; harmless
        // when use_price_including_tax is FALSE — the variant is unused).
        $inclTaxPreDiscount = (float) ($address->getData('base_subtotal_incl_tax')
            ?: $address->getData('base_subtotal_total_incl_tax')
            ?: $preTaxPreDiscount);

        // Discount on the tax-inclusive line — Magento stores the negative
        // base_discount_amount that already accounts for any tax adjustment.
        $discountAmount = (float) $address->getData('base_discount_amount');
        $inclTaxPostDiscount = $inclTaxPreDiscount + $discountAmount;  // discount is negative
        if ($inclTaxPostDiscount < 0) {
            // Defensive — over-applied discount shouldn't make the subtotal
            // negative for matching/formula purposes (Magento clamps the same
            // way before charging).
            $inclTaxPostDiscount = 0.0;
        }
        // If the incl-tax value collapsed because Magento didn't populate it,
        // fall back so the matcher's filter behaves the same as without tax.
        if ($inclTaxPreDiscount <= 0) {
            $inclTaxPreDiscount  = $preTaxPreDiscount;
            $inclTaxPostDiscount = $preTaxPostDiscount;
        }

        return [
            'preTaxPreDiscount'   => $preTaxPreDiscount,
            'preTaxPostDiscount'  => $preTaxPostDiscount > 0 ? $preTaxPostDiscount : $preTaxPreDiscount,
            'inclTaxPreDiscount'  => $inclTaxPreDiscount,
            'inclTaxPostDiscount' => $inclTaxPostDiscount,
        ];
    }

    /**
     * Compute the four subtotal variants from a Magento RateRequest. The
     * shipping subsystem exposes `package_value` and `package_value_with_discount`
     * as the wire-format equivalents of the address subtotals. Tax-inclusive
     * variants are not natively in RateRequest — Magento computes shipping
     * pre-tax by default — so we use the same fall-back strategy as the
     * Address path: incl-tax variants degrade to the pre-tax values when
     * not separately populated.
     *
     * @param RateRequest $request
     * @return array{preTaxPreDiscount: float, preTaxPostDiscount: float, inclTaxPreDiscount: float, inclTaxPostDiscount: float}
     */
    private function resolveSubtotalVariantsFromRateRequest(RateRequest $request): array
    {
        $preTaxPreDiscount  = (float) $request->getPackageValue();
        $preTaxPostDiscount = (float) ($request->getPackageValueWithDiscount() ?: $preTaxPreDiscount);

        // RateRequest data-bag may carry the incl-tax variants if a setter
        // injected them upstream (e.g. by a custom Tax plugin); otherwise
        // we degrade to pre-tax.
        $inclTaxPreDiscount  = (float) ($request->getData('package_value_incl_tax') ?: $preTaxPreDiscount);
        $inclTaxPostDiscount = (float) ($request->getData('package_value_incl_tax_with_discount') ?: $preTaxPostDiscount);

        return [
            'preTaxPreDiscount'   => $preTaxPreDiscount,
            'preTaxPostDiscount'  => $preTaxPostDiscount,
            'inclTaxPreDiscount'  => $inclTaxPreDiscount,
            'inclTaxPostDiscount' => $inclTaxPostDiscount,
        ];
    }

    /**
     * Bulk-load the cart's per-product data with ONE query and aggregate
     * both the distinct shipping_type values and the total volumetric cm³
     * (Feature 7). Collapsing the two lookups into one collection load
     * keeps the query budget identical to v1.0 — the dimension columns
     * are just extra fields on the existing select.
     *
     * Returns:
     *   - shippingTypes: distinct lowercased non-empty shipping_type values
     *   - volumetricCm3: sum of (length × width × height × qty) per product.
     *     Products without ALL three dimension attributes set contribute 0
     *     (max() in chargeableWeightForMethod safely falls back to actual
     *     weight), so partial-data carts never silently mis-charge.
     *
     * @param array<int, int> $productQtys productId => total qty in cart
     * @return array{shippingTypes: string[], volumetricCm3: float}
     */
    private function loadCartProductData(array $productQtys): array
    {
        if (empty($productQtys)) {
            return ['shippingTypes' => [], 'volumetricCm3' => 0.0];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter(array_keys($productQtys));
        $collection->addAttributeToSelect(self::SHIPPING_TYPE_ATTR);
        $collection->addAttributeToSelect('etechflow_length_cm');
        $collection->addAttributeToSelect('etechflow_width_cm');
        $collection->addAttributeToSelect('etechflow_height_cm');

        $types          = [];
        $volumetricCm3  = 0.0;
        foreach ($collection as $product) {
            /** @var ProductInterface $product */
            $shippingType = strtolower(trim((string) $product->getData(self::SHIPPING_TYPE_ATTR)));
            if ($shippingType !== '') {
                $types[$shippingType] = true;
            }

            $length = (float) $product->getData('etechflow_length_cm');
            $width  = (float) $product->getData('etechflow_width_cm');
            $height = (float) $product->getData('etechflow_height_cm');
            if ($length > 0.0 && $width > 0.0 && $height > 0.0) {
                $qty = $productQtys[(int) $product->getId()] ?? 0;
                $volumetricCm3 += $length * $width * $height * $qty;
            }
        }

        return [
            'shippingTypes' => array_keys($types),
            'volumetricCm3' => $volumetricCm3,
        ];
    }
}
