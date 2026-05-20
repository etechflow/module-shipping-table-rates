<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Registry;

/**
 * Shared ACL + DI setup for the Method admin controllers. All concrete
 * actions extend this to inherit the standard backend behaviour.
 */
abstract class AbstractMethod extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_ShippingTableRates::manage';

    /**
     * Constructor.
     *
     * @param Context  $context
     * @param Registry $coreRegistry
     */
    public function __construct(
        Context $context,
        protected readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }
}
