<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

/**
 * How author archives are treated.
 */
enum AuthorArchivePolicy
{
    /**
     * Core serves author archives as it always did: the account enumeration
     * protection is off and the archives are not closed.
     */
    case Untouched;

    /**
     * Archives that list posts stay public. An archive that lists nothing answers a 404: core serves it for any
     * member, a customer included, which confirms that the account exists.
     */
    case PublicWithPosts;

    /**
     * Every author archive answers a 404 and author links point to the home page:
     * `PORTCULLIS_AUTHOR_ARCHIVES` is falsy. Closing them changes public URLs, so
     * it has to be asked for.
     */
    case Closed;
}
