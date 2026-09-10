<?php

declare(strict_types=1);

namespace EdmondsCommerce\Security\Model\Log;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records every blocked attempt and the IP address it came from
 */
class AttackLogger
{
    public const LOG_FILE = 'var/log/edmondscommerce_security.log';

    private const MAX_LENGTH = 255;

    public function __construct(
        private readonly LoggerInterface  $logger,
        private readonly GetClientContext $context,
    ) {
    }

    public function log(string $attempt, array $details = []): void
    {
        $clientContext = $this->context->fetch();
        try {
            $this->logger->warning($attempt, [...array_map([$this, 'clip'], $clientContext->toArray()), ...$details]);
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Describe one posted file without trusting its shape.
     *
     * @param mixed  $file
     *
     * @return array<string, int|string>
     */
    public function fileDetails(string $field, mixed $file): array
    {
        $file = is_array($file) ? $file : [];

        return [
            'field' => $this->clip($field),
            'name'  => $this->clip($file['name'] ?? ''),
            'type'  => $this->clip($file['type'] ?? ''),
            'size'  => is_numeric($file['size'] ?? null) ? (int)$file['size'] : 0,
        ];
    }

    /**
     * Cut a client-supplied value to a bounded string.
     *
     * @param mixed $value
     *
     * @return string
     */
    private function clip(mixed $value): string
    {
        return is_scalar($value) ? mb_strcut((string)$value, 0, self::MAX_LENGTH, 'UTF-8') : '';
    }
}
