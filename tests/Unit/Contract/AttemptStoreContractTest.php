<?php

declare(strict_types=1);

use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Tests\Support\InMemoryAttemptStore;

/*
 * Holds the in-memory reference store to the AttemptStorePort contract.
 */

require_once __DIR__.'/../../Contract/AttemptStoreContract.php';

dataset('in-memory store', [
    'in memory' => [fn (): AttemptStorePort => new InMemoryAttemptStore],
]);

attemptStoreContract('in-memory store');
