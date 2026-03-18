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
| Tomorrow message builder
|--------------------------------------------------------------------------
*/
function build_tomorrow_message(array $tomorrowTasks): string
{
    $sections = ['明日の予定があります。', ''];
    foreach ($tomorrowTasks as $t) {
        if (!empty($t['due_time'])) {
            $sections[] = '・' . $t['title'] . '（明日 ' . $t['due_time'] . '）';
        } else {
            $sections[] = '・' . $t['title'] . '（明日）';
        }
    }
    $sections[] = '';
    $sections[] = '必要な準備があれば今日のうちにどうぞ。';

    return implode("\n", $sections);
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

$d = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$d->modify('+1 day');
$tomorrow = $d->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $tomorrowTasks = $taskRepo->getTomorrowTasksByOwner($ownerId, $tomorrow);

        if (empty($tomorrowTasks)) {
            cron_log('tomorrow reminder skipped (no tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message = build_tomorrow_message($tomorrowTasks);
        $line->pushMessage($lineUserId, $message);
        cron_log('tomorrow reminder sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('tomorrow reminder failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
