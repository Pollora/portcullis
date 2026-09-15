<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\Lockout;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use Pollora\Portcullis\Port\Out\LockoutNotifierPort;

/**
 * Reports lockouts to the PHP error log, and to the host through an action.
 *
 * One line per lockout, with a stable format so that it can be grepped or fed
 * to fail2ban. The action lets the host add what the package deliberately
 * leaves out — emails, a SIEM, a Slack channel — without a settings screen.
 */
final class ErrorLogLockoutNotifier implements LockoutNotifierPort
{
    /**
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(private readonly HookRegistrarPort $hooks) {}

    /**
     * {@inheritDoc}
     */
    public function lockedOut(Lockout $lockout, ClientIp $ip, string $login): void
    {
        error_log(sprintf(
            '[portcullis] lockout scope=%s ip=%s login=%s until=%s tier=%s',
            $lockout->subject()->scope()->label(),
            $ip->value(),
            $this->sanitiseForLog($login),
            gmdate('Y-m-d\TH:i:s\Z', $lockout->lockedUntil()),
            $lockout->isLong() ? 'long' : 'regular',
        ));

        /**
         * Fires when an address or an account has just been locked out.
         *
         * @param  Lockout  $lockout  The lockout.
         * @param  ClientIp  $ip  Address the failure that triggered it came from.
         * @param  string  $login  Login submitted with that failure, possibly empty.
         */
        $this->hooks->doAction('portcullis/locked_out', $lockout, $ip, $login);
    }

    /**
     * Keeps an attacker-supplied login from forging extra log lines.
     *
     * @param  string  $login  Raw login.
     */
    private function sanitiseForLog(string $login): string
    {
        $login = preg_replace('/[^\x20-\x7E]/', '?', $login) ?? '';

        return '"'.addcslashes(substr($login, 0, 100), '"\\').'"';
    }
}
