<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

enum AuthorArchivePolicy
{
    case Untouched;

    /**
     * An archive that lists nothing answers a 404: core serves it for any
     * member, a customer included, which confirms that the account exists.
     */
    case PublicWithPosts;

    case Closed;
}
