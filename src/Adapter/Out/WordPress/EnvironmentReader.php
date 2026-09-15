<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Exception\InvalidThrottlePolicyException;

/**
 * Reads a configuration value from a PHP constant, falling back to the environment.
 *
 * Same lookup order as {@see EnvironmentSlugProvider}: the constant first, because
 * Bedrock-style installations define it from `.env` before WordPress boots, then
 * the raw environment for hosts that export variables from the web server or the
 * container. A constant defined as `null` — what `Config::define(..., env(...))`
 * yields for an absent variable — counts as unset.
 */
final class EnvironmentReader
{
    /**
     * The raw value of the first key that holds one, `null` when none does.
     *
     * Blank strings count as unset, so that `PORTCULLIS_ALLOWLIST=` in a `.env`
     * template does not shadow a fallback key.
     *
     * @param  string  ...$keys  Names looked up in order.
     */
    public function raw(string ...$keys): bool|int|string|null
    {
        foreach ($keys as $key) {
            if (defined($key)) {
                $value = constant($key);

                if (is_bool($value) || is_int($value) || (is_string($value) && trim($value) !== '')) {
                    return $value;
                }

                continue;
            }

            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A string value, `null` when unset.
     *
     * @param  string  ...$keys  Names looked up in order.
     */
    public function string(string ...$keys): ?string
    {
        $value = $this->raw(...$keys);

        return is_string($value) ? trim($value) : null;
    }

    /**
     * An integer value, `$default` when unset.
     *
     * @param  string  $key  Name looked up.
     * @param  int  $default  Value used when nothing is configured.
     *
     * @throws InvalidThrottlePolicyException When a value is configured but is not an integer.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->raw($key);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        $string = trim((string) $value);

        if (preg_match('/^-?\d+$/', $string) !== 1) {
            throw InvalidThrottlePolicyException::notAnInteger($key, $string);
        }

        return (int) $string;
    }

    /**
     * A boolean value, `$default` when unset or unrecognised.
     *
     * @param  string  $key  Name looked up.
     * @param  bool  $default  Value used when nothing usable is configured.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->raw($key);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim((string) $value))) {
            'true', '1', 'on', 'yes', 'enabled' => true,
            'false', '0', 'off', 'no', 'disabled' => false,
            default => $default,
        };
    }
}
