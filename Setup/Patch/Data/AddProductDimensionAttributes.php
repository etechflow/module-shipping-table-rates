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
 * Adds three per-product decimal dimension attributes used by Feature 7
 * (volumetric / dimensional weight, Amasty parity).
 *
 *   - etechflow_length_cm
 *   - etechflow_width_cm
 *   - etechflow_height_cm
 *
 * The `etechflow_` prefix is deliberate. Many shipping modules add plain
 * `length` / `width` / `height` (UPS, FedEx integrations, ts_dimensions_*)
 * — using the prefix means we never clash with whatever else the merchant
 * has installed. All three are decimal cm, optional, not visible on the
 * frontend.
 *
 * When the merchant turns on `use_volumetric_weight` on a method, the
 * RateMatcher computes the cart's total volumetric cm³ from these
 * attributes × qty and converts via the method's `volumetric_divisor`.
 */
class AddProductDimensionAttributes implements DataPatchInterface, PatchRevertableInterface
{
    private const ATTRIBUTES = [
        'etechflow_length_cm' => [
            'label' => 'Length (cm)',
            'note'  => 'Box length in centimetres. Used together with width + height to compute volumetric weight for shipping methods that opt in to dimensional-weight pricing.',
            'sort'  => 250,
        ],
        'etechflow_width_cm' => [
            'label' => 'Width (cm)',
            'note'  => 'Box width in centimetres. Used together with length + height to compute volumetric weight for shipping methods that opt in to dimensional-weight pricing.',
            'sort'  => 251,
        ],
        'etechflow_height_cm' => [
            'label' => 'Height (cm)',
            'note'  => 'Box height in centimetres. Used together with length + width to compute volumetric weight for shipping methods that opt in to dimensional-weight pricing.',
            'sort'  => 252,
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTES as $code => $meta) {
            if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
                continue;
            }
            $eavSetup->addAttribute(
                Product::ENTITY,
                $code,
                [
                    'type'                    => 'decimal',
                    'label'                   => $meta['label'],
                    'note'                    => $meta['note'],
                    'input'                   => 'price',  // numeric input with decimal validation
                    'frontend_class'          => 'validate-zero-or-greater',
                    'required'                => false,
                    'sort_order'              => $meta['sort'],
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'user_defined'            => true,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,virtual,bundle,grouped,downloadable',
                    'group'                   => 'eTechFlow Shipping',
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        foreach (array_keys(self::ATTRIBUTES) as $code) {
            $eavSetup->removeAttribute(Product::ENTITY, $code);
        }
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [AddShippingTypeAttribute::class];
    }
}
