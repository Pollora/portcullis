<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use Pollora\Portcullis\Port\Out\NotFoundResponderPort;

/**
 * 404 double, with the same observable contract as the real responder.
 */
final class FakeNotFound implements NotFoundResponderPort
{
    public bool $prepared = false;

    public function prepare(): void
    {
        $this->prepared = true;
    }

    public function respond(): never
    {
        throw new ResponseSent('404');
    }
}
