<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Domain\Model\LoginSlug;
use Pollora\Portcullis\Port\Out\LoginScreenRendererPort;

/**
 * Login screen double: `render()` ends the request the way the real one does,
 * which a test observes as an exception.
 */
final class FakeLoginScreen implements LoginScreenRendererPort
{
    public ?LoginSlug $prepared = null;

    public function prepare(LoginSlug $slug): void
    {
        $this->prepared = $slug;
    }

    public function render(): never
    {
        throw new ResponseSent('login screen');
    }
}
