<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Domain\Model\DefaultEndpoint;
use Pollora\Portcullis\Port\Out\RequestContextPort;

/**
 * A request described by plain values.
 */
final class FakeRequestContext implements RequestContextPort
{
    public function __construct(
        public string $uri = '/',
        public string $home = '',
        public ?DefaultEndpoint $endpoint = null,
        public string $action = 'login',
        public bool $loggedIn = false,
        public bool $nonHttp = false,
    ) {}

    public function requestUri(): string
    {
        return $this->uri;
    }

    public function homePath(): string
    {
        return $this->home;
    }

    public function defaultEndpoint(): ?DefaultEndpoint
    {
        return $this->endpoint;
    }

    public function requestedAction(): string
    {
        return $this->action;
    }

    public function isUserLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function isNonHttpContext(): bool
    {
        return $this->nonHttp;
    }
}
