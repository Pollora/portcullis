<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\Out\WordPress;

use Pollora\Portcullis\Domain\Model\FeatureState;
use Pollora\Portcullis\Port\Out\FeatureTogglePort;

/**
 * Reads the kill switch from a PHP constant, falling back to the environment.
 *
 * Same lookup order and rationale as {@see EnvironmentSlugProvider}: the
 * constant first, because Bedrock-style installations define it from `.env`
 * before WordPress boots, then the raw environment for hosts that export it
 * from the web server or the container.
 */
final class EnvironmentFeatureToggle implements FeatureTogglePort
{
    /**
     * Name of the constant and of the environment variable holding the switch.
     */
    public const KEY = 'PORTCULLIS_ENABLED';

    /**
     * Name the switch was read from in `pollora/hidden-login` 1.x, still honoured
     * after {@see self::KEY}: an installation that switched 1.x off must not see
     * it come back on through a rename.
     */
    public const LEGACY_KEY = 'HIDDEN_LOGIN_ENABLED';

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
    public function state(): FeatureState
    {
        return FeatureState::fromConfiguredValue($this->configuredValue());
    }

    /**
     * The first raw value found, or `null` when nothing is configured.
     *
     * A constant defined as `null` — what `Config::define(..., env(...))`
     * produces for an absent variable — counts as unset, and therefore as
     * enabled.
     */
    private function configuredValue(): bool|string|null
    {
        foreach ($this->keys as $key) {
            if (defined($key)) {
                $value = constant($key);

                if (is_bool($value) || is_string($value)) {
                    return $value;
                }

                continue;
            }

            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
