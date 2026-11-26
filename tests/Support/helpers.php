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

/**
 * A WordPress global, checked to be what the test expects.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T
 */
function globalInstance(string $name, string $class): object
{
    $value = $GLOBALS[$name] ?? null;

    return $value instanceof $class ? $value : throw new RuntimeException("\${$name} is not an instance of {$class}.");
}

/**
 * The refusal a run of the authentication chain answered with.
 */
function errorOf(mixed $result): WP_Error
{
    return $result instanceof WP_Error ? $result : throw new RuntimeException('Expected a WP_Error, got '.get_debug_type($result).'.');
}
