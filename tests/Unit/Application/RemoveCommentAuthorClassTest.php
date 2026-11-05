<?php

declare(strict_types=1);

use Pollora\Portcullis\Application\Service\RemoveCommentAuthorClass;

it('removes the class derived from the author slug', function (): void {
    // What get_comment_class() builds for a comment by a registered user.
    $classes = ['comment', 'byuser', 'comment-author-jane', 'bypostauthor', 'even', 'thread-even', 'depth-1'];

    expect((new RemoveCommentAuthorClass)->remove($classes, 'comment-author-jane'))
        ->toBe(['comment', 'byuser', 'bypostauthor', 'even', 'thread-even', 'depth-1']);
});

it('keeps the classes a theme or a plugin adds with the same prefix', function (): void {
    expect((new RemoveCommentAuthorClass)->remove(['comment-author-jane', 'comment-author-verified'], 'comment-author-jane'))
        ->toBe(['comment-author-verified']);
});

it('keeps a class that is not a string', function (): void {
    expect((new RemoveCommentAuthorClass)->remove(['comment', 42], 'comment-author-jane'))->toBe(['comment', 42]);
});
