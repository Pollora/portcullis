<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Tests\Support;

use RuntimeException;

/**
 * Stands for "the response was sent and the script ended".
 */
final class ResponseSent extends RuntimeException {}
