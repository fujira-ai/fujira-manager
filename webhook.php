<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Simple logger
|--------------------------------------------------------------------------
*/
function webhook_log(string $message, array $context = []): void
{
    global $config;

    $logDir = $config['paths']['log_dir'] ?? (__DIR__ . '/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    @file_put_contents($logDir . '/webhook.log', $line, FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| LINE reply helper
|--------------------------------------------------------------------------
*/
function line_reply(string $replyToken, string $message): void
{
    global $config;

    $accessToken = trim((string)($config['line']['channel_access_token'] ?? ''));
    if ($accessToken === '') {
        webhook_log('LINE access token is empty');
        return;
    }

    $url = 'https://api.line.me/v2/bot/message/reply';

    $payload = [
        'replyToken' => $replyToken,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message,
            ]
        ],
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        webhook_log('json_encode failed', [
            'json_error' => json_last_error_msg(),
            'payload' => $payload,
        ]);
        return;
    }

    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . $accessToken,
        'Content-Length: ' . strlen($json),
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    webhook_log('LINE reply result', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'request_json' => $json,
        'response' => $response,
    ]);
}

/*
|--------------------------------------------------------------------------
| Browser direct access test
|--------------------------------------------------------------------------
*/
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if ($body === '' && $signature === '') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "webhook ready";
    exit;
}

/*
|--------------------------------------------------------------------------
| Signature validation
|--------------------------------------------------------------------------
*/
$channelSecret = $config['line']['channel_secret'] ?? '';
if ($channelSecret === '') {
    http_response_code(500);
    echo 'LINE channel_secret is empty';
    webhook_log('LINE channel_secret is empty');
    exit;
}

$hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));

