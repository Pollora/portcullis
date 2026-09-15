<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use WP_User;

/**
 * Turns whatever was typed in the login field into a stable account identifier.
 *
 * WordPress accepts a username or an email address for the same account. Keyed
 * on the raw input, an attacker would get two independent counters per account,
 * and the successful login that clears one would leave the other in place. An
 * existing account is therefore identified by its numeric ID; only logins that
 * match no account fall back to the typed value.
 */
final class AccountIdentifier
{
    /**
     * Identifier of the account a login designates.
     *
     * Costs up to two lookups by indexed column, the same ones core runs to
     * check the password — and WordPress caches users, so the second call in a
     * request is free.
     *
     * @param  string  $login  Username or email address, as submitted.
     */
    public function forLogin(string $login): string
    {
        $login = trim($login);

        if ($login === '') {
            return '';
        }

        $user = get_user_by('login', $login);

        if (! $user instanceof WP_User && is_email($login)) {
            $user = get_user_by('email', $login);
        }

        return $user instanceof WP_User ? $this->forUser($user) : 'login:'.$login;
    }

    /**
     * Identifier of an account.
     *
     * @param  WP_User  $user  The account.
     */
    public function forUser(WP_User $user): string
    {
        return 'user:'.$user->ID;
    }
}
