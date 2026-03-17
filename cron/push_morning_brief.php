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
    if (empty($todayTasks) && empty($noneTasks)) {
        return "【今日のブリーフ】\n\n今日やるべきタスクはありません";
    }

    $sections = ['【今日のブリーフ】'];
    $counter  = 1;

    if (!empty($todayTasks)) {
        $sections[] = "\n■ 今日の期限";
        foreach ($todayTasks as $t) {
            $sections[] = $counter++ . '. ' . $t['title'];
        }
    }

    if (!empty($noneTasks)) {
        $sections[] = "\n■ その他（未期限）";
        foreach ($noneTasks as $t) {
            $sections[] = $counter++ . '. ' . $t['title'];
        }
    }

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

$users = $userRepo->getAllUsers();
cron_log('morning brief user count', ['count' => count($users)]);

$today = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
        $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);
        $message    = build_brief_message($todayTasks, $noneTasks);

        $line->pushMessage($lineUserId, $message);
        cron_log('morning brief sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('morning brief failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