if (!hash_equals($hash, $signature)) {
    http_response_code(400);
    echo 'Invalid signature';
    webhook_log('Invalid signature', [
        'received_signature' => $signature,
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Parse event payload
|--------------------------------------------------------------------------
*/
$data = json_decode($body, true);

if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
    http_response_code(400);
    echo 'Invalid payload';
    webhook_log('Invalid payload', ['body' => $body]);
    exit;
}

webhook_log('Webhook received', ['events_count' => count($data['events'])]);

/*
|--------------------------------------------------------------------------
| Storage initialization
|--------------------------------------------------------------------------
*/
$userRepo      = null;
$taskRepo      = null;
$convStateRepo = null;
try {
    $db            = new \FujiraManager\Storage\Database($config['db']);
    $userRepo      = new \FujiraManager\Storage\UserRepository($db);
    $taskRepo      = new \FujiraManager\Storage\TaskRepository($db);
    $convStateRepo = new \FujiraManager\Storage\ConvStateRepository($db);
} catch (\Throwable $e) {
    webhook_log('DB init failed', ['error' => $e->getMessage()]);
}

/*
|--------------------------------------------------------------------------
| Handle events
|--------------------------------------------------------------------------
*/
foreach ($data['events'] as $event) {
    $eventType = $event['type'] ?? '';

    // Follow event
    if ($eventType === 'follow') {
        $replyToken = $event['replyToken'] ?? '';
        if ($replyToken !== '') {
            line_reply($replyToken, '友だち追加ありがとうございます。Fujira Manager 起動確認OKです。');
        }
        continue;
    }

    // Only text message for now
    if ($eventType !== 'message') {
        continue;
    }

    if (($event['message']['type'] ?? '') !== 'text') {
        continue;
    }

    $replyToken = $event['replyToken'] ?? '';
    $text = trim((string) ($event['message']['text'] ?? ''));
    $lineUserId = (string) ($event['source']['userId'] ?? '');

    webhook_log('Text message received', [
        'user_id' => $lineUserId,
        'text' => $text,
    ]);

    // Auto-register user and resolve owner_id
    $ownerId = null;
    if ($lineUserId !== '' && $userRepo !== null) {
        try {
            $user = $userRepo->findByLineUserId($lineUserId);
            if ($user === null) {
                $ownerId = $userRepo->create($lineUserId);
                webhook_log('user created', ['line_user_id' => $lineUserId, 'owner_id' => $ownerId]);
            } else {
                $ownerId = (int) $user['id'];
                webhook_log('user exists', ['line_user_id' => $lineUserId, 'owner_id' => $ownerId]);
            }
        } catch (\Throwable $e) {
            webhook_log('user registration failed', ['error' => $e->getMessage()]);
        }
    }

    // History command
    if ($text === '履歴' || $text === '/history') {
        $replyText = '完了済みタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $doneTasks = $taskRepo->getDoneTasksByOwner($ownerId);
                if (!empty($doneTasks)) {
                    $doneMap = [];
                    $lines   = ['完了済みタスク:'];
                    foreach ($doneTasks as $i => $t) {
                        $num           = $i + 1;
                        $doneMap[(string)$num] = (int)$t['id'];
                        $lines[]       = $num . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);

                    if ($convStateRepo !== null) {
                        try {
                            $state = $convStateRepo->getState($ownerId);
                            $state['last_done_task_list_map'] = $doneMap;
                            $convStateRepo->saveState($ownerId, $state);
                        } catch (\Throwable $e) {
                            webhook_log('conv_state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('task history failed', ['error' => $e->getMessage()]);
                $replyText = '履歴取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Undo command
    if (preg_match('/^(?:戻す|\/undo)\s+(\d+)$/', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'undo', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

        // Resolve done list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state   = $convStateRepo->getState($ownerId);
                $doneMap = $state['last_done_task_list_map'] ?? [];
                if (isset($doneMap[(string)$num])) {
                    $taskId = (int) $doneMap[(string)$num];
                }
                webhook_log('resolved taskId', ['command' => 'undo', 'input' => $num, 'resolved' => $taskId]);
            } catch (\Throwable $e) {
                webhook_log('task command resolve failed', ['command' => 'undo', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する完了済みタスクが見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $reopened = $taskRepo->reopenTaskById($ownerId, $taskId);
                if ($reopened !== null) {
                    $replyText = "未完了に戻しました:\n・" . $reopened['title'];
                    webhook_log('task reopened', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task undo failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク取り消し処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task list command
    if ($text === '一覧' || $text === '/list') {
        $replyText = '現在 open のタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tasks = $taskRepo->getOpenTasksByOwner($ownerId);
                if (!empty($tasks)) {
                    $map   = [];
                    $lines = ['現在のタスク:'];
                    foreach ($tasks as $i => $t) {
                        $num        = $i + 1;
                        $map[(string)$num] = (int)$t['id'];
                        $lines[]    = $num . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);

                    if ($convStateRepo !== null) {
                        webhook_log('list map save attempt', ['owner_id' => $ownerId, 'map' => $map]);
                        try {
                            $state = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map'] = $map;
                            $convStateRepo->saveState($ownerId, $state);
                            webhook_log('list map save result', ['owner_id' => $ownerId, 'saved' => true]);
                        } catch (\Throwable $e) {
                            webhook_log('conv_state save failed', ['error' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                webhook_log('task list failed', ['error' => $e->getMessage()]);
                $replyText = 'タスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task complete command
    if (preg_match('/^(?:完了|\/done)\s+(\d+)$/', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'complete', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

        // Resolve list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                webhook_log('state loaded', ['owner_id' => $ownerId, 'state' => $state]);
                $map   = $state['last_task_list_map'] ?? [];
                if (isset($map[(string)$num])) {
                    $taskId = (int) $map[(string)$num];
                }
                webhook_log('resolved taskId', ['input' => $num, 'resolved' => $taskId]);
            } catch (\Throwable $e) {
                webhook_log('task command resolve failed', ['command' => 'complete', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する open task が見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $done = $taskRepo->completeOpenTaskById($ownerId, $taskId);
                if ($done !== null) {
                    $replyText = "完了にしました:\n・" . $done['title'];
                    webhook_log('task completed', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task complete failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク完了処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Task delete command
    if (preg_match('/^(?:削除|\/delete|\/del)\s+(\d+)$/', $text, $matches)) {
        $num    = (int) $matches[1];
        $taskId = $num;

        if ($num < 1) {
            webhook_log('task command invalid', ['command' => 'delete', 'input' => $num, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '番号の指定が不正です');
            }
            continue;
        }

        // Resolve list number → task_id via conv_state
        if ($ownerId !== null && $convStateRepo !== null) {
            try {
                $state = $convStateRepo->getState($ownerId);
                webhook_log('state loaded', ['owner_id' => $ownerId, 'state' => $state]);
                $map   = $state['last_task_list_map'] ?? [];
                if (isset($map[(string)$num])) {
                    $taskId = (int) $map[(string)$num];
                }
                webhook_log('resolved taskId', ['input' => $num, 'resolved' => $taskId]);
            } catch (\Throwable $e) {
                webhook_log('task command resolve failed', ['command' => 'delete', 'input' => $num, 'owner_id' => $ownerId, 'error' => $e->getMessage()]);
            }
        }

        $replyText = '該当する open task が見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $deleted = $taskRepo->deleteOpenTaskById($ownerId, $taskId);
                if ($deleted !== null) {
                    $replyText = "削除しました:\n・" . $deleted['title'];
                    webhook_log('task deleted', ['owner_id' => $ownerId, 'task_id' => $taskId]);
                }
            } catch (\Throwable $e) {
                webhook_log('task delete failed', ['owner_id' => $ownerId, 'input' => $num, 'task_id' => $taskId, 'error' => $e->getMessage()]);
                $replyText = 'タスク削除処理に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Tomorrow command ("明日" alone — "明日 XXX" goes to task save)
    if ($text === '明日' || $text === '/tomorrow') {
        $replyText = '明日が期限のタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $d = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
                $d->modify('+1 day');
                $tomorrow      = $d->format('Y-m-d');
                $tomorrowTasks = $taskRepo->getTomorrowTasksByOwner($ownerId, $tomorrow);
                if (!empty($tomorrowTasks)) {
                    $lines = ['明日のタスク:'];
                    foreach ($tomorrowTasks as $i => $t) {
                        $lines[] = ($i + 1) . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);
                }
            } catch (\Throwable $e) {
                webhook_log('task tomorrow failed', ['error' => $e->getMessage()]);
                $replyText = '明日のタスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Today command ("今日" alone — "今日 XXX" goes to task save)
    if ($text === '今日' || $text === '/today') {
        $replyText = '今日が期限のタスクはありません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $today      = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
                if (!empty($todayTasks)) {
                    $lines = ['今日のタスク:'];
                    foreach ($todayTasks as $i => $t) {
                        $lines[] = ($i + 1) . '. ' . $t['title'];
                    }
                    $replyText = implode("\n", $lines);
                }
            } catch (\Throwable $e) {
                webhook_log('task today failed', ['error' => $e->getMessage()]);
                $replyText = '今日のタスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // Save task
    $isCommand = ($text === '一覧'
        || $text === '/list'
        || $text === '履歴'
        || $text === '/history'
        || $text === '今日'
        || $text === '/today'
        || $text === '明日'
        || $text === '/tomorrow'
        || $text === '/ping'
        || $text === '/brief'
        || $text === 'ブリーフ'
        || $text === 'brief on'
        || $text === 'ブリーフオン'
        || $text === 'brief off'
        || $text === 'ブリーフオフ'
        || preg_match('/^(?:完了|\/done)\s+\d+$/', $text) === 1
        || preg_match('/^(?:削除|\/delete|\/del)\s+\d+$/', $text) === 1
        || preg_match('/^(?:戻す|\/undo)\s+\d+$/', $text) === 1);

    webhook_log('task attempt', ['owner_id' => $ownerId, 'text' => $text, 'is_command' => $isCommand]);

    if ($ownerId === null) {
        webhook_log('task skipped: owner_id is null', ['line_user_id' => $lineUserId]);
    }

    if (!$isCommand && $ownerId !== null && $taskRepo !== null && $text !== '') {
        // Detect prefix-only inputs with no content (e.g. "今日は", "明日の")
        if (preg_match('/^(?:今日|明日)(?:[ 　]+|の|は)?[ 　]*$/u', $text)) {
            webhook_log('task skipped: empty title after prefix strip', ['text' => $text, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '内容を入力してください');
            }
            continue;
        }

        // Parse due_date from natural Japanese date prefixes
        $dueDate   = null;
        $dueTime   = null;
        $saveTitle = $text;
        $tz        = new DateTimeZone('Asia/Tokyo');

        if (preg_match('/^今日(?:[ 　]+|の|は)(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[1]);
            $dueDate   = (new DateTime('now', $tz))->format('Y-m-d');
        } elseif (preg_match('/^明日(?:[ 　]+|の|は)(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[1]);
            $d = new DateTime('now', $tz);
            $d->modify('+1 day');
            $dueDate = $d->format('Y-m-d');
        }

        // Parse due_time from the start of saveTitle (only when due_date was resolved)
        if ($dueDate !== null && $saveTitle !== '') {
            if (preg_match('/^(\d{1,2}:\d{2})[ 　]*(.*)$/u', $saveTitle, $tm)) {
                $dueTime   = $tm[1];
                $saveTitle = trim(preg_replace('/^に/u', '', $tm[2]));
            } elseif (preg_match('/^(\d{1,2}時半)[ 　]*(.*)$/u', $saveTitle, $tm)) {
                $dueTime   = $tm[1];
                $saveTitle = trim(preg_replace('/^に/u', '', $tm[2]));
            } elseif (preg_match('/^(\d{1,2}時)[ 　]*(.*)$/u', $saveTitle, $tm)) {
                $dueTime   = $tm[1];
                $saveTitle = trim(preg_replace('/^に/u', '', $tm[2]));
            }
        }

        if ($saveTitle === '') {
            webhook_log('task skipped: empty title after prefix strip', ['text' => $text, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, '内容を入力してください');
            }
            continue;
        }

        try {
            $taskId = $taskRepo->create($ownerId, $saveTitle, $dueDate, $dueTime);
            webhook_log('task created', ['owner_id' => $ownerId, 'title' => $saveTitle, 'due_date' => $dueDate, 'due_time' => $dueTime, 'task_id' => $taskId]);
        } catch (\Throwable $e) {
            webhook_log('task create failed', ['error' => $e->getMessage()]);
        }
    }

    if ($replyToken === '') {
        continue;
    }

    // simple test responses
    if ($text === '/ping') {
        line_reply($replyToken, 'pong');
        continue;
    }

    if ($text === 'brief on' || $text === 'ブリーフオン') {
        $replyText = '朝ブリーフを有効にしました';
        if ($ownerId !== null && $userRepo !== null) {
            try {
                $userRepo->updateBriefEnabled($ownerId, 1);
                webhook_log('brief enabled', ['owner_id' => $ownerId]);
            } catch (\Throwable $e) {
                webhook_log('brief enable failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
                $replyText = '設定の更新に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    if ($text === 'brief off' || $text === 'ブリーフオフ') {
        $replyText = '朝ブリーフを停止しました';
        if ($ownerId !== null && $userRepo !== null) {
            try {
                $userRepo->updateBriefEnabled($ownerId, 0);
                webhook_log('brief disabled', ['owner_id' => $ownerId]);
            } catch (\Throwable $e) {
                webhook_log('brief disable failed', ['owner_id' => $ownerId, 'error' => $e->getMessage()]);
                $replyText = '設定の更新に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    if ($text === '/brief' || $text === 'ブリーフ') {
        $replyText = "【今日のブリーフ】\n\n今日やるべきタスクはありません";
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $today      = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
                $noneTasks  = $taskRepo->getNoDueDateTasksByOwner($ownerId);

                if (!empty($todayTasks) || !empty($noneTasks)) {
                    $sections = ["【今日のブリーフ】"];
                    $counter  = 1;

                    if (!empty($todayTasks)) {
                        $sections[] = "\n■ 今日の期限";
                        foreach ($todayTasks as $t) {
                            $prefix = (!empty($t['due_time'])) ? $t['due_time'] . ' ' : '';
                            $sections[] = $counter++ . '. ' . $prefix . $t['title'];
                        }
                    }

                    if (!empty($noneTasks)) {
                        $sections[] = "\n■ その他（未期限）";
                        foreach ($noneTasks as $t) {
                            $sections[] = $counter++ . '. ' . $t['title'];
                        }
                    }

                    $replyText = implode("\n", $sections);
                }
            } catch (\Throwable $e) {
                webhook_log('brief failed', ['error' => $e->getMessage()]);
                $replyText = 'ブリーフ取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText);
        }
        continue;
    }

    // default echo response
    line_reply($replyToken, '受信: ' . $text);
}

http_response_code(200);
echo 'OK';