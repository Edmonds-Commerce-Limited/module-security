<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Test\Unit\Model;

use EdmondsCommerce\Security\Model\Log\AttackLogger;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every field written here comes from the client, so the log must stay bounded and must not break the
 * block it is recording.
 */
class AttackLoggerTest extends TestCase
{
    private const ATTEMPT = 'Blocked something';
    private const IP = '203.0.113.7';

    #[Test]
    public function theAttemptIsLoggedWithTheClientAndRequest(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(self::ATTEMPT, [
            'ip' => self::IP,
            'forwarded_for' => '198.51.100.1',
            'method' => 'POST',
            'uri' => '/customer/address_file/upload',
            'user_agent' => 'curl/8.0',
            'files' => ['detail'],
        ]);

        $this->createLogger($logger, $this->httpRequest([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_USER_AGENT' => 'curl/8.0',
        ]))->log(self::ATTEMPT, ['files' => ['detail']]);
    }

    #[Test]
    public function forwardedForIsLeftOutWhenTheClientDidNotSendIt(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::ATTEMPT, $this->logicalNot($this->arrayHasKey('forwarded_for')));

        $this->createLogger($logger, $this->httpRequest([]))->log(self::ATTEMPT);
    }

    #[Test]
    public function aNonHttpRequestIsLoggedWithTheIpAlone(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(self::ATTEMPT, ['ip' => self::IP]);

        $this->createLogger($logger, $this->createMock(RequestInterface::class))->log(self::ATTEMPT);
    }

    #[Test]
    public function aLogThatCannotBeWrittenDoesNotEscape(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->willThrowException(new \UnexpectedValueException('var/log is not writable'));

        $this->createLogger($logger, $this->httpRequest([]))->log(self::ATTEMPT);
    }

    #[Test]
    public function fileDetailsAreCutToABoundedLength(): void
    {
        $details = $this->createLogger()->fileDetails('options_7_file', ['name' => str_repeat('a', 1000)]);

        $this->assertSame(255, strlen((string)$details['name']));
    }

    /**
     * A client can post custom_attributes[code][name][]=x, which casting would turn into an exception.
     */
    #[Test]
    public function aNestedFileEntryIsDescribedAsEmpty(): void
    {
        $this->assertSame(
            ['field' => 'custom_attributes[probe]', 'name' => '', 'type' => '', 'size' => 0],
            $this->createLogger()->fileDetails(
                'custom_attributes[probe]',
                ['name' => ['a', 'b'], 'type' => ['c'], 'size' => ['d']]
            )
        );
    }

    #[Test]
    public function aFileEntryThatIsNotAnArrayIsDescribedAsEmpty(): void
    {
        $this->assertSame(
            ['field' => 'file_info', 'name' => '', 'type' => '', 'size' => 0],
            $this->createLogger()->fileDetails('file_info', 'not a file')
        );
    }

    private function createLogger(
        ?LoggerInterface $logger = null,
        ?RequestInterface $request = null
    ): AttackLogger {
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn(self::IP);

        return new \EdmondsCommerce\Security\Model\Log\AttackLogger(
            $logger ?? $this->createMock(LoggerInterface::class),
            $remoteAddress,
            $request ?? $this->createMock(RequestInterface::class)
        );
    }

    /**
     * @param array<string, string> $server
     * @return Http
     */
    private function httpRequest(array $server): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getRequestUri')->willReturn('/customer/address_file/upload');
        $request->method('getServer')->willReturnCallback(
            static fn (?string $name = null, mixed $default = null): mixed => $server[$name] ?? $default
        );

        return $request;
    }
}
