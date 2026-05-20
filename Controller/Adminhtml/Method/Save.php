<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\Method;

use ETechFlow\ShippingTableRates\Model\Conflict\ConflictDetector;
use ETechFlow\ShippingTableRates\Model\Config;
use ETechFlow\ShippingTableRates\Model\MethodFactory;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Method as MethodResource;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;

/**
 * POST /admin/etechflow_str/method/save — create or update a method.
 *
 * Order of operations:
 *   1. Load existing record by method_id (if editing)
 *   2. Snapshot pre-save state via VersionRepository (skipped for new rows)
 *   3. setData() + save
 *   4. Flash success / redirect
 *
 * Any exception during snapshot is swallowed by VersionRepository — the
 * save still proceeds. Any exception during save is caught here and
 * surfaced to the merchant.
 */
class Save extends AbstractMethod
{
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        private readonly RedirectFactory $redirectFactory,
        private readonly MethodFactory $methodFactory,
        private readonly MethodResource $methodResource,
        private readonly VersionRepository $versionRepository,
        private readonly ConflictDetector $conflictDetector,
        private readonly RateCollectionFactory $rateCollectionFactory,
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
            return $redirect->setPath('*/*/');
        }

        $data = $request->getPostValue();
        $id   = isset($data['method_id']) ? (int) $data['method_id'] : 0;

        $method = $this->methodFactory->create();
        if ($id > 0) {
            $this->methodResource->load($method, $id);
            if (!$method->getMethodId()) {
                $this->messageManager->addErrorMessage(__('This shipping method no longer exists.'));
                return $redirect->setPath('*/*/');
            }

            // Snapshot pre-save state (best-effort; failure is logged but not blocking)
            $this->versionRepository->snapshot($method, 'Pre-save snapshot');
        }

        try {
            // Whitelist the columns the form can write — guards against attribute
            // injection on rows we haven't declared yet.
            $allowed = [
                'code', 'name', 'is_active', 'sort_order',
                'min_rate', 'max_rate', 'multi_type_mode', 'free_shipping_compatible',
                'use_price_after_discount', 'use_price_including_tax',
                'ship_for_free_types',
                'store_view_ids', 'customer_group_ids',
                'use_volumetric_weight', 'volumetric_divisor',
            ];
            $idLists = ['store_view_ids', 'customer_group_ids'];
            // volumetric_divisor: empty = NULL (= use default 5000 in getter)
            $nullableDecimals = ['volumetric_divisor'];
            $booleans = [
                'is_active', 'free_shipping_compatible',
                'use_price_after_discount', 'use_price_including_tax',
                'use_volumetric_weight',
            ];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    // Convert empty strings on nullable numeric fields to null
                    if (in_array($field, ['min_rate', 'max_rate'], true) && trim((string) $value) === '') {
                        $value = null;
                    }
                    // F7 nullable decimals (volumetric_divisor): empty → NULL
                    // so the Method getter falls back to the carrier default
                    // (5000). Non-empty values pass through as-is.
                    if (in_array($field, $nullableDecimals, true) && trim((string) $value) === '') {
                        $value = null;
                    }
                    // Normalise Yes/No selects to a clean 0/1 — the schema
                    // columns are NOT NULL and Magento's select widgets
                    // submit string values that round-trip as '0' / '1'.
                    if (in_array($field, $booleans, true)) {
                        $value = !empty($value) && (string) $value !== '0' ? 1 : 0;
                    }
                    // ship_for_free_types: normalise the merchant's input
                    // to lowercase, trim each entry, drop empties + dupes,
                    // store as comma-separated. Mirrors Method::getShipForFreeTypes
                    // parsing so the round-trip is stable.
                    if ($field === 'ship_for_free_types') {
                        $raw = trim((string) $value);
                        if ($raw === '') {
                            $value = null;
                        } else {
                            $seen = [];
                            foreach (explode(',', $raw) as $piece) {
                                $n = strtolower(trim($piece));
                                if ($n !== '') {
                                    $seen[$n] = true;
                                }
                            }
                            $value = empty($seen) ? null : implode(',', array_keys($seen));
                        }
                    }
                    // Feature 6 ID lists (store_view_ids / customer_group_ids):
                    // keep only numeric ≥ 0 entries; dedupe; persist NULL when
                    // empty so "applies to all" is the natural default for new
                    // methods. Mirrors Method::parseIdCsv normalisation.
                    if (in_array($field, $idLists, true)) {
                        $raw = trim((string) $value);
                        if ($raw === '') {
                            $value = null;
                        } else {
                            $seen = [];
                            foreach (explode(',', $raw) as $piece) {
                                $piece = trim($piece);
                                if ($piece !== '' && ctype_digit(ltrim($piece, '-')) && (int) $piece >= 0) {
                                    $seen[(int) $piece] = true;
                                }
                            }
                            $value = empty($seen) ? null : implode(',', array_keys($seen));
                        }
                    }
                    $method->setData($field, $value);
                }
            }

            $this->methodResource->save($method);

            $this->messageManager->addSuccessMessage(__('The shipping method has been saved.'));

            // Conflict detection — scan existing active rates for overlaps.
            // Reported as INFO not errors: merchants decide if an overlap is
            // intentional (wildcard + type override is a valid pattern).
            // Best-effort: disabled by Config::isConflictDetectionEnabled()
            // for high-volume merchants who want to skip the scan cost.
            if ($this->strConfig->isConflictDetectionEnabled()) {
                $methodId = (int) $method->getMethodId();
                if ($methodId > 0) {
                    $this->scanForRateConflicts($methodId);
                }
            }

            if ($request->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['method_id' => $method->getMethodId()]);
            }
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not save the method.'));
        }

        return $redirect->setPath('*/*/edit', ['method_id' => $id]);
    }

    /**
     * Run the conflict detector across all active rates for a method.
     * Surfaces any overlaps as notice messages (capped at 5 visible).
     *
     * Wrapped in try/catch so a query failure doesn't block the save.
     *
     * @param int $methodId
     */
    private function scanForRateConflicts(int $methodId): void
    {
        try {
            $rateCollection = $this->rateCollectionFactory->create();
            $rateCollection->addFieldToFilter('method_id', $methodId);
            $rateCollection->addFieldToFilter('is_active', 1);

            $allConflicts = [];
            foreach ($rateCollection as $rate) {
                foreach ($this->conflictDetector->detect($rate) as $conflict) {
                    // Deduplicate: only report each pair once
                    $a = (int) $rate->getRateId();
                    $b = (int) $conflict['rate_id'];
                    $pairKey = min($a, $b) . ':' . max($a, $b);
                    $allConflicts[$pairKey] = sprintf(
                        'rate_id=%d <-> %s',
                        $rate->getRateId(),
                        $conflict['reason']
                    );
                }
            }

            if (empty($allConflicts)) {
                return;
            }

            $shown = array_slice(array_values($allConflicts), 0, 5);
            foreach ($shown as $msg) {
                $this->messageManager->addNoticeMessage(__('Conflict: %1', $msg));
            }
            if (count($allConflicts) > 5) {
                $this->messageManager->addNoticeMessage(__(
                    '... and %1 more overlap(s). Open each rate to review or set distinct sort_order values.',
                    count($allConflicts) - 5
                ));
            }
        } catch (\Throwable $e) {
            // Conflict scan is advisory — don't block the save if it errors
            $this->_logger->warning(
                'ETechFlow_ShippingTableRates: Conflict scan failed.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
