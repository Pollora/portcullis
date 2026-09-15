<?php

declare(strict_types=1);

namespace Pollora\HiddenLogin\Port\Out;

use Pollora\Portcullis\Port\Out\FeatureTogglePort;

/*
 * 1.x name of {@see \Pollora\Portcullis\Port\Out\FeatureTogglePort}.
 *
 * Loaded by Composer only when a host still references the old name — a custom
 * adapter written against pollora/hidden-login — and aliased rather than copied,
 * so that such an adapter is accepted wherever the new interface is expected.
 *
 * @deprecated 2.0.0 Implement \Pollora\Portcullis\Port\Out\FeatureTogglePort instead.
 */
class_alias(FeatureTogglePort::class, __NAMESPACE__.'\FeatureTogglePort');
