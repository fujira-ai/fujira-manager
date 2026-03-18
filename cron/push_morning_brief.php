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

    @file_put_contents($logDir . '/cron_morning_brief.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Brief message builder
|--------------------------------------------------------------------------
*/
function build_brief_message(array $todayTasks, array $noneTasks): string
{
    if (!empty($todayTasks)) {
        $count    = count($todayTasks);
        $sections = [
            'おはようございます。',
            '',
            '今日のタスクは' . $count . '件です。',
            '',
            '■ 今日',
        ];
        foreach ($todayTasks as $i => $t) {
            $prefix = (!empty($t['due_time'])) ? $t['due_time'] . ' ' : '';
            $sections[] = ($i + 1) . '. ' . $prefix . $t['title'];
        }
        $sections[] = '';
        $sections[] = 'まずは「1」から進めてください。';
        return implode("\n", $sections);
    }

    // today tasks empty, only non-dated tasks
    $count    = count($noneTasks);
    $sections = [
        'おはようございます。',
        '',
        '今日締め切りのタスクはありません。',
        '期限なしのタスクが' . $count . '件あります。',
        '',
    ];
    foreach ($noneTasks as $i => $t) {
        $sections[] = ($i + 1) . '. ' . $t['title'];
    }
    $sections[] = '';
    $sections[] = '今日も1件だけでも進めましょう。';
    return implode("\n", $sections);
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
cron_log('morning brief start');

try {
    $db       = new \FujiraManager\Storage\Database($config['db']);
    $userRepo = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo = new \FujiraManager\Storage\TaskRepository($db);
    $line     = new \FujiraManager\Services\LineService($config);
} catch (\Throwable $e) {
    cron_log('morning brief init failed', ['error' => $e->getMessage()]);
    exit(1);
}

$users = $userRepo->getAllBriefEnabledUsers();
cron_log('morning brief user count', ['count' => count($users)]);

$today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
        $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);

        if (empty($todayTasks) && empty($noneTasks)) {
            cron_log('morning brief skipped (no tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message = build_brief_message($todayTasks, $noneTasks);
        $line->pushMessage($lineUserId, $message);
        cron_log('morning brief sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('morning brief failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
