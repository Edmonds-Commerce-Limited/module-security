<?php

namespace EdmondsCommerce\Security\Model\Log;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\HTTP\PhpEnvironment\Request;

class GetClientContext
{
    private ?ClientContext $context = null;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RemoteAddress    $remoteAddress
    ) {
    }

    public function fetch(): ?ClientContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        if (!$this->request instanceof Request) {
            return null;
        }
        $forwardedFor = $this->request->getServer('HTTP_X_FORWARDED_FOR');
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            $forwardedFor = $this->clip($forwardedFor);
        }

        $this->context = new ClientContext(
            (string)$this->remoteAddress->getRemoteAddress(),
            $forwardedFor,
            $this->request->getMethod(),
            $this->request->getUri(),
            $this->request->getServer('HTTP_USER_AGENT')
        );

        return $this->context;
    }
}