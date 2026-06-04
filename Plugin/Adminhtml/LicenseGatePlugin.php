<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Plugin\Adminhtml;

use ETechFlow\ShippingTableRates\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * License-gate plugin for every admin Method/Rate controller.
 *
 * When the module's licence is invalid, the plugin short-circuits the
 * controller dispatch and redirects the browser to the License gate page
 * at /admin/etechflow_str/license/gate. The merchant can still reach:
 *
 *   - License gate / Checkout / Activated controllers (NOT gated — they
 *     extend Magento\Backend\App\Action directly, so this plugin's targets
 *     never match them)
 *   - Stores → Configuration → eTechFlow → Shipping Table Rates (Magento's
 *     own system_config controller — never gated by this plugin)
 *
 * The plugin is declared against ETechFlow\ShippingTableRates\Controller\
 * Adminhtml\Method\AbstractMethod and ETechFlow\ShippingTableRates\Controller\
 * Adminhtml\Rate\AbstractRate, so it transparently covers every concrete
 * subclass (Method\Index, Method\Edit, Method\Save, Method\Export, Method\
 * Import, Method\Simulate, Rate\Edit, Rate\Save, etc.) — no need to modify
 * each controller individually.
 */
class LicenseGatePlugin
{
    public function __construct(
        private readonly LicenseValidator $licenseValidator,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * @param Action           $subject
     * @param callable         $proceed
     * @param RequestInterface $request
     * @return mixed   ResponseInterface | ResultInterface — Magento dispatches both
     */
    public function aroundDispatch(Action $subject, callable $proceed, RequestInterface $request)
    {
        if (!$this->licenseValidator->isValid()) {
            /** @var \Magento\Framework\Controller\Result\Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $redirect->setPath('etechflow_str/license/gate');
            return $redirect;
        }
        return $proceed($request);
    }
}
