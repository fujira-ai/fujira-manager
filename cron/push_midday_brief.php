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

    @file_put_contents($logDir . '/cron_midday_brief.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Midday message builder
|--------------------------------------------------------------------------
*/
function build_midday_message(array $todayTasks, array $tomorrowTasks, array $noneTasks): string
{
    // Group classification — within each group SQL already sorted (due_time ASC)
    $todayWithTime    = [];
    $todayNoTime      = [];
    $tomorrowWithTime = [];
    $tomorrowNoTime   = [];

    foreach ($todayTasks as $t) {
        if (!empty($t['due_time'])) {
            $todayWithTime[] = $t;
        } else {
            $todayNoTime[] = $t;
        }
    }
    foreach ($tomorrowTasks as $t) {
        if (!empty($t['due_time'])) {
            $tomorrowWithTime[] = $t;
        } else {
            $tomorrowNoTime[] = $t;
        }
    }

    // Priority order: today_with_time → today_no_time → tomorrow_with_time → tomorrow_no_time → no_due
    $pending = array_merge($todayWithTime, $todayNoTime, $tomorrowWithTime, $tomorrowNoTime, $noneTasks);
    $total   = count($pending);

    $sections = [
        'お昼の確認です。',
        '',
        '未完了のタスクが' . $total . '件あります。',
        '',
    ];

    if ($total <= 3) {
        foreach ($pending as $t) {
            $label = (!empty($t['due_time'])) ? $t['due_time'] . ' ' . $t['title'] : $t['title'];
            $sections[] = '・' . $label;
        }
    } else {
        $top       = array_slice($pending, 0, 3);
        $remaining = $total - 3;
        $sections[] = '優先タスク：';
        foreach ($top as $t) {
            $label = (!empty($t['due_time'])) ? $t['due_time'] . ' ' . $t['title'] : $t['title'];
            $sections[] = '・' . $label;
        }
        $sections[] = '';
        $sections[] = '残り' . $remaining . '件';
    }
    $sections[] = '';
    $sections[] = 'この中から1つ進めましょう。';

    return implode("\n", $sections);
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
cron_log('midday brief start');

try {
    $db       = new \FujiraManager\Storage\Database($config['db']);
    $userRepo = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo = new \FujiraManager\Storage\TaskRepository($db);
    $line     = new \FujiraManager\Services\LineService($config);
} catch (\Throwable $e) {
    cron_log('midday brief init failed', ['error' => $e->getMessage()]);
    exit(1);
}

$users = $userRepo->getAllBriefEnabledUsers();
cron_log('midday brief user count', ['count' => count($users)]);

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
        $noneTasks     = $taskRepo->getNoDueDateTasksByOwner($ownerId);

        if (empty($todayTasks) && empty($tomorrowTasks) && empty($noneTasks)) {
            cron_log('midday brief skipped (no tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message = build_midday_message($todayTasks, $tomorrowTasks, $noneTasks);
        $line->pushMessage($lineUserId, $message);
        cron_log('midday brief sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('midday brief failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
