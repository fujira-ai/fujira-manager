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

    @file_put_contents($logDir . '/cron_pre_alert.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| due_time parser — returns minutes since midnight, or null if unparseable
| Supported: "13:30", "9:05", "13時", "13時半", "9時"
|--------------------------------------------------------------------------
*/
function parse_due_time_minutes(string $dueTime): ?int
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $dueTime, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    if (preg_match('/^(\d{1,2})時半$/u', $dueTime, $m)) {
        return (int) $m[1] * 60 + 30;
    }
    if (preg_match('/^(\d{1,2})時$/u', $dueTime, $m)) {
        return (int) $m[1] * 60;
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/
cron_log('pre alert cron started');

try {
    $db            = new \FujiraManager\Storage\Database($config['db']);
    $userRepo      = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo      = new \FujiraManager\Storage\TaskRepository($db);
    $convStateRepo = new \FujiraManager\Storage\ConvStateRepository($db);
    $line          = new \FujiraManager\Services\LineService($config);
} catch (\Throwable $e) {
    cron_log('pre alert init failed', ['error' => $e->getMessage()]);
    exit(1);
}

$users = $userRepo->getAllUsers();
cron_log('pre alert user scan', ['total_users' => count($users)]);

$tz         = new DateTimeZone('Asia/Tokyo');
$now        = new DateTime('now', $tz);
$today      = $now->format('Y-m-d');
$nowMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');

$sentCount = 0;

foreach ($users as $user) {
    $ownerId    = (int) $user['id'];
    $lineUserId = (string) $user['line_user_id'];

    try {
        // Load per-day sent list from conv_state
        $state     = $convStateRepo->getState($ownerId);
        $alertDate = $state['pre_alert_sent_date'] ?? null;

        // Reset when date has changed
        $alertSentIds = ($alertDate === $today)
            ? array_map('intval', (array) ($state['pre_alert_sent_task_ids'] ?? []))
            : [];

        // Get today's open tasks that have a due_time
        $tasks = $taskRepo->getTodayAlertTasksByOwner($ownerId, $today);
        if (empty($tasks)) {
            continue;
        }

        foreach ($tasks as $task) {
            $taskId  = (int) $task['id'];
            $dueTime = (string) $task['due_time'];

            // Skip already alerted
            if (in_array($taskId, $alertSentIds, true)) {
                continue;
            }

            $taskMinutes = parse_due_time_minutes($dueTime);
            if ($taskMinutes === null) {
                cron_log('pre alert skip: unparseable due_time', [
                    'owner_id' => $ownerId,
                    'task_id'  => $taskId,
                    'due_time' => $dueTime,
                ]);
                continue;
            }

            // Fire when current time is at or past the 10-minute mark before due_time
            if ($nowMinutes < $taskMinutes - 10) {
                continue; // too early
            }

            // Do not alert if due_time has already passed (task is overdue)
            if ($nowMinutes >= $taskMinutes) {
                cron_log('pre alert skip: already past due_time', [
                    'owner_id'    => $ownerId,
                    'task_id'     => $taskId,
                    'due_time'    => $dueTime,
                    'now_minutes' => $nowMinutes,
                    'task_minutes'=> $taskMinutes,
                ]);
                continue;
            }

            // Send alert
            $message = implode("\n", [
                'まもなく予定です。',
                '',
                '・' . $task['title'] . '（' . $dueTime . '）',
                '',
                '必要があれば今のうちに準備してください。',
            ]);

            cron_log('pre alert push attempt', [
                'owner_id'     => $ownerId,
                'task_id'      => $taskId,
                'due_time'     => $dueTime,
                'now_minutes'  => $nowMinutes,
                'task_minutes' => $taskMinutes,
            ]);

            $line->pushMessage($lineUserId, $message);

            // Mark as sent
            $alertSentIds[]                      = $taskId;
            $state['pre_alert_sent_date']         = $today;
            $state['pre_alert_sent_task_ids']     = $alertSentIds;
            $convStateRepo->saveState($ownerId, $state);

            $sentCount++;
            cron_log('pre alert sent', ['owner_id' => $ownerId, 'task_id' => $taskId, 'due_time' => $dueTime]);
        }
    } catch (\Throwable $e) {
        cron_log('pre alert error', [
            'owner_id'     => $ownerId,
            'line_user_id' => $lineUserId,
            'error'        => $e->getMessage(),
        ]);
    }
}

cron_log('pre alert cron finished', [
    'total_users' => count($users),
    'sent'        => $sentCount,
]);
