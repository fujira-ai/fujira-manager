<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Logger
|--------------------------------------------------------------------------
*/
function cron_log(string $message, array $context = []): void
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

    @file_put_contents($logDir . '/cron_tomorrow_reminder.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Evening message builder
|--------------------------------------------------------------------------
*/
function build_tomorrow_message(array $todayTasks, array $tomorrowTasks): string
{
    $lines = [];

    // Section 1: today's incomplete tasks
    if (!empty($todayTasks)) {
        $withTime = [];
        $noTime   = [];
        foreach ($todayTasks as $t) {
            if (!empty($t['due_time'])) {
                $withTime[] = $t;
            } else {
                $noTime[] = $t;
            }
        }
        $sorted = array_merge($withTime, $noTime);
        $total  = count($sorted);

        $lines[] = '今日まだ終わっていないタスクがあります📌';
        $lines[] = '';
        foreach (array_slice($sorted, 0, 3) as $t) {
            $label   = (!empty($t['due_time'])) ? $t['due_time'] . ' ' . $t['title'] : $t['title'];
            $lines[] = '・' . $label;
        }
        if ($total > 3) {
            $lines[] = '';
            $lines[] = '他にも未完了タスクがあります。';
        }
    }

    // Section 2: tomorrow's tasks
    if (!empty($tomorrowTasks)) {
        if (!empty($lines)) {
            $lines[] = '';
        }
        $withTime = [];
        $noTime   = [];
        foreach ($tomorrowTasks as $t) {
            if (!empty($t['due_time'])) {
                $withTime[] = $t;
            } else {
                $noTime[] = $t;
            }
        }
        $sorted = array_merge($withTime, $noTime);
        $total  = count($sorted);

        $lines[] = '明日の予定はこちらです🌙';
        $lines[] = '';
        foreach (array_slice($sorted, 0, 3) as $t) {
            $label   = (!empty($t['due_time'])) ? $t['due_time'] . ' ' . $t['title'] : $t['title'];
            $lines[] = '・' . $label;
        }
        if ($total > 3) {
            $lines[] = '';
            $lines[] = '他にも明日の予定があります。';
        }
    }

    // Footer
    if (!empty($todayTasks)) {
        $lines[] = '';
        $lines[] = '「今日」で未完了タスクを確認できます。';
    }

    return implode("\n", $lines);
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
cron_log('tomorrow reminder start');

try {
    $db       = new \FujiraManager\Storage\Database($config['db']);
    $userRepo = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo = new \FujiraManager\Storage\TaskRepository($db);
    $line     = new \FujiraManager\Services\LineService($config);
} catch (\Throwable $e) {
    cron_log('tomorrow reminder init failed', ['error' => $e->getMessage()]);
    exit(1);
}

$users = $userRepo->getAllBriefEnabledUsers();
cron_log('tomorrow reminder user count', ['count' => count($users)]);

$tz       = new DateTimeZone('Asia/Tokyo');
$today    = (new DateTime('now', $tz))->format('Y-m-d');
$d        = new DateTime('now', $tz);
$d->modify('+1 day');
$tomorrow = $d->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $todayTasks    = $taskRepo->getTodayTasksByOwner($ownerId, $today);
        $tomorrowTasks = $taskRepo->getTomorrowTasksByOwner($ownerId, $tomorrow);

        if (empty($todayTasks) && empty($tomorrowTasks)) {
            cron_log('tomorrow reminder skipped (no tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message = build_tomorrow_message($todayTasks, $tomorrowTasks);
        $line->pushMessage($lineUserId, $message);
        cron_log('tomorrow reminder sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('tomorrow reminder failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
