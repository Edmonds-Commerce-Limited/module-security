<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Plugin\Catalog;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomOptions\Config;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Adapter\FileTransferFactory;

/**
 * Refuses a multipart upload made against a product custom option of type "file".
 *
 * Magento's extension gate on this route is fail open. Uploader::checkAllowedExtension() permits any
 * alphanumeric extension unless an allow list was set, and processFileQueue() never sets one, so the
 * only filter is the option's own optional file_extension field. A polyglot payload satisfies the
 * image checks regardless, which leaves the route a general "customer writes a file of their choosing
 * into pub/media" primitive. Declared for customer facing areas only.
 */
class BlockCustomOptionFileUpload
{
    public const BLOCKED_MESSAGE = 'Uploading files through product options is disabled on this store.';
    public const ATTEMPT = 'Blocked product custom option file upload';

    /**
     * @param Config                                           $config
     * @param FileTransferFactory                              $httpFactory
     * @param \EdmondsCommerce\Security\Model\Log\AttackLogger $attackLogger
     */
    public function __construct(
        private readonly Config $config,
        private readonly FileTransferFactory $httpFactory,
        private readonly AttackLogger $attackLogger
    ) {
    }

    /**
     * Refuse the upload before core touches the posted file.
     *
     * @param ValidatorFile $subject
     * @param DataObject $processingParams
     * @param Option $option
     * @return array<int, mixed>|null
     * @throws LocalizedException
     */
    public function beforeValidate(
        ValidatorFile $subject,
        DataObject $processingParams,
        Option $option
    ): ?array {
        if (!$this->config->isFileUploadDisabled()) {
            return null;
        }

        $field = $this->fieldName($processingParams, $option);
        $file = $this->submittedFile($field);
        if ($file === null) {
            return null;
        }

        $this->attackLogger->log(self::ATTEMPT, [
            'product_id' => (string)$option->getData('product_id'),
            'option_id' => (string)$option->getId(),
            'files' => [$this->attackLogger->fileDetails($field, $file)],
        ]);

        // Must be a LocalizedException: Option\Type\File aliases Validator\Exception as Exception and
        // catches it first, which would swallow the block and put the item in the cart anyway.
        // phpcs:ignore Magento2.Translation.ConstantUsage -- phrase is listed in i18n/en_US.csv by hand
        throw new LocalizedException(__(self::BLOCKED_MESSAGE));
    }

    /**
     * The $_FILES key core reads this option's upload from.
     *
     * @param DataObject $processingParams
     * @param Option $option
     * @return string
     */
    private function fieldName(DataObject $processingParams, Option $option): string
    {
        // getData() rather than the magic getFilesPrefix() core uses, so static analysis can see it
        $prefix = (string)$processingParams->getData('files_prefix');

        return $prefix . 'options_' . (string)$option->getId() . '_file';
    }

    /**
     * The file actually posted for this option, if any.
     *
     * Core validates a file option even when nothing was uploaded and relies on the resulting exception
     * being swallowed, which is how an optional empty file option still reaches the cart. Refusing
     * unconditionally would make every product carrying an optional file option un-addable.
     *
     * @param string $field
     * @return array<string, mixed>|null
     */
    private function submittedFile(string $field): ?array
    {
        try {
            // Not isUploaded(): getFileInfo() falls back to every posted file when the key is absent,
            // so that would deny an unrelated option's upload elsewhere in the same request.
            $info = $this->httpFactory->create()->getFileInfo($field);
        } catch (\Exception) {
            return null;
        }

        return is_array($info) && is_array($info[$field] ?? null) && !empty($info[$field]['name'])
            ? $info[$field]
            : null;
    }
}
