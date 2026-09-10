<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Integration\CustomerAddress;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use EdmondsCommerce\Security\Plugin\Customer\BlockAddressFileUpload;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the block is wired to the real route, anonymously, as SessionReaper hits it.
 *
 * @magentoAppArea frontend
 */
class FileUploadBlockedTest extends AbstractController
{
    private const URI = 'customer/address_file/upload';
    private const PROBE_NAME = 'sess_ecsecurityprobe';

    #[Test]
    public function anAnonymousUploadIsRefusedByDefault(): void
    {
        $this->postProbe();

        $this->dispatch(self::URI);

        $this->assertSame(403, $this->httpResponse()->getHttpResponseCode());
        $this->assertSame(
            ['error' => BlockAddressFileUpload::BLOCKED_MESSAGE, 'errorcode' => 0],
            json_decode((string)$this->httpResponse()->getBody(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Unit tests mock the logger, so only this shows the virtual types really reach the one log file.
     */
    #[Test]
    public function theAttemptIsWrittenToTheAttackLog(): void
    {
        $this->postProbe();
        $offset = $this->logSize();

        $this->dispatch(self::URI);

        $logged = $this->logSince($offset);
        $this->assertStringContainsString(BlockAddressFileUpload::ATTEMPT, $logged);
        $this->assertStringContainsString(self::PROBE_NAME, $logged);
    }

    /**
     * @magentoConfigFixture default_store edmondscommerce_security/customer_address/disable_file_upload 0
     */
    #[Test]
    public function coreHandlesTheRouteWhenTheHardeningIsSwitchedOff(): void
    {
        $this->httpRequest()->setMethod(HttpRequest::METHOD_POST);
        $offset = $this->logSize();

        $this->dispatch(self::URI);

        $body = (string)$this->httpResponse()->getBody();
        $this->assertStringNotContainsString(BlockAddressFileUpload::BLOCKED_MESSAGE, $body);
        $this->assertStringContainsString('No files for upload.', $body);
        $this->assertStringNotContainsString(BlockAddressFileUpload::ATTEMPT, $this->logSince($offset));
    }

    private function postProbe(): void
    {
        $this->httpRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->httpRequest()->setFiles(new Parameters([
            'custom_attributes' => [
                'probe' => [
                    'name' => self::PROBE_NAME,
                    'type' => 'application/octet-stream',
                    'tmp_name' => __FILE__,
                    'error' => 0,
                    'size' => filesize(__FILE__),
                ],
            ],
        ]));
    }

    private function logPath(): string
    {
        // Handler\Base resolves the file against BP, which only Magento's bootstrap defines
        return BP . '/' . AttackLogger::LOG_FILE; // @phpstan-ignore constant.notFound
    }

    private function logSize(): int
    {
        clearstatcache(true, $this->logPath());

        return is_file($this->logPath()) ? (int)filesize($this->logPath()) : 0;
    }

    private function logSince(int $offset): string
    {
        clearstatcache(true, $this->logPath());

        return is_file($this->logPath()) ? (string)file_get_contents($this->logPath(), false, null, $offset) : '';
    }

    private function httpRequest(): HttpRequest
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest) {
            $this->fail('The test framework no longer hands out an HTTP request.');
        }

        return $request;
    }

    private function httpResponse(): HttpResponse
    {
        $response = $this->getResponse();
        if (!$response instanceof HttpResponse) {
            $this->fail('The test framework no longer hands out an HTTP response.');
        }

        return $response;
    }
}
