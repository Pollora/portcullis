<?php

declare(strict_types=1);

use Pollora\Portcullis\Domain\Model\ClientIp;
use Pollora\Portcullis\Domain\Model\ScopePolicy;
use Pollora\Portcullis\Domain\Model\SubjectScope;
use Pollora\Portcullis\Domain\Model\ThrottlePolicy;

/*
 * Helpers shared by the tests: they turn a "or null" answer of the package into
 * the value a test relies on, and fail with a message when it is not there.
 */

function clientIp(string $value): ClientIp
{
    return ClientIp::tryFromString($value) ?? throw new RuntimeException("Not an IP address: {$value}");
}

function scopePolicy(ThrottlePolicy $policy, SubjectScope $scope): ScopePolicy
{
    return $policy->forScope($scope) ?? throw new RuntimeException("No policy for the scope {$scope->label()}.");
}
