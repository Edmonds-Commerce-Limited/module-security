<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Plugin\Customer;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomerAddress\Config;
use Magento\Customer\Controller\Address\File\Upload;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;

/**
 * Refuses the storefront customer address file upload, the file-write half of SessionReaper
 * (CVE-2025-54236).
 *
 * The controller needs no login and moves the posted file into pub/media/customer_address, which is
 * how attackers plant a session file for the Web API deserialization bug to load.
 */
class BlockAddressFileUpload
{
    public const BLOCKED_MESSAGE = 'Uploading files against customer addresses is disabled on this store.';
    public const ATTEMPT = 'Blocked customer address file upload';

    /**
     * @param Config                                           $config
     * @param JsonFactory                                      $resultJsonFactory
     * @param \EdmondsCommerce\Security\Model\Log\AttackLogger $attackLogger
     */
    public function __construct(
        private readonly Config $config,
        private readonly JsonFactory $resultJsonFactory,
        private readonly AttackLogger $attackLogger
    ) {
    }

    /**
     * Answer in core's own error shape without ever running the upload.
     *
     * @param Upload $subject
     * @param callable $proceed
     * @return ResultInterface|ResponseInterface
     */
    public function aroundExecute(Upload $subject, callable $proceed): ResultInterface|ResponseInterface
    {
        if (!$this->config->isFileUploadDisabled()) {
            return $proceed();
        }

        $this->attackLogger->log(self::ATTEMPT, ['files' => $this->postedFiles($subject)]);

        // A result, not an exception: every probe would otherwise be a 500 plus a var/report file.
        return $this->resultJsonFactory->create()
            ->setHttpResponseCode(403)
            ->setData([
                // phpcs:ignore Magento2.Translation.ConstantUsage -- phrase is listed in i18n/en_US.csv by hand
                'error' => __(self::BLOCKED_MESSAGE),
                'errorcode' => 0,
            ]);
    }

    /**
     * Describe each file posted under custom_attributes, keyed the way core reads them.
     *
     * @param Upload $subject
     * @return array<int, array<string, int|string>>
     */
    private function postedFiles(Upload $subject): array
    {
        $request = $subject->getRequest();
        if (!$request instanceof Request) {
            return [];
        }

        $files = [];
        foreach ((array)$request->getFiles('custom_attributes') as $attributeCode => $file) {
            $files[] = $this->attackLogger->fileDetails('custom_attributes[' . $attributeCode . ']', $file);
        }

        return $files;
    }
}
