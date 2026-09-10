<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Integration\CustomOptions;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\Test;
use EdmondsCommerce\Security\Plugin\Catalog\BlockCustomOptionFileUpload;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Proves the block is really wired into a booted application, which the unit tests cannot show: the
 * plugin is declared per area, so a missing or misnamed di.xml would pass unit tests and do nothing.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class FileUploadBlockedTest extends TestCase
{
    private const POSTED_FILE_NAME = 'polyglot.gif';

    // The fake upload cannot satisfy is_uploaded_file(), so core rejects it on its own terms.
    private const CORE_REJECTION = "The file '" . self::POSTED_FILE_NAME . "' is invalid. Please choose another one";

    /**
     * @var string|null
     */
    private ?string $tmpFile = null;

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        parent::tearDown();
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_custom_file_option.php
     */
    #[BackupGlobals(true)]
    #[Test]
    public function anUploadIsRefusedByDefault(): void
    {
        $option = $this->fileOption();
        $this->postAFile($option);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(BlockCustomOptionFileUpload::BLOCKED_MESSAGE);

        $this->validator()->validate(new DataObject(), $option);
    }

    /**
     * Turning the switch off has to hand the route straight back to core, untouched.
     *
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_custom_file_option.php
     * @magentoConfigFixture default_store edmondscommerce_security/custom_options/disable_file_upload 0
     */
    #[BackupGlobals(true)]
    #[Test]
    public function coreHandlesTheUploadWhenTheHardeningIsSwitchedOff(): void
    {
        $option = $this->fileOption();
        $this->postAFile($option);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(self::CORE_REJECTION);

        $this->validator()->validate(new DataObject(), $option);
    }

    /**
     * The plugin is declared per area, so the backend has to be left able to attach a file.
     *
     * @magentoAppArea adminhtml
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_custom_file_option.php
     */
    #[BackupGlobals(true)]
    #[Test]
    public function theAdminIsNotBlocked(): void
    {
        $option = $this->fileOption();
        $this->postAFile($option);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(self::CORE_REJECTION);

        $this->validator()->validate(new DataObject(), $option);
    }

    private function validator(): ValidatorFile
    {
        return Bootstrap::getObjectManager()->create(ValidatorFile::class);
    }

    private function fileOption(): Option
    {
        $product = Bootstrap::getObjectManager()
            ->get(ProductRepositoryInterface::class)
            ->get('simple_with_custom_file_option');

        foreach ((array)$product->getOptions() as $option) {
            if ($option instanceof Option && $option->getType() === 'file') {
                return $option;
            }
        }

        $this->fail('The fixture product no longer carries a custom option of type file.');
    }

    /**
     * @param Option $option
     * @return void
     */
    private function postAFile(Option $option): void
    {
        $this->tmpFile = (string)tempnam(sys_get_temp_dir(), 'ec-security-');
        file_put_contents($this->tmpFile, 'GIF89a');

        $_FILES['options_' . $option->getId() . '_file'] = [
            'name' => self::POSTED_FILE_NAME,
            'type' => 'image/gif',
            'tmp_name' => $this->tmpFile,
            'error' => 0,
            'size' => filesize($this->tmpFile),
        ];
    }
}
