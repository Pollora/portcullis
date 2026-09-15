<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Application\Service;

use InvalidArgumentException;
use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\Subject;
use Pollora\Portcullis\Domain\Model\SubjectScope;

/**
 * Turns an address and a login into the pseudonymous keys counters are stored under.
 *
 * The keys are HMAC-SHA256 digests under a per-installation secret, rather than
 * plain hashes. The whole IPv4 space fits in four billion values, which a plain
 * SHA-256 table covers in minutes: without the secret, a leaked table would
 * still be a list of addresses.
 */
final class DeriveSubjects
{
    /**
     * Shortest secret accepted, in bytes.
     */
    public const MINIMUM_SECRET_LENGTH = 32;

    /**
     * @param  string  $secret  Per-installation key.
     *
     * @throws InvalidArgumentException When the secret is too short to protect anything.
     */
    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The secret subjects are derived with must be at least %d bytes long.',
                self::MINIMUM_SECRET_LENGTH
            ));
        }
    }

    /**
     * The subjects a login attempt is counted against.
     *
     * The account subject is left out when no login was submitted: an empty
     * login is not an account, and counting it would lock every future
     * empty-login attempt out together.
     *
     * @param  ClientIp  $ip  Address the attempt comes from.
     * @param  string  $account  Account identifier, see {@see self::forAccount()}.
     * @return list<Subject>
     */
    public function forAttempt(ClientIp $ip, string $account): array
    {
        $subjects = [$this->forIp($ip)];

        if (trim($account) !== '') {
            $subjects[] = $this->forAccount($account);
        }

        return $subjects;
    }

    /**
     * The subject of an address, grouped as {@see ClientIp::bucket()} does.
     *
     * @param  ClientIp  $ip  Address.
     */
    public function forIp(ClientIp $ip): Subject
    {
        return $this->derive(SubjectScope::Ip, $ip->bucket());
    }

    /**
     * The subject of an account.
     *
     * The identifier is compared case-insensitively. Callers should resolve it to
     * something stable when the account exists — its numeric ID, say — so that
     * attempts by username and by email address land on the same counter.
     *
     * @param  string  $account  Account identifier.
     */
    public function forAccount(string $account): Subject
    {
        return $this->derive(SubjectScope::Account, strtolower(trim($account)));
    }

    /**
     * Computes the keyed digest.
     *
     * The scope is part of the message so that an address and a login spelling
     * the same string never share a counter.
     *
     * @param  SubjectScope  $scope  Scope of the subject.
     * @param  string  $value  Normalised value.
     */
    private function derive(SubjectScope $scope, string $value): Subject
    {
        return new Subject($scope, hash_hmac('sha256', $scope->label()."\0".$value, $this->secret, true));
    }
}
