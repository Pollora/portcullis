<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use RuntimeException;

/**
 * The database behind the attempt store cannot be used: its table cannot be created, or a query failed.
 *
 * It extends RuntimeException, as the plain RuntimeException it replaces did, so a caller that catches
 * RuntimeException still does; the messages are unchanged.
 */
final class StorageException extends RuntimeException
{
    /**
     * The table cannot be created, or a previous attempt to create it failed and is not retried yet.
     */
    public static function schema(string $error): self
    {
        return new self($error);
    }

    /**
     * A query of the attempt store failed.
     */
    public static function query(string $error): self
    {
        return new self('[portcullis] '.$error);
    }
}
