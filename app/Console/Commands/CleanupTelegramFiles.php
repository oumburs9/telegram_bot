<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupTelegramFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:cleanup {--hours= : Override cleanup age in hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old Telegram processing files from local storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hoursOption = $this->option('hours');
        $cleanupHours = (int) config('telegram.cleanup_after_hours', 72);

        if ($hoursOption !== null && $hoursOption !== '') {
            if (! is_numeric($hoursOption) || (int) $hoursOption < 1) {
                $this->error('The --hours option must be a positive integer.');

                return self::FAILURE;
            }

            $cleanupHours = (int) $hoursOption;
        }

        $cutoffTimestamp = now()->subHours($cleanupHours)->getTimestamp();
        $baseDirectory = 'telegram/jobs';
        $disk = Storage::disk('local');

        if (! $disk->exists($baseDirectory)) {
            $this->info('No Telegram job directory found. Nothing to clean.');

            return self::SUCCESS;
        }

        $deletedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($disk->directories($baseDirectory) as $directory) {
            $absolutePath = storage_path('app/private/'.$directory);

            if (! File::isDirectory($absolutePath)) {
                $skippedCount++;

                continue;
            }

            try {
                $lastModified = File::lastModified($absolutePath);

                if ($lastModified > $cutoffTimestamp) {
                    $skippedCount++;

                    continue;
                }

                if ($disk->deleteDirectory($directory)) {
                    $deletedCount++;

                    continue;
                }

                $failedCount++;
            } catch (\Throwable $throwable) {
                $failedCount++;
            }
        }

        Log::info('telegram.cleanup_finished', [
            'hours' => $cleanupHours,
            'deleted' => $deletedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
        ]);

        $this->info("Cleanup completed. Deleted: {$deletedCount}, Skipped: {$skippedCount}, Failed: {$failedCount}.");

        return self::SUCCESS;
    }
}
