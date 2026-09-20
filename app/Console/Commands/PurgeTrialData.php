<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Empties the movement recorded while trying the system out, and leaves the setup behind it standing. */
class PurgeTrialData extends Command
{
    protected $signature = 'data:purge-trial
        {--keep= : Extra tables to spare, comma separated}
        {--files : Delete the uploads attached to the purged rows too}
        {--dry-run : Show what would go without deleting anything}
        {--force : Run without asking for confirmation}';

    protected $description = 'Purge the trial data and hand the system over clean, keeping the halls, chalets and their setup';

    /** Setup entered to be used, not to be tried — it survives the handover. */
    private const KEEP = [
        'settings', 'roles', 'users', 'unit_user', 'backups',
        'facilities', 'facility_unit_section', 'departments',
        'units', 'unit_sections', 'unit_prices', 'seasons',
        'packages', 'package_items', 'addons',
        'items', 'item_categories', 'item_groups', 'item_group_items', 'item_components', 'measure_units',
        'accounts', 'cost_centers', 'treasuries', 'payment_methods', 'expense_categories',
        // أين يُرحَّل كل إيراد — إعدادٌ يُضبط مرة ويبقى بعد تسليم النظام.
        'revenue_accounts',
        'cities', 'event_types', 'employee_groups',
        'contract_templates', 'notification_templates',
    ];

    /** Movement — every row of it was entered to see what the screen would do. */
    private const PURGE = [
        'contracts', 'quotations', 'quotation_items',
        'booking_payments', 'booking_addon', 'booking_section', 'bookings',
        'sales', 'sale_items', 'purchases', 'purchase_items', 'stock_movements',
        'vouchers', 'voucher_attachments', 'expenses', 'journal_entries', 'journal_lines',
        'fixed_assets', 'asset_depreciation_entries',
        'bank_statement_imports', 'bank_statement_lines',
        'employees', 'attendances', 'leaves', 'advances', 'allowances', 'bonuses', 'deductions',
        'payrolls', 'payroll_lines',
        'clients', 'suppliers', 'tickets', 'whatsapp_messages', 'audit_logs',
    ];

    /** Laravel's own tables — outside the split. */
    private const FRAMEWORK = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /** A table kept by --keep drags its parents along, or its rows end up pointing at nothing. */
    private const PARENTS = [
        'contracts' => ['bookings'],
        'bookings' => ['clients'],
        'booking_payments' => ['bookings'],
        'booking_addon' => ['bookings'],
        'booking_section' => ['bookings'],
        'quotations' => ['clients'],
        'quotation_items' => ['quotations'],
        'sales' => ['clients'],
        'sale_items' => ['sales'],
        'purchases' => ['suppliers'],
        'purchase_items' => ['purchases'],
        'vouchers' => ['clients', 'journal_entries'],
        'voucher_attachments' => ['vouchers'],
        'expenses' => ['journal_entries'],
        'journal_lines' => ['journal_entries'],
        'payrolls' => ['employees'],
        'payroll_lines' => ['payrolls'],
        'attendances' => ['employees'],
        'leaves' => ['employees'],
        'advances' => ['employees'],
        'allowances' => ['employees'],
        'bonuses' => ['employees'],
        'deductions' => ['employees'],
        'asset_depreciation_entries' => ['fixed_assets'],
        'bank_statement_lines' => ['bank_statement_imports'],
    ];

    /** Uploads of a purged row, on the public disk. Generated PDFs are not here — pdf:prune ages those out. */
    private const FILE_COLUMNS = [
        'booking_payments' => 'attachment_path',
        'expenses' => 'attachment_path',
        'tickets' => 'attachment_path',
        'voucher_attachments' => 'path',
    ];

    public function handle(): int
    {
        if ($unclassified = $this->unclassifiedTables()) {
            $this->error('Unclassified tables: '.implode(', ', $unclassified));
            $this->line('Add each one to KEEP or PURGE in '.static::class.', then run again.');

            return self::FAILURE;
        }

        $asked = $this->requestedKeeps();

        if ($stray = array_values(array_diff($asked, self::PURGE, self::KEEP))) {
            $this->error('No such tables: '.implode(', ', $stray));

            return self::FAILURE;
        }

        $keep = $this->expandKeeps($asked);

        if ($pulled = array_values(array_diff($keep, self::KEEP, $asked))) {
            $this->warn('Also spared, as what you asked for depends on them: '.implode(', ', $pulled));
        }

        $targets = array_values(array_diff(self::PURGE, $keep));
        $counts = array_filter($this->rowCounts($targets));
        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('No trial data — the system is already clean.');

            return self::SUCCESS;
        }

        $this->report($counts, $total, count($keep));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        $this->warnOnStaleBackup();

        if (! $this->option('force') && ! $this->confirm('Delete all of the above permanently?', false)) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        $files = $this->option('files') ? $this->deleteAttachments($targets) : 0;

        Schema::withoutForeignKeyConstraints(function () use ($targets) {
            foreach ($targets as $table) {
                DB::table($table)->truncate();
            }
        });

        $this->newLine();
        $this->info('Deleted '.number_format($total).' rows'.($files > 0 ? " and {$files} attached files" : '').'.');
        $this->line('Booking, voucher and invoice numbers restart, as they are derived from the last recorded number.');

        return self::SUCCESS;
    }

    /** A table nobody classified would be silently spared, so the command stops instead. */
    private function unclassifiedTables(): array
    {
        $known = array_merge(self::KEEP, self::PURGE, self::FRAMEWORK);

        // Named explicitly: with no schema, MySQL lists every database on the server.
        $existing = Schema::getTableListing(Schema::getCurrentSchemaName(), schemaQualified: false);

        return array_values(array_diff($existing, $known));
    }

    /** @return string[] */
    private function requestedKeeps(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('keep'))
        )));
    }

    /** @return string[] */
    private function expandKeeps(array $asked): array
    {
        $keep = self::KEEP;
        $queue = $asked;

        while ($queue) {
            $table = array_shift($queue);

            if (in_array($table, $keep, true)) {
                continue;
            }

            $keep[] = $table;
            $queue = array_merge($queue, self::PARENTS[$table] ?? []);
        }

        return $keep;
    }

    /** @return array<string, int> */
    private function rowCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function report(array $counts, int $total, int $kept): void
    {
        $this->newLine();
        $this->line('Database: '.DB::connection()->getDatabaseName());

        $this->table(
            ['Table', 'Rows to delete'],
            array_map(fn (string $table, int $rows) => [$table, number_format($rows)], array_keys($counts), $counts)
        );

        $this->line('Total: '.number_format($total).' rows across '.count($counts).' tables.');
        $this->line("Untouched: {$kept} tables — units with their sections, prices and seasons; packages, add-ons and items; the chart of accounts and treasuries; contract and notification templates; users, roles and settings.");
    }

    /** A wipe with no fresh backup behind it has no way back. */
    private function warnOnStaleBackup(): void
    {
        $latest = DB::table('backups')->where('status', 'completed')->max('created_at');

        if ($latest === null || Carbon::parse($latest)->lt(now()->subDay())) {
            $this->warn('No completed backup in the last 24 hours — run php artisan backup:run first.');
        }
    }

    private function deleteAttachments(array $targets): int
    {
        $disk = Storage::disk('public');
        $deleted = 0;

        foreach (self::FILE_COLUMNS as $table => $column) {
            if (! in_array($table, $targets, true)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $path) {
                if ($path !== '' && $disk->delete($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
