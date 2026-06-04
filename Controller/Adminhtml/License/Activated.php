<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Controller\Adminhtml\License;

use ETechFlow\ShippingTableRates\Model\LicenseValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\View\Result\PageFactory;

/**
 * Landing page after Stripe payment. Calls the eTechFlow portal to activate
 * the subscription and get the SP-XXXX license key, saves it to config,
 * shows success page with the key.
 */
class Activated extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_ShippingTableRates::config';

    private const XML_STRIPE_SECRET = 'etechflow_shippingtablerates/payment/stripe_secret_key';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter,
        private readonly CacheInterface $cache,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sessionId = trim((string) $this->getRequest()->getParam('session_id', ''));
        $plan      = trim((string) $this->getRequest()->getParam('plan', ''));
        $domain    = trim((string) $this->getRequest()->getParam('domain', '')) ?: $this->licenseValidator->getCurrentHost();
        $name      = trim((string) $this->getRequest()->getParam('name', ''));
        $email     = trim((string) $this->getRequest()->getParam('email', ''));

        if (!$sessionId) {
            $this->messageManager->addErrorMessage(__('Invalid payment callback.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('etechflow_str/license/gate');
        }

        $stripeRaw = trim((string) $this->scopeConfig->getValue(self::XML_STRIPE_SECRET));
        $stripeKey = $stripeRaw !== '' ? trim((string) $this->encryptor->decrypt($stripeRaw)) : '';
        $portal    = str_replace('/license/validate', '', $this->licenseValidator->getPortalUrl());

        $payload = json_encode(array_filter([
            'session_id'        => $sessionId,
            'stripe_secret_key' => $stripeKey ?: null,
            'domain'            => $domain,
            'name'              => $name,
            'email'             => $email,
            'plan'              => $plan,
        ]));

        $licenseKey = '';
        $planName   = '';
        $error      = '';

        try {
            $this->curl->setTimeout(20);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-STR/1.2');
            $this->curl->post($portal . '/license/activate', $payload);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
            $data   = json_decode($body, true);

            if ($status === 200 && !empty($data['license_key'])) {
                $licenseKey = $data['license_key'];
                $planName   = $data['plan'] ?? $plan;
            } else {
                $error = $data['error'] ?? ('Portal returned status ' . $status . ': ' . $body);
            }
        } catch (\Throwable $e) {
            $error = 'Could not reach portal: ' . $e->getMessage();
        }

        if ($licenseKey) {
            $this->configWriter->save(LicenseValidator::XML_PATH_LICENSE_KEY, $licenseKey);
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Subscription Activated'));

        $block = $page->getLayout()->getBlock('etechflow.str.license.activated');
        if ($block) {
            $block->setData('license_key', $licenseKey)
                  ->setData('plan', $planName)
                  ->setData('error', $error)
                  ->setData('settings_url', $this->getUrl('adminhtml/system_config/edit/section/etechflow_shippingtablerates'))
                  ->setData('management_url', $this->getUrl('etechflow_str/method/index'));
        }

        return $page;
    }
}
