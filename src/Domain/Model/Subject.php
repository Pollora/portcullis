<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Domain\Model;

use InvalidArgumentException;
use Pollora\Portcullis\Application\Service\DeriveSubjects;

/**
 * The pseudonymous key a failure counter is stored under.
 *
 * Neither the address nor the login is ever stored: only a keyed hash of it.
 * The table therefore holds no personal data an operator would have to declare,
 * and a production dump restored elsewhere reveals nothing and blocks no one,
 * because the key differs between environments.
 *
 * Instances come from {@see DeriveSubjects},
 * which owns the hashing.
 */
final class Subject
{
    /**
     * Length, in bytes, of a key: the raw output of HMAC-SHA256.
     */
    public const KEY_LENGTH = 32;

    /**
     * @param  SubjectScope  $scope  What the counter is attached to.
     * @param  string  $key  Raw binary key, {@see self::KEY_LENGTH} bytes long.
     *
     * @throws InvalidArgumentException When the key does not have the expected length.
     */
    public function __construct(
        private readonly SubjectScope $scope,
        private readonly string $key,
    ) {
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('A subject key must be %d bytes long.', self::KEY_LENGTH));
        }
    }

    /**
     * Rebuilds a subject from the hexadecimal form produced by {@see self::hexKey()}.
     *
     * @param  SubjectScope  $scope  What the counter is attached to.
     * @param  string  $hexKey  Hexadecimal key.
     *
     * @throws InvalidArgumentException When the value is not a valid hexadecimal key.
     */
    public static function fromHex(SubjectScope $scope, string $hexKey): self
    {
        if (preg_match('/^[0-9a-f]{'.(self::KEY_LENGTH * 2).'}$/i', $hexKey) !== 1) {
            throw new InvalidArgumentException('A subject key must be written as 64 hexadecimal characters.');
        }

        return new self($scope, (string) hex2bin($hexKey));
    }

    /**
     * What the counter is attached to.
     */
    public function scope(): SubjectScope
    {
        return $this->scope;
    }

    /**
     * Raw binary key, as stored.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Hexadecimal key, for logs, cache keys and WP-CLI output.
     */
    public function hexKey(): string
    {
        return bin2hex($this->key);
    }

    /**
     * Whether both subjects designate the same counter.
     *
     * @param  self  $other  Subject to compare with.
     */
    public function equals(self $other): bool
    {
        return $this->scope === $other->scope && hash_equals($this->key, $other->key);
    }
}
