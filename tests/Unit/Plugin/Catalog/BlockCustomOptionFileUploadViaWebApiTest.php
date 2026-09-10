<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Unit\Plugin\Catalog;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomOptions\Config;
use EdmondsCommerce\Security\Plugin\Catalog\BlockCustomOptionFileUpload;
use EdmondsCommerce\Security\Plugin\Catalog\BlockCustomOptionFileUploadViaWebApi;
use Magento\Catalog\Model\Webapi\Product\Option\Type\File\Processor;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The base64 Web API route never reaches ValidatorFile, so it needs its own guard. Reaching this
 * method at all means content was supplied, hence no "was anything posted" branch to cover.
 */
class BlockCustomOptionFileUploadViaWebApiTest extends TestCase
{
    // 12 bytes, so the base64 length maps back to the exact size
    private const PAYLOAD = 'GIF89a<?php ';

    /**
     * @var AttackLogger&MockObject
     */
    private MockObject $attackLogger;

    protected function setUp(): void
    {
        // log() only: fileDetails() stays real, so the details asserted are the ones really built
        $this->attackLogger = $this->createPartialMock(AttackLogger::class, ['log']);
    }

    #[Test]
    public function fileContentIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(BlockCustomOptionFileUpload::BLOCKED_MESSAGE);

        $this->createPlugin(true)->beforeProcessFileContent(
            $this->createMock(Processor::class),
            $this->imageContent()
        );
    }

    #[Test]
    public function theAttemptIsLoggedWithTheFile(): void
    {
        $this->attackLogger->expects($this->once())
            ->method('log')
            ->with(BlockCustomOptionFileUploadViaWebApi::ATTEMPT, [
                'files' => [[
                    'field' => 'file_info',
                    'name' => 'shell.php',
                    'type' => 'image/gif',
                    'size' => strlen(self::PAYLOAD),
                ]],
            ]);
        $this->expectException(LocalizedException::class);

        $this->createPlugin(true)->beforeProcessFileContent(
            $this->createMock(Processor::class),
            $this->imageContent()
        );
    }

    #[Test]
    public function coreIsLeftAloneWhenTheHardeningIsSwitchedOff(): void
    {
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertNull(
            $this->createPlugin(false)->beforeProcessFileContent(
                $this->createMock(Processor::class),
                $this->imageContent()
            )
        );
    }

    private function createPlugin(bool $disabled): BlockCustomOptionFileUploadViaWebApi
    {
        $config = $this->createMock(Config::class);
        $config->method('isFileUploadDisabled')->willReturn($disabled);

        return new BlockCustomOptionFileUploadViaWebApi($config, $this->attackLogger);
    }

    private function imageContent(): ImageContentInterface
    {
        $imageContent = $this->createMock(ImageContentInterface::class);
        $imageContent->method('getName')->willReturn('shell.php');
        $imageContent->method('getType')->willReturn('image/gif');
        $imageContent->method('getBase64EncodedData')->willReturn(base64_encode(self::PAYLOAD));

        return $imageContent;
    }
}
