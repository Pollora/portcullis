<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Port\Out\AttemptStorePort;

/**
 * Forgets the failures of subjects — after a successful login, or on an operator's request.
 */
final class ClearAttempts
{
    /**
     * @param  AttemptStorePort  $store  Where counters are kept.
     */
    public function __construct(private readonly AttemptStorePort $store) {}

    /**
     * Forgets everything about the subjects, lockouts included.
     *
     * @param  list<Subject>  $subjects  Subjects to forget.
     */
    public function clear(array $subjects): void
    {
        if ($subjects === []) {
            return;
        }

        $this->store->clear($subjects);
    }
}
