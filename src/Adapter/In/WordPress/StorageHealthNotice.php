<?php

declare(strict_types=1);

namespace Pollora\Portcullis\Adapter\In\WordPress;

use Pollora\Portcullis\Adapter\Out\WordPress\WpdbSchema;
use Pollora\Portcullis\Port\Out\HookRegistrarPort;
use RuntimeException;

/**
 * Installs the attempts table from the administration, and says so when it cannot.
 *
 * The table is otherwise created on the first login attempt, which leaves two
 * gaps for sites run without a terminal: nothing happens until someone logs in,
 * and a failure — typically a database user that may not create tables — only
 * reaches the PHP error log, while logins keep working without protection.
 *
 * An administrator opening any administration screen closes both: the table is
 * installed on the spot if needed, and a failure is shown as a notice until it
 * is fixed.
 */
final class StorageHealthNotice
{
    /**
     * @param  WpdbSchema  $schema  Table the counters live in.
     * @param  HookRegistrarPort  $hooks  Hook system of the host.
     */
    public function __construct(
        private readonly WpdbSchema $schema,
        private readonly HookRegistrarPort $hooks,
    ) {}

    /**
     * Registers the hooks.
     */
    public function register(): void
    {
        $this->hooks->addAction('admin_init', [$this, 'install'], 1, 0);
        $this->hooks->addAction('admin_notices', [$this, 'render'], 10, 0);
        $this->hooks->addAction('network_admin_notices', [$this, 'render'], 10, 0);
    }

    /**
     * Installs the table when it is missing or outdated.
     *
     * Only for administrators: the check itself is free, but an installation is
     * not something a subscriber's profile page should trigger.
     *
     * @internal Hooked on `admin_init`; not part of the public API.
     */
    public function install(): void
    {
        if (! $this->isAdministrator() || $this->schema->isUpToDate()) {
            return;
        }

        try {
            $this->schema->ensure();
        } catch (RuntimeException) {
            // Already logged and remembered by the schema; render() shows it.
        }
    }

    /**
     * Shows the last installation error, if any.
     *
     * @internal Hooked on `admin_notices` and `network_admin_notices`; not part of the public API.
     */
    public function render(): void
    {
        if (! $this->isAdministrator()) {
            return;
        }

        $error = $this->schema->lastInstallError();

        if ($error === null) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>Portcullis</strong> — %s</p><p>%s</p><p>%s</p></div>',
            esc_html__('Brute-force protection is not active: the table failed login attempts are counted in could not be created.', 'portcullis'),
            esc_html($error),
            esc_html(sprintf(
                /* translators: %d: number of minutes */
                __('Grant the database user the CREATE privilege, then run `wp portcullis install` or wait %d minutes for the next automatic attempt.', 'portcullis'),
                (int) ceil(WpdbSchema::RETRY_DELAY / 60),
            )),
        );
    }

    /**
     * Whether the current user may administer the installation.
     */
    private function isAdministrator(): bool
    {
        return current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options');
    }
}
