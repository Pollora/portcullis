<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Port\Out\AttemptStorePort;
use Pollora\Portcullis\Port\Out\ClockPort;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use RuntimeException;

/**
 * Deletes expired failure counters once a day, through WP-Cron.
 *
 * Counters are small and the table is keyed by subject, so letting them pile up
 * would cost disk space rather than speed. The purge still matters after an
 * attack from many addresses, and it runs in bounded batches so that a large
 * backlog never turns into one long-running `DELETE`.
 */
final class AttemptPurgeScheduler
{
    /**
     * WP-Cron event name.
     */
    public const EVENT = 'portcullis_purge_attempts';

    /**
     * Counters deleted per batch.
     */
    public const BATCH_SIZE = 1000;

    /**
     * Batches per run, after which the rest waits for the next day.
     */
    public const MAX_BATCHES = 50;

    /**
     * @param  AttemptStorePort  $store  Where counters are kept.
     * @param  ClockPort  $clock  Current time.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(
        private readonly AttemptStorePort $store,
        private readonly ClockPort $clock,
        private readonly HookRegistrarPort $hooks,
    ) {}

    /**
     * Registers the event and its handler.
     */
    public function register(): void
    {
        $this->hooks->addAction(self::EVENT, [$this, 'purge'], 10, 0);
        $this->hooks->addAction('init', [$this, 'schedule'], 10, 0);
    }

    /**
     * Schedules the daily event when it is not scheduled yet.
     *
     * @internal Hooked on `init`; not part of the public API.
     */
    public function schedule(): void
    {
        if (wp_next_scheduled(self::EVENT) === false) {
            wp_schedule_event($this->clock->now() + HOUR_IN_SECONDS, 'daily', self::EVENT);
        }
    }

    /**
     * Deletes expired counters.
     *
     * @internal Hooked on the cron event; also called by `wp portcullis purge`.
     *
     * @return int Number of counters deleted.
     */
    public function purge(): int
    {
        $deleted = 0;

        try {
            for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
                $count = $this->store->purgeExpired($this->clock->now(), self::BATCH_SIZE);
                $deleted += $count;

                if ($count < self::BATCH_SIZE) {
                    break;
                }
            }
        } catch (RuntimeException $exception) {
            error_log('[portcullis] purge failed: '.$exception->getMessage());
        }

        return $deleted;
    }
}
