<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Operational figures read from real sources only (failed_jobs, the application log, the storage directories).
 * Anything without a source returns null so widgets can show an unconfigured state instead of invented numbers.
 */
class SystemMonitoringService
{
    private const LOG_PATTERN = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [\w.-]+\.(WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/';

    /**
     * @return array{total: int, last_day: int}|null
     */
    public function failedJobs(): ?array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        return Cache::remember('dashboard:system:failed-jobs', 60, fn (): array => [
            'total' => DB::table('failed_jobs')->count(),
            'last_day' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
        ]);
    }

    /**
     * @return array{errors: int, warnings: int, recent: list<array{at: string, level: string, message: string}>}|null
     */
    public function logSummary(): ?array
    {
        return Cache::remember('dashboard:system:log', 60, function (): ?array {
            $entries = $this->readLogEntries();

            if ($entries === null) {
                return null;
            }

            $since = now()->subDay();
            $recent = array_filter($entries, fn (array $entry): bool => Carbon::parse($entry['at'])->gte($since));
            $errors = array_filter($recent, fn (array $entry): bool => $entry['level'] !== 'WARNING');

            return [
                'errors' => count($errors),
                'warnings' => count($recent) - count($errors),
                'recent' => array_slice(array_reverse(array_values($errors)), 0, 5),
            ];
        });
    }

    /**
     * @return array{used: int, free: int|null, paths: array<string, int>}
     */
    public function storageUsage(): array
    {
        return Cache::remember('dashboard:system:storage', 600, function (): array {
            $paths = collect(config('dashboard.storage_paths'))->map(fn (string $path): int => $this->directorySize($path));
            $free = @disk_free_space(storage_path());

            return ['used' => $paths->sum(), 'free' => $free === false ? null : (int) $free, 'paths' => $paths->all()];
        });
    }

    public function ga4Configured(): bool
    {
        return filled(config('dashboard.ga4.property_id'));
    }

    /**
     * @return list<array{at: string, level: string, message: string}>|null
     */
    private function readLogEntries(): ?array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $file = $files[0] ?? null;

        if ($file === null || ! is_readable($file)) {
            return null;
        }

        try {
            $bytes = (int) config('dashboard.log_tail_bytes');
            $size = filesize($file);
            $handle = fopen($file, 'rb');
            fseek($handle, max(0, $size - $bytes));
            $content = stream_get_contents($handle);
            fclose($handle);
        } catch (Throwable) {
            return null;
        }

        $entries = [];

        foreach (preg_split('/\R/', (string) $content) as $line) {
            if (preg_match(self::LOG_PATTERN, $line, $match) === 1) {
                $entries[] = ['at' => $match[1], 'level' => $match[2], 'message' => mb_strimwidth($match[3], 0, 200, '…')];
            }
        }

        return $entries;
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $size = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->isFile() ? $file->getSize() : 0;
        }

        return $size;
    }
}
