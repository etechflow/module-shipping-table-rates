<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Adds the `shipping_type` per-product attribute used to bucket products
 * for rate-rule matching (fragile / oversized / hazmat / etc.).
 *
 * This mirrors Amasty's "Shipping Type" attribute — the way merchants
 * group products that need special-case shipping rules. A rate row in
 * etechflow_str_rate can target a specific shipping_type value to
 * apply only when the cart contains products of that type.
 *
 * Default values are seeded with the most common buckets; merchants can
 * add their own via Stores → Attributes → Product → shipping_type.
 */
class AddShippingTypeAttribute implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTE_CODE = 'shipping_type';

    /** Seeded option values — merchants extend or replace via admin UI. */
    private const DEFAULT_OPTIONS = [
        'standard'  => 'Standard',
        'fragile'   => 'Fragile',
        'oversized' => 'Oversized / Bulky',
        'hazmat'    => 'Hazardous (Hazmat)',
        'cold'      => 'Cold Chain / Refrigerated',
    ];

    /**
     * Constructor.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory          $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::ATTRIBUTE_CODE,
                [
                    'type'                    => 'varchar',
                    'label'                   => 'Shipping Type',
                    'note'                    => 'Bucket this product for shipping-rate rules. Use Standard for most products; pick Fragile / Oversized / Hazmat / Cold Chain for special-case shipping. Add your own options via Stores → Attributes → Product → shipping_type.',
                    'input'                   => 'select',
                    'required'                => false,
                    'sort_order'              => 230,
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'default'                 => 'standard',
                    'visible'                 => true,
                    'user_defined'            => true,
                    'searchable'              => false,
                    'filterable'              => true,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped',
                    'is_used_in_grid'         => true,
                    'is_visible_in_grid'      => true,
                    'is_filterable_in_grid'   => true,
                    'group'                   => 'eTechFlow Shipping',
                    'option'                  => [
                        'values' => self::DEFAULT_OPTIONS,
                    ],
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return void
     */
    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }
}
