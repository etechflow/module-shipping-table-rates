<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Block\Adminhtml\System\Config;

use ETechFlow\ShippingTableRates\Model\Config;
use ETechFlow\ShippingTableRates\Model\LicenseValidator;
use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\Js;

/**
 * Status banner at the top of the Shipping Table Rates admin config section.
 *
 * Identical 6-state pattern to NDE / BED. Surfaces immediately to a
 * first-time installer whether the module is doing anything or sitting idle.
 */
class ModuleStatus extends Fieldset
{
    /**
     * Constructor.
     *
     * @param Context          $context
     * @param Session          $authSession
     * @param Js               $jsHelper
     * @param Config           $config
     * @param LicenseValidator $licenseValidator
     * @param array            $data
     */
    public function __construct(
        Context $context,
        Session $authSession,
        Js $jsHelper,
        private readonly Config $config,
        private readonly LicenseValidator $licenseValidator,
        array $data = []
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->addClass('etechflow-module-status');

        $html  = $this->_getHeaderHtml($element);
        $html .= '<tr id="' . $element->getHtmlId() . '_status_row"><td colspan="4">';
        $html .= $this->renderStatusBanner();
        $html .= '</td></tr>';
        $html .= $this->_getFooterHtml($element);

        return $html;
    }

    /**
     * @return string
     */
    private function renderStatusBanner(): string
    {
        $host = $this->licenseValidator->getCurrentHost();
        $isDevHost = $this->licenseValidator->isDevHost($host);
        $isProduction = $this->licenseValidator->isProductionEnvironment();
        $licenceValid = $this->licenseValidator->isValid();
        $moduleEnabled = $this->config->isEnabled();
        $hasKey = trim($this->licenseValidator->getConfiguredKey()) !== ''
            || trim($this->licenseValidator->getConfiguredBundleKey()) !== '';

        if ($isDevHost) {
            return $this->banner(
                'info',
                'ℹ️ Dev host bypass active',
                'The detected host <code>' . $this->escapeHtml($host) . '</code> matches a development pattern '
                . '(<code>*.test</code>, <code>*.local</code>, <code>staging.*</code>, <code>*.magento.cloud</code>, etc.). '
                . 'The module runs at full features without a licence key here. Pay only when going live on a production domain.'
            );
        }

        if (!$isProduction) {
            return $this->banner(
                'info',
                'ℹ️ Production Environment = No',
                'The Production Environment toggle is off, so the module runs at full features without checking the licence. '
                . 'Use this on non-standard dev/staging domains the auto-detector misses. Switch to Yes before going live.'
            );
        }

        if (!$licenceValid) {
            if (!$hasKey) {
                return $this->banner(
                    'warning',
                    '⚠️ Licence key missing',
                    'You\'re on production host <code>' . $this->escapeHtml($host) . '</code> but no licence key has been entered. '
                    . 'The module is silently disabled until a valid key is saved below. '
                    . 'Paste your key in the <strong>License Key</strong> field, or — if this is actually a dev/staging install — '
                    . 'set <strong>Production Environment = No</strong>.'
                );
            }

            return $this->banner(
                'warning',
                '⚠️ Licence key invalid for this host',
                'A licence key has been entered, but it does not match the expected key for host '
                . '<code>' . $this->escapeHtml($host) . '</code>. The module is silently disabled. '
                . 'Common causes: wrong key, site moved domains (email support for a new key), or stray whitespace in the field.'
            );
        }

        if (!$moduleEnabled) {
            return $this->banner(
                'neutral',
                '⚪ Licence valid, module is disabled',
                'Licence accepted for <code>' . $this->escapeHtml($host) . '</code>, but <strong>Enable Module</strong> in General Settings is set to No. '
                . 'Shipping table rates are not being offered at checkout. Flip Enable Module to Yes to activate.'
            );
        }

        return $this->banner(
            'success',
            '✅ Module is active',
            'Licence valid for <code>' . $this->escapeHtml($host) . '</code>. Your configured shipping methods will be offered at checkout when their rate rules match.'
        );
    }

    /**
     * @param string $kind
     * @param string $heading
     * @param string $body
     * @return string
     */
    private function banner(string $kind, string $heading, string $body): string
    {
        $palette = match ($kind) {
            'success' => ['bg' => '#e7f5ec', 'border' => '#2e7d32', 'fg' => '#1b5e20'],
            'warning' => ['bg' => '#fff4e5', 'border' => '#ef6c00', 'fg' => '#bf360c'],
            'info'    => ['bg' => '#e3f2fd', 'border' => '#1976d2', 'fg' => '#0d47a1'],
            default   => ['bg' => '#f5f5f5', 'border' => '#9e9e9e', 'fg' => '#424242'],
        };

        return sprintf(
            '<div style="background:%s;border-left:4px solid %s;color:%s;padding:14px 18px;margin:0 0 6px;border-radius:4px;font-size:13px;line-height:1.5;">'
            . '<strong style="font-size:14px;display:block;margin-bottom:4px;">%s</strong>%s'
            . '</div>',
            $palette['bg'],
            $palette['border'],
            $palette['fg'],
            $heading,
            $body
        );
    }
}
