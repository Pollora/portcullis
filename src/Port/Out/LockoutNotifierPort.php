<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Port\Out;

use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\Lockout;

/**
 * Reports lockouts to the operator.
 *
 * This is the only place the address and the login appear in clear, since the
 * store only ever holds their hashes — without it, nobody could tell who was
 * locked out.
 */
interface LockoutNotifierPort
{
    /**
     * Reports a lockout that has just been applied.
     *
     * @param  Lockout  $lockout  The lockout.
     * @param  ClientIp  $ip  Address the failure that triggered it came from.
     * @param  string  $login  Login submitted with that failure, possibly empty.
     */
    public function lockedOut(Lockout $lockout, ClientIp $ip, string $login): void;
}
