<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\RemoveOembedAuthor;

it('removes the author from an oEmbed response', function (): void {
    $data = [
        'version' => '1.0',
        'provider_name' => 'Example',
        'author_name' => 'jane',
        'author_url' => 'https://example.com/author/jane',
        'title' => 'Hello world',
    ];

    expect((new RemoveOembedAuthor)->remove($data))->toBe([
        'version' => '1.0',
        'provider_name' => 'Example',
        'title' => 'Hello world',
    ]);
});
