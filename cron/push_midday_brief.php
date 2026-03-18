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
function build_midday_message(array $todayTasks, array $noneTasks): string
{
    // Priority: today with due_time ASC → today without due_time → no-date
    // $todayTasks is already sorted by getTodayTasksByOwner (due_time あり先・ASC)
    $pending = array_merge($todayTasks, $noneTasks);
    $total   = count($pending);

    $sections = [
        'お昼の確認です。',
        '',
        '未完了のタスクが' . $total . '件あります。',
        '',
    ];

    if ($total <= 3) {
        foreach ($pending as $t) {
            $sections[] = '・' . $t['title'];
        }
        $sections[] = '';
        $sections[] = '今日の分を1件だけでも進めましょう。';
    } else {
        $top       = array_slice($pending, 0, 3);
        $remaining = $total - 3;
        $sections[] = '優先タスク：';
        foreach ($top as $t) {
            $sections[] = '・' . $t['title'];
        }
        $sections[] = '';
        $sections[] = '残り' . $remaining . '件';
    }

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

$today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
        $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);

        if (empty($todayTasks) && empty($noneTasks)) {
            cron_log('midday brief skipped (no tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message = build_midday_message($todayTasks, $noneTasks);
        $line->pushMessage($lineUserId, $message);
        cron_log('midday brief sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('midday brief failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
