<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Plugin\Catalog;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomOptions\Config;
use Magento\Catalog\Model\Webapi\Product\Option\Type\File\Processor;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Refuses the base64 upload route into a product custom option of type "file".
 *
 * Web API callers pass file bytes as an ImageContentInterface extension attribute on a cart item
 * option, which never reaches ValidatorFile. Framework\Api\ImageProcessor also logs and swallows
 * upload failures, so this is the only place the route can be stopped.
 */
class BlockCustomOptionFileUploadViaWebApi
{
    public const ATTEMPT = 'Blocked product custom option file upload via Web API';

    /**
     * @param Config $config
     * @param AttackLogger $attackLogger
     */
    public function __construct(
        private readonly Config $config,
        private readonly AttackLogger $attackLogger
    ) {
    }

    /**
     * Refuse the file content before it is decoded and written to media.
     *
     * @param Processor $subject
     * @param ImageContentInterface $imageContent
     * @return array<int, mixed>|null
     * @throws LocalizedException
     */
    public function beforeProcessFileContent(Processor $subject, ImageContentInterface $imageContent): ?array
    {
        if (!$this->config->isFileUploadDisabled()) {
            return null;
        }

        $this->attackLogger->log(self::ATTEMPT, [
            'files' => [
                $this->attackLogger->fileDetails('file_info', [
                    'name' => $imageContent->getName(),
                    'type' => $imageContent->getType(),
                    // Decoded size from the base64 length, without decoding an attacker's payload
                    'size' => intdiv(strlen((string)$imageContent->getBase64EncodedData()) * 3, 4),
                ]),
            ],
        ]);

        // phpcs:ignore Magento2.Translation.ConstantUsage -- phrase is listed in i18n/en_US.csv by hand
        throw new LocalizedException(__(BlockCustomOptionFileUpload::BLOCKED_MESSAGE));
    }
}
