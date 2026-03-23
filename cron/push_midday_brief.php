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
    // Priority: today with time → today no time → no due date (lower priority)
    $withTime = [];
    $noTime   = [];
    foreach ($todayTasks as $t) {
        if (!empty($t['due_time'])) {
            $withTime[] = $t;
        } else {
            $noTime[] = $t;
        }
    }
    $pending = array_merge($withTime, $noTime, $noneTasks);
    $total   = count($pending);

    $lines = [
        'まだ終わっていないタスクがあります📌',
        '',
    ];

    foreach (array_slice($pending, 0, 3) as $t) {
        $label   = (!empty($t['due_time'])) ? $t['due_time'] . ' ' . $t['title'] : $t['title'];
        $lines[] = '・' . $label;
    }

    if ($total > 3) {
        $lines[] = '';
        $lines[] = '他にも未完了タスクがあります。';
    }

    $lines[] = '';
    $lines[] = '「今日」で一覧を確認できます。';

    return implode("\n", $lines);
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

$tz    = new DateTimeZone('Asia/Tokyo');
$today = (new DateTime('now', $tz))->format('Y-m-d');

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
        $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);

        if (empty($todayTasks)) {
            cron_log('midday brief skipped (no today tasks)', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
            continue;
        }

        $message    = build_midday_message($todayTasks, $noneTasks);
        $quickReply = null;
        if (!empty($todayTasks)) {
            $qrItems   = [];
            $qrItems[] = ['type' => 'action', 'action' => ['type' => 'message', 'label' => '完了1', 'text' => '完了1']];
            if (count($todayTasks) >= 2) {
                $qrItems[] = ['type' => 'action', 'action' => ['type' => 'message', 'label' => '完了2', 'text' => '完了2']];
            }
            $qrItems[]  = ['type' => 'action', 'action' => ['type' => 'message', 'label' => '今日', 'text' => '今日']];
            $quickReply = ['items' => $qrItems];
        }
        $line->pushMessage($lineUserId, $message, $quickReply);
        cron_log('midday brief sent', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId]);
    } catch (\Throwable $e) {
        cron_log('midday brief failed', ['owner_id' => $ownerId, 'line_user_id' => $lineUserId, 'error' => $e->getMessage()]);
    }
}
