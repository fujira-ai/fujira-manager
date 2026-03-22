<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Logger
|--------------------------------------------------------------------------
*/
function backup_log(string $message, array $context = []): void
{
    global $config;

    $logDir = $config['paths']['log_dir'] ?? (__DIR__ . '/../logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    @file_put_contents($logDir . '/cron_log_backup.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Helper: recursively delete a directory
|--------------------------------------------------------------------------
*/
function rmdir_recursive(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    $items = scandir($dir);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rmdir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
backup_log('start');

$logDir    = rtrim((string) ($config['paths']['log_dir'] ?? (__DIR__ . '/../logs')), '/');
$today     = date('Y-m-d');
$backupDir = $logDir . '/backup/' . $today;

// Create today's backup directory
if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
    backup_log('failed to create backup dir', ['dir' => $backupDir]);
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Compress and rotate each .log file
|--------------------------------------------------------------------------
*/
$logFiles = glob($logDir . '/*.log') ?: [];

foreach ($logFiles as $logFile) {
    $filename = basename($logFile);

    // Exclude the backup script's own log to avoid self-truncation mid-run
    if ($filename === 'cron_log_backup.log') {
        backup_log('skipped (excluded)', ['file' => $filename]);
        continue;
    }

    $destFile = $backupDir . '/' . $filename . '.gz';

    try {
        $contents = file_get_contents($logFile);
        if ($contents === false) {
            backup_log('skipped (unreadable)', ['file' => $filename]);
            continue;
        }

        if ($contents === '') {
            backup_log('skipped (empty)', ['file' => $filename]);
            continue;
        }

        $compressed = gzencode($contents, 6);
        if ($compressed === false) {
            backup_log('failed to compress', ['file' => $filename]);
            continue;
        }

        if (file_put_contents($destFile, $compressed) === false) {
            backup_log('failed to write backup', ['file' => $filename]);
            continue;
        }

        // Truncate original: keep the file so writers can continue appending
        file_put_contents($logFile, '');

        backup_log('backed up', ['file' => $filename, 'dest' => $destFile]);
    } catch (\Throwable $e) {
        backup_log('error', ['file' => $filename, 'error' => $e->getMessage()]);
    }
}

/*
|--------------------------------------------------------------------------
| Delete backup directories older than 30 days
|--------------------------------------------------------------------------
*/
$retentionDays = 30;
$backupRoot    = $logDir . '/backup';
$cutoffDate    = date('Y-m-d', strtotime('-' . $retentionDays . ' days'));

if (is_dir($backupRoot)) {
    $dirs = scandir($backupRoot) ?: [];
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') {
            continue;
        }
        // Only touch YYYY-MM-DD named directories
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dir)) {
            continue;
        }
        if ($dir < $cutoffDate) {
            $dirPath = $backupRoot . '/' . $dir;
            if (rmdir_recursive($dirPath)) {
                backup_log('deleted old backup dir', ['dir' => $dir]);
            } else {
                backup_log('failed to delete old backup dir', ['dir' => $dir]);
            }
        }
    }
}

backup_log('done');
