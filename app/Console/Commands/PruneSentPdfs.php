<?php

namespace App\Console\Commands;

use App\Services\BondPdf;
use App\Services\ContractPdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the PDFs generated for WhatsApp once the gateway has had time to fetch them.
 */
class PruneSentPdfs extends Command
{
    protected $signature = 'pdf:prune
        {--hours=24 : How old a file must be before it is deleted}
        {--dry-run : List what would go without deleting anything}';

    protected $description = 'Delete the generated contract and voucher PDFs older than the given age';

    /** Generated for sending, not for keeping — every file here is reproducible. */
    private const DIRECTORIES = [ContractPdf::DIRECTORY, BondPdf::DIRECTORY];

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours)->getTimestamp();
        $disk = Storage::disk('public');

        $deleted = 0;
        $kept = 0;
        $bytes = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach ($disk->files($directory) as $file) {
                // A young file may still be waiting for the gateway to fetch it.
                if ($disk->lastModified($file) > $cutoff) {
                    $kept++;

                    continue;
                }

                $size = $disk->size($file);

                if (! $this->option('dry-run')) {
                    $disk->delete($file);
                }

                $deleted++;
                $bytes += $size;
            }
        }

        $summary = ($this->option('dry-run') ? 'Would delete ' : 'Deleted ')
            .$deleted.' files ('.$this->humanSize($bytes).") older than {$hours}h, kept {$kept} newer.";

        $this->info($summary);

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
