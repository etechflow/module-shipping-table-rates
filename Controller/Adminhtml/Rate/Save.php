<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Rate;

use ETechFlow\ShippingTableRates\Model\Conflict\ConflictDetector;
use ETechFlow\ShippingTableRates\Model\Config;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\RateFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate as RateResource;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/rate/save — create or update a single rate.
 *
 * Pipeline:
 *  1. Load existing rate by rate_id (if editing)
 *  2. Snapshot parent method state via VersionRepository
 *  3. Whitelist + write form fields, coercing empty strings on nullable
 *     numeric/string columns to null so the matcher's "any" semantics work
 *  4. Save the rate
 *  5. Run conflict detector against other active rates of the same method;
 *     surface overlaps as notices (not errors)
 *  6. Redirect to method edit page (the merchant's home base)
 */
class Save extends AbstractRate
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly RateFactory $rateFactory,
        private readonly RateResource $rateResource,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly VersionRepository $versionRepository,
        private readonly ConflictDetector $conflictDetector,
        private readonly Config $strConfig
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        /** @var HttpRequest $request */
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $redirect->setPath('etechflow_str/method/index');
        }

        $data     = $request->getPostValue();
        $rateId   = isset($data['rate_id']) ? (int) $data['rate_id'] : 0;
        $methodId = isset($data['method_id']) ? (int) $data['method_id'] : 0;

        if ($methodId <= 0) {
            $this->messageManager->addErrorMessage(__('A method_id is required.'));
            return $redirect->setPath('etechflow_str/method/index');
        }

        // Verify the parent method exists
        $method = $this->methodFactory->create();
        $this->methodResource->load($method, $methodId);
        if (!$method->getMethodId()) {
            $this->messageManager->addErrorMessage(__('The parent method no longer exists.'));
            return $redirect->setPath('etechflow_str/method/index');
        }

        // Snapshot the parent method (with rates) before mutation so rollback works
        $this->versionRepository->snapshot($method, $rateId > 0 ? 'Pre-edit rate snapshot' : 'Pre-add rate snapshot');

        $rate = $this->rateFactory->create();
        if ($rateId > 0) {
            $this->rateResource->load($rate, $rateId);
            if (!$rate->getRateId()) {
                $this->messageManager->addErrorMessage(__('This rate rule no longer exists.'));
                return $redirect->setPath('etechflow_str/method/edit', ['method_id' => $methodId]);
            }
        }

        try {
            $this->applyFormDataToRate($rate, $data, $methodId);
            $this->rateResource->save($rate);

            $this->messageManager->addSuccessMessage(__('Rate rule saved.'));

            if ($this->strConfig->isConflictDetectionEnabled()) {
                $this->surfaceConflicts($rate);
            }

            if ($request->getParam('back')) {
                return $redirect->setPath('etechflow_str/rate/edit', ['rate_id' => (int) $rate->getRateId()]);
            }
            return $redirect->setPath('etechflow_str/method/edit', ['method_id' => $methodId]);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not save the rate rule.'));
        }

        if ($rateId > 0) {
            return $redirect->setPath('etechflow_str/rate/edit', ['rate_id' => $rateId]);
        }
        return $redirect->setPath('etechflow_str/rate/new', ['method_id' => $methodId]);
    }

    /**
     * Whitelist + coerce form data into the Rate model. Critical bit:
     * nullable condition columns receive NULL (not empty string) when the
     * merchant left the form field blank — the matcher uses NULL as the
     * "match any" wildcard, and "" would NOT match anything.
     *
     * @param \ETechFlow\ShippingTableRates\Model\Rate $rate
     * @param array $data
     * @param int   $methodId
     */
    private function applyFormDataToRate($rate, array $data, int $methodId): void
    {
        $rate->setData('method_id', $methodId);

        // String columns: empty => null
        foreach (['country_code', 'region_code', 'city', 'zip_from', 'zip_to',
                  'customer_group_id', 'shipping_type', 'comment'] as $field) {
            $val = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $rate->setData($field, $val === '' ? null : $val);
        }

        // Numeric columns: empty => null (these are NULLABLE in the schema)
        foreach (['weight_from', 'weight_to', 'subtotal_from', 'subtotal_to'] as $field) {
            $val = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $rate->setData($field, $val === '' ? null : (float) $val);
        }
        foreach (['qty_from', 'qty_to', 'delivery_days'] as $field) {
            $val = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $rate->setData($field, $val === '' ? null : (int) $val);
        }

        // Rate components: empty => 0.0 (NOT NULL default 0 in the schema)
        foreach (['rate_base', 'rate_per_product', 'rate_per_kg', 'rate_percent'] as $field) {
            $val = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $rate->setData($field, $val === '' ? 0.0 : (float) $val);
        }

        // weight_unit_conversion_rate: empty / non-positive => 1.0 (NOT NULL
        // default 1.0). Positive values pass through. Mirrors the safety net
        // in CsvImporter::prepareForInsert + Rate::getWeightUnitConversionRate.
        $convRaw  = isset($data['weight_unit_conversion_rate']) ? trim((string) $data['weight_unit_conversion_rate']) : '';
        $convFloat = $convRaw === '' ? 1.0 : (float) $convRaw;
        $rate->setData('weight_unit_conversion_rate', $convFloat > 0.0 ? $convFloat : 1.0);

        $rate->setData('sort_order', isset($data['sort_order']) ? (int) $data['sort_order'] : 0);
        $rate->setData('is_active', !empty($data['is_active']) ? 1 : 0);
    }

    /**
     * Run conflict detection against the just-saved rate, surface up to 5
     * overlaps as notice messages.
     *
     * @param \ETechFlow\ShippingTableRates\Model\Rate $rate
     */
    private function surfaceConflicts($rate): void
    {
        try {
            $conflicts = $this->conflictDetector->detect($rate);
            if (empty($conflicts)) {
                return;
            }
            $shown = array_slice($conflicts, 0, 5);
            foreach ($shown as $c) {
                $this->messageManager->addNoticeMessage(__('Conflict: %1', $c['reason']));
            }
            if (count($conflicts) > 5) {
                $this->messageManager->addNoticeMessage(__(
                    '... and %1 more overlap(s). Open the method edit page to review.',
                    count($conflicts) - 5
                ));
            }
        } catch (\Throwable $e) {
            $this->_logger->warning(
                'ETechFlow_ShippingTableRates: Conflict scan failed.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
