<?php

namespace EdmondsCommerce\Security\Model\Log;

class ClientContext
{
    public function __construct(
        public readonly string $ipAddress,
        public readonly ?string $forwardedForHeader,
        public readonly string $httpMethod,
        public readonly string $uri,
        public readonly ?string $userAgent,
    ) {
    }

    public function toArray(): array
    {
        return [
            'ipAddress' => $this->ipAddress,
            'forwardedForHeader' => $this->forwardedForHeader,
            'httpMethod' => $this->httpMethod,
            'uri' => $this->uri,
            'userAgent' => $this->userAgent,
        ];
    }
}