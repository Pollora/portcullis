<?php

declare(strict_types=1);

use Pollora\Portcullis\Adapter\Out\WordPress\StorageException;

it('keeps the message of a schema failure and stays a RuntimeException', function (): void {
    $exception = StorageException::schema('Could not create the table.');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('Could not create the table.');
});

it('prefixes the message of a query failure with the package name and stays a RuntimeException', function (): void {
    $exception = StorageException::query('Deadlock found.');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe('[portcullis] Deadlock found.');
});
