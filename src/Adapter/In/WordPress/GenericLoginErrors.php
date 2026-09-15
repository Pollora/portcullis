<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use WP_Error;

/**
 * Stops the login screen from telling unknown accounts apart from wrong passwords.
 *
 * Out of the box, WordPress answers "the username x is not registered" in one
 * case and "the password you entered for the username x is incorrect" in the
 * other. That turns the login form into an oracle for valid accounts, which is
 * the first step of any targeted attack.
 *
 * Only the displayed messages change: error codes are kept, so that anything
 * keying off them — the form shake, two-factor providers, this package's own
 * counting — behaves exactly as before. The replacement is WordPress' own
 * generic message, which already ships translated in every locale.
 */
final class GenericLoginErrors
{
    /**
     * Codes whose message discloses whether the account exists.
     *
     * @var list<string>
     */
    private const DISCLOSING_CODES = ['incorrect_password', 'invalid_email', 'invalid_username'];

    /**
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(private readonly HookRegistrarPort $hooks) {}

    /**
     * Registers the filter.
     */
    public function register(): void
    {
        $this->hooks->addFilter('wp_login_errors', [$this, 'filter'], PHP_INT_MAX, 1);
    }

    /**
     * Replaces disclosing messages with the generic one.
     *
     * @internal Hooked on `wp_login_errors`; not part of the public API.
     *
     * @param  mixed  $errors  Errors about to be displayed, a WP_Error unless a plugin misbehaves.
     */
    public function filter(mixed $errors): mixed
    {
        if (! $errors instanceof WP_Error) {
            return $errors;
        }

        $disclosing = array_values(array_intersect($errors->get_error_codes(), self::DISCLOSING_CODES));

        if ($disclosing === []) {
            return $errors;
        }

        foreach ($disclosing as $code) {
            $errors->remove($code);
        }

        // One message is enough, whichever codes it stands for; the first code is
        // kept so that the form still shakes.
        $errors->add($disclosing[0], __('<strong>Error:</strong> Invalid username, email address or incorrect password.'));

        return $errors;
    }
}
