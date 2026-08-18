<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * أخذ نسخة احتياطية (§18) — يدويًا من الطرفية أو ليليًا بالجدولة.
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run {--trigger=schedule : مصدر الطلب (schedule أو manual)}';

    protected $description = 'أخذ نسخة احتياطية من قاعدة البيانات وحذف ما زاد عن مدة الاحتفاظ';

    public function handle(BackupService $backups): int
    {
        $this->info('جارٍ أخذ النسخة الاحتياطية…');

        $backup = $backups->run((string) $this->option('trigger'));

        if (! $backup->isCompleted()) {
            $this->error("فشلت النسخة: {$backup->error}");

            return self::FAILURE;
        }

        $this->info("تمت النسخة {$backup->filename} ({$backup->sizeLabel()}) بطريقة {$backup->method}.");

        return self::SUCCESS;
    }
}
