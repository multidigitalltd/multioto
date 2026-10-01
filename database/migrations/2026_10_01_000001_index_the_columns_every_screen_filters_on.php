<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes this schema never had, and the reason the panel got slower every
 * month rather than all at once.
 *
 * Two gaps, both of them invisible until there is enough data:
 *
 * **1. Not one foreign key is indexed.** `foreignId()->constrained()` creates the
 * constraint, and on MySQL the engine quietly adds an index behind it — so this
 * reads as perfectly normal Laravel. PostgreSQL does not. Every
 * `$customer->subscriptions`, `$ticket->messages`, `$customer->invoices` has
 * therefore been a sequential scan of the whole child table, and so has every
 * delete of a parent row, which must check each child for references.
 *
 * **2. The dates the dashboard aggregates on are not indexed.** `charges` carries
 * an index on `status`, which a planner will ignore: most rows are 'succeeded', so
 * it is cheaper to read the table. The actual filter is `status + charged_at`, and
 * `charged_at` appears in no index at all — so the two revenue sums on the home
 * screen scan every charge ever made, on every load, for every user.
 *
 * Both get monotonically worse with use, which is exactly how this was reported:
 * not "it broke" but "it has become very slow".
 *
 * Composite order follows the queries, not tidiness: equality column first, range
 * column second, which is the only order a b-tree can use for both.
 *
 * `IF NOT EXISTS` by hand rather than a bare `index()`: a few of these may already
 * exist on a database that has been patched directly, and a migration that dies
 * on the first duplicate leaves the rest unapplied.
 */
return new class extends Migration
{
    /**
     * table => [name => columns]
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INDEXES = [
        // The dashboard's hottest table. Both of these run on every panel load.
        'charges' => [
            // "נגבה החודש" and its siblings: sum over status + a date range.
            'charges_status_charged_at_index' => ['status', 'charged_at'],
            // Payment demands — the widget, its summary row, and the sidebar badge.
            'charges_demand_sent_at_index' => ['demand_sent_at'],
            // Overdue demands.
            'charges_due_at_index' => ['due_at'],
        ],

        // Read on every entitlement check the site agent makes, and on every
        // customer page.
        'subscriptions' => [
            'subscriptions_customer_id_index' => ['customer_id'],
            'subscriptions_site_id_index' => ['site_id'],
            'subscriptions_plan_id_index' => ['plan_id'],
            'subscriptions_token_id_index' => ['token_id'],
        ],

        'tickets' => [
            'tickets_customer_id_index' => ['customer_id'],
            'tickets_created_at_index' => ['created_at'],
            'tickets_assigned_to_index' => ['assigned_to'],
        ],

        // The thread of one conversation, in order. Opened dozens of times a day
        // and read by every team alert, and until now a scan of every message in
        // the system each time.
        'ticket_messages' => [
            'ticket_messages_ticket_id_id_index' => ['ticket_id', 'id'],
        ],

        'sites' => [
            'sites_customer_id_index' => ['customer_id'],
        ],

        'invoices' => [
            'invoices_customer_id_index' => ['customer_id'],
        ],

        // Deleted by date every night. Without this the nightly prune scans the
        // whole table — the one job whose entire purpose is to keep it small.
        'webhook_events' => [
            'webhook_events_created_at_index' => ['created_at'],
        ],

        'notification_logs' => [
            'notification_logs_created_at_index' => ['created_at'],
        ],

        // The in-panel bell counts one user's unread notifications on every page
        // render. The framework's own index stops at the notifiable, so the
        // unread filter was a scan of that user's entire history.
        'notifications' => [
            'notifications_notifiable_read_at_index' => ['notifiable_type', 'notifiable_id', 'read_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                // A column may not exist on an older database than this file.
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue 2;
                    }
                }

                $this->createIndex($table, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    /** @param  list<string>  $columns */
    private function createIndex(string $table, string $name, array $columns): void
    {
        // Postgres and SQLite both understand IF NOT EXISTS here, which keeps
        // this safe to run on a database somebody already added one of these to
        // by hand. Quoted so a column like "status" is never read as a keyword.
        $quoted = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns));

        Schema::getConnection()->statement(
            sprintf('create index if not exists %s on "%s" (%s)', $name, $table, $quoted)
        );
    }

    private function dropIndex(string $table, string $name): void
    {
        // Same shape in Postgres and SQLite; the table name is only needed by
        // MySQL, which this project does not run.
        Schema::getConnection()->statement(sprintf('drop index if exists %s', $name));
    }
};
