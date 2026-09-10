<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Unit\Plugin\Catalog;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomOptions\Config;
use EdmondsCommerce\Security\Plugin\Catalog\BlockCustomOptionFileUpload;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Http;
use Magento\Framework\HTTP\Adapter\FileTransferFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The plugin has to refuse an upload without breaking the two cases core relies on: a required option
 * with nothing posted must still produce core's own "required option" error, and an optional option
 * with nothing posted must still add to cart. Those are what these tests pin down.
 */
class BlockCustomOptionFileUploadTest extends TestCase
{
    private const OPTION_ID = 7;
    private const PRODUCT_ID = 42;
    private const FIELD = 'options_7_file';

    /**
     * @var \EdmondsCommerce\Security\Model\Log\AttackLogger&MockObject
     */
    private MockObject $attackLogger;

    protected function setUp(): void
    {
        // log() only: fileDetails() stays real, so the details asserted are the ones really built
        $this->attackLogger = $this->createPartialMock(AttackLogger::class, ['log']);
    }

    #[Test]
    public function aFileSubmittedForTheOptionIsRefused(): void
    {
        $plugin = $this->createPlugin(true, [self::FIELD => ['name' => 'polyglot.gif']]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(BlockCustomOptionFileUpload::BLOCKED_MESSAGE);

        $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option());
    }

    #[Test]
    public function theAttemptIsLoggedWithTheProductOptionAndFile(): void
    {
        $plugin = $this->createPlugin(true, [
            self::FIELD => ['name' => 'polyglot.php', 'type' => 'image/gif', 'size' => 1337],
        ]);

        $this->attackLogger->expects($this->once())->method('log')->with(BlockCustomOptionFileUpload::ATTEMPT, [
            'product_id' => (string)self::PRODUCT_ID,
            'option_id' => (string)self::OPTION_ID,
            'files' => [['field' => self::FIELD, 'name' => 'polyglot.php', 'type' => 'image/gif', 'size' => 1337]],
        ]);
        $this->expectException(LocalizedException::class);

        $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option());
    }

    #[Test]
    public function aFileSubmittedUnderAPrefixIsRefused(): void
    {
        $plugin = $this->createPlugin(true, ['item_3_' . self::FIELD => ['name' => 'polyglot.gif']]);

        $this->expectException(LocalizedException::class);

        $plugin->beforeValidate(
            $this->createMock(ValidatorFile::class),
            new DataObject(['files_prefix' => 'item_3_']),
            $this->option()
        );
    }

    #[Test]
    public function coreIsLeftAloneWhenTheHardeningIsSwitchedOff(): void
    {
        $plugin = $this->createPlugin(false, [self::FIELD => ['name' => 'polyglot.gif']]);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option())
        );
    }

    /**
     * Core validates a file option even when nothing was posted, so refusing here would make any
     * product carrying an optional file option un-addable.
     */
    #[Test]
    public function coreIsLeftAloneWhenNoFileWasPosted(): void
    {
        $plugin = $this->createPlugin(true, []);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option())
        );
    }

    /**
     * getFileInfo() falls back to every posted file when the requested key is absent, so a bare
     * isUploaded() check would deny an unrelated upload elsewhere in the same request.
     */
    #[Test]
    public function aFilePostedForADifferentFieldIsIgnored(): void
    {
        $plugin = $this->createPlugin(true, ['options_99_file' => ['name' => 'holiday.jpg']]);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option())
        );
    }

    #[Test]
    public function anEmptyFileNameIsNotTreatedAsAnUpload(): void
    {
        $plugin = $this->createPlugin(true, [self::FIELD => ['name' => '']]);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option())
        );
    }

    /**
     * The adapter is Laminas backed on older Magento releases and can raise on an unknown key.
     */
    #[Test]
    public function aFailingAdapterDoesNotBreakAddToCart(): void
    {
        $plugin = $this->createPlugin(true, null);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $plugin->beforeValidate($this->createMock(ValidatorFile::class), new DataObject(), $this->option())
        );
    }

    /**
     * @param bool $disabled
     * @param array<string, array<string, int|string>>|null $fileInfo Null makes the adapter throw.
     * @return BlockCustomOptionFileUpload
     */
    private function createPlugin(bool $disabled, ?array $fileInfo): BlockCustomOptionFileUpload
    {
        $config = $this->createMock(Config::class);
        $config->method('isFileUploadDisabled')->willReturn($disabled);

        $adapter = $this->createMock(Http::class);
        if ($fileInfo === null) {
            $adapter->method('getFileInfo')->willThrowException(new \RuntimeException('unknown file'));
        } else {
            $adapter->method('getFileInfo')->willReturnCallback(
                static fn ($file = null) => isset($fileInfo[$file]) ? [$file => $fileInfo[$file]] : $fileInfo
            );
        }

        $httpFactory = $this->createMock(FileTransferFactory::class);
        $httpFactory->method('create')->willReturn($adapter);

        return new BlockCustomOptionFileUpload($config, $httpFactory, $this->attackLogger);
    }

    private function option(): Option
    {
        $option = $this->createMock(Option::class);
        $option->method('getId')->willReturn(self::OPTION_ID);
        $option->method('getData')->willReturnMap([['product_id', null, self::PRODUCT_ID]]);

        return $option;
    }
}
