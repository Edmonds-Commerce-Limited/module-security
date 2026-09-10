<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Model\CustomerAddress;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the hardening switches that apply to customer addresses.
 */
class Config
{
    public const XML_PATH_DISABLE_FILE_UPLOAD = 'edmondscommerce_security/customer_address/disable_file_upload';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Are storefront file uploads against customer address attributes switched off for the current store?
     *
     * @return bool
     */
    public function isFileUploadDisabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DISABLE_FILE_UPLOAD, ScopeInterface::SCOPE_STORE);
    }
}
