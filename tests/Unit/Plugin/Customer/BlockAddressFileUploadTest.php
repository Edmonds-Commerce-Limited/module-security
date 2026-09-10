<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Unit\Plugin\Customer;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Model\CustomerAddress\Config;
use EdmondsCommerce\Security\Plugin\Customer\BlockAddressFileUpload;
use Magento\Customer\Controller\Address\File\Upload;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The upload must never run while blocked, since running it is what writes the file.
 */
class BlockAddressFileUploadTest extends TestCase
{
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
    public function theUploadIsRefusedWithoutRunningIt(): void
    {
        $result = $this->createMock(Json::class);
        $result->expects($this->once())->method('setHttpResponseCode')->with(403)->willReturnSelf();
        $result->expects($this->once())
            ->method('setData')
            ->with($this->callback(static fn (array $data): bool => $data['errorcode'] === 0
                && (string)$data['error'] === BlockAddressFileUpload::BLOCKED_MESSAGE))
            ->willReturnSelf();

        $this->assertSame(
            $result,
            $this->createPlugin(true, $result)->aroundExecute($this->upload([]), $this->coreMustNotRun())
        );
    }

    #[Test]
    public function theAttemptIsLoggedWithEveryPostedFile(): void
    {
        $this->attackLogger->expects($this->once())
            ->method('log')
            ->with(BlockAddressFileUpload::ATTEMPT, [
                'files' => [
                    ['field' => 'custom_attributes[probe]', 'name' => 'sess_x', 'type' => 'text/plain', 'size' => 6],
                ],
            ]);

        $this->createPlugin(true, $this->jsonResult())->aroundExecute(
            $this->upload(['probe' => ['name' => 'sess_x', 'type' => 'text/plain', 'size' => 6]]),
            $this->coreMustNotRun()
        );
    }

    #[Test]
    public function coreIsLeftAloneWhenTheHardeningIsSwitchedOff(): void
    {
        $coreResult = $this->createMock(Json::class);
        $this->attackLogger->expects($this->never())->method('log');

        $this->assertSame(
            $coreResult,
            $this->createPlugin(false, $this->createMock(Json::class))
                ->aroundExecute($this->upload([]), static fn (): Json => $coreResult)
        );
    }

    private function createPlugin(bool $disabled, Json $result): BlockAddressFileUpload
    {
        $config = $this->createMock(Config::class);
        $config->method('isFileUploadDisabled')->willReturn($disabled);

        $resultJsonFactory = $this->createMock(JsonFactory::class);
        $resultJsonFactory->method('create')->willReturn($result);

        return new BlockAddressFileUpload($config, $resultJsonFactory, $this->attackLogger);
    }

    /**
     * @param array<string, array<string, int|string>> $files
     * @return Upload
     */
    private function upload(array $files): Upload
    {
        $request = $this->createMock(Http::class);
        $request->method('getFiles')->with('custom_attributes')->willReturn($files);

        $upload = $this->createMock(Upload::class);
        $upload->method('getRequest')->willReturn($request);

        return $upload;
    }

    private function coreMustNotRun(): callable
    {
        return function (): never {
            $this->fail('The core upload ran while blocked.');
        };
    }

    private function jsonResult(): Json
    {
        $result = $this->createMock(Json::class);
        $result->method('setHttpResponseCode')->willReturnSelf();
        $result->method('setData')->willReturnSelf();

        return $result;
    }
}
