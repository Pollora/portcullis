<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Port\Out\SlugProviderPort;

/**
 * Reads the login slug from a PHP constant, falling back to the environment.
 *
 * The constant is looked up first because Bedrock-style installations define it
 * from `.env` in `config/application.php`, which makes it available to every
 * SAPI — web, WP-CLI and cron alike — before WordPress even boots.
 *
 * Nothing is read from the database on purpose. A slug stored as an option
 * would travel with production dumps restored on staging and local machines,
 * where it would either leak the production secret or lock developers out of an
 * environment they cannot reach a terminal on.
 */
final class EnvironmentSlugProvider implements SlugProviderPort
{
    /**
     * Name of the constant and of the environment variable holding the slug.
     */
    public const KEY = 'PORTCULLIS_LOGIN_SLUG';

    /**
     * Name the slug was read from in `pollora/hidden-login` 1.x.
     *
     * Still honoured, after {@see self::KEY}, so that renaming the package does
     * not silently switch the protection off on installations whose `.env` was
     * written for 1.x.
     */
    public const LEGACY_KEY = 'HIDDEN_LOGIN_SLUG';

    /**
     * @var list<string>
     */
    private readonly array $keys;

    /**
     * @param  string  ...$keys  Names looked up in order. Overridable for hosts that
     *                           already own the default names.
     */
    public function __construct(string ...$keys)
    {
        $this->keys = $keys === [] ? [self::KEY, self::LEGACY_KEY] : array_values($keys);
    }

    /**
     * {@inheritDoc}
     */
    public function slug(): ?string
    {
        foreach ($this->keys as $key) {
            $value = $this->fromConstant($key) ?? $this->fromEnvironment($key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Reads the slug from a PHP constant.
     *
     * A constant defined with a `null` or non-string value — which is what
     * `Config::define('PORTCULLIS_LOGIN_SLUG', env('PORTCULLIS_LOGIN_SLUG') ?: null)`
     * produces when the variable is absent — is treated as "not configured".
     *
     * @param  string  $key  Name of the constant.
     */
    private function fromConstant(string $key): ?string
    {
        if (! defined($key)) {
            return null;
        }

        $value = constant($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Reads the slug from the process environment.
     *
     * Covers installations that do not go through Bedrock's configuration layer
     * and simply export the variable from the web server or the container.
     *
     * @param  string  $key  Name of the environment variable.
     */
    private function fromEnvironment(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
