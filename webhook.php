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
function line_reply(string $replyToken, string $message, ?array $quickReply = null): void
{
    global $config;

    $accessToken = trim((string)($config['line']['channel_access_token'] ?? ''));
    if ($accessToken === '') {
        webhook_log('LINE access token is empty');
        return;
    }

    $url = 'https://api.line.me/v2/bot/message/reply';

    $msgObj = ['type' => 'text', 'text' => $message];
    if ($quickReply !== null) {
        $msgObj['quickReply'] = $quickReply;
    }
    $payload = [
        'replyToken' => $replyToken,
        'messages'   => [$msgObj],
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
| Quick reply helpers — list paging only
|--------------------------------------------------------------------------
*/
function qr_item(string $label, string $text): array
{
    return ['type' => 'action', 'action' => ['type' => 'message', 'label' => $label, 'text' => $text]];
}

function build_list_quick_reply(int $page, int $totalPages): array
{
    if ($totalPages <= 1) {
        $items = [qr_item('今日', '今日')];
    } elseif ($page === 1) {
        $items = [qr_item('次', '次'), qr_item('今日', '今日')];
    } elseif ($page >= $totalPages) {
        $items = [qr_item('前', '前'), qr_item('今日', '今日')];
    } else {
        $items = [qr_item('前', '前'), qr_item('次', '次'), qr_item('今日', '今日')];
    }
    return ['items' => $items];
}

function build_today_quick_reply(int $count): array
{
    $items = [];
    $n     = min($count, 3);
    for ($i = 1; $i <= $n; $i++) {
        $items[] = qr_item('完了' . $i, '完了' . $i);
    }
    $items[] = qr_item('一覧', '一覧');
    return ['items' => $items];
}

/*
|--------------------------------------------------------------------------
| Task list page renderer
|--------------------------------------------------------------------------
*/
function render_task_list_page(
    array $allTasks,
    int $page,
    string $listToday,
    string $listTomorrow
): string {
    $pageSize   = 5;
    $total      = count($allTasks);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $pageSize;
    $pageTasks  = array_slice($allTasks, $offset, $pageSize);

    // Group tasks on this page (today / 直近 / 期限なし)
    // tomorrow + other are merged into 直近の予定
    $groups = ['today' => [], 'kinsetsu' => [], 'none' => []];
    foreach ($pageTasks as $idx => $t) {
        $num = $offset + $idx + 1;
        if (empty($t['due_date'])) {
            $groups['none'][] = ['num' => $num, 'task' => $t];
        } elseif ($t['due_date'] === $listToday) {
            $groups['today'][] = ['num' => $num, 'task' => $t];
        } else {
            $groups['kinsetsu'][] = ['num' => $num, 'task' => $t];
        }
    }

    $header = '未完了タスク一覧（' . $page . '/' . $totalPages . '）';
    $lines = [$header];

    // ■ 今日
    if (!empty($groups['today'])) {
        $lines[] = '';
        $lines[] = '■ 今日（' . count($groups['today']) . '件）';
        foreach ($groups['today'] as $e) {
            $t    = $e['task'];
            $line = $e['num'] . '. ' . $t['title'];
            if (!empty($t['due_time'])) {
                $line .= '（' . $t['due_time'] . '）';
            }
            $lines[] = $line;
        }
    }

    // ■ 今後の予定（明日以降の期日あり）
    if (!empty($groups['kinsetsu'])) {
        $lines[] = '';
        $lines[] = '■ 今後の予定（' . count($groups['kinsetsu']) . '件）';
        foreach ($groups['kinsetsu'] as $e) {
            $t         = $e['task'];
            $d         = new DateTime($t['due_date']);
            $dateLabel = $d->format('n') . '/' . $d->format('j');
            $line      = $e['num'] . '. ' . $t['title'];
            if (!empty($t['due_time'])) {
                $line .= '（' . $dateLabel . ' ' . $t['due_time'] . '）';
            } else {
                $line .= '（' . $dateLabel . '）';
            }
            $lines[] = $line;
        }
    }

    // ■ 期限なし
    if (!empty($groups['none'])) {
        $lines[] = '';
        $lines[] = '■ 期限なし（' . count($groups['none']) . '件）';
        foreach ($groups['none'] as $e) {
            $line = $e['num'] . '. ' . $e['task']['title'];
            if (!empty($e['task']['due_time'])) {
                $line .= '（' . $e['task']['due_time'] . '）';
            }
            $lines[] = $line;
        }
    }

    // Action footer
    $firstNum = $offset + 1;
    $lines[]  = '';
    $lines[]  = '→ 完了する場合：「完了 ' . $firstNum . '」';
    if ($page < $totalPages) {
        $lines[] = '→ 続きを見る：「次」';
    }

    return implode("\n", $lines);
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
        $lineUserId = (string) ($event['source']['userId'] ?? '');

        // [DEBUG] checkpoint 1: follow branch entered
        webhook_log('follow event: entered', [
            'line_user_id'       => $lineUserId,
            'reply_token_exists' => ($replyToken !== ''),
            'userRepo_ready'     => ($userRepo !== null),
            'convStateRepo_ready'=> ($convStateRepo !== null),
        ]);

        // Register user and save onboarding state
        if ($lineUserId !== '' && $userRepo !== null && $convStateRepo !== null) {
            try {
                $user = $userRepo->findByLineUserId($lineUserId);
                if ($user === null) {
                    $followOwnerId = $userRepo->create($lineUserId);
                    webhook_log('user created on follow', ['line_user_id' => $lineUserId, 'owner_id' => $followOwnerId]);
                } else {
                    $followOwnerId = (int) $user['id'];
                }
                $state = $convStateRepo->getState($followOwnerId);
                if (empty($state['onboarding_started_at'])) {
                    $state['onboarding_started_at']    = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
                    $state['onboarding_followup_sent'] = false;
                    $convStateRepo->saveState($followOwnerId, $state);
                    webhook_log('onboarding state saved', ['owner_id' => $followOwnerId]);
                } else {
                    // [DEBUG] checkpoint 2: already onboarded
                    webhook_log('follow event: onboarding already saved', ['owner_id' => $followOwnerId]);
                }
            } catch (\Throwable $e) {
                webhook_log('follow event state save failed', ['error' => $e->getMessage()]);
            }
        }

        // [DEBUG] checkpoint 3: about to reply
        webhook_log('follow event: about to reply', ['reply_token_exists' => ($replyToken !== '')]);

        if ($replyToken !== '') {
            $welcomeText = implode("\n", [
                'はじめまして、フジラマネージャーです。',
                '',
                'ここでは、LINEでタスク管理ができます。',
                '',
                'まずは試しに、1つ送ってみてください👇',
                '',
                '「今日 13:30 歯医者」',
                '「明日 税理士に連絡」',
                '「請求書確認」',
                '',
                'このように送るだけで登録されます。',
                '',
                '※あとで「一覧」と送ると確認できます',
            ]);
            line_reply($replyToken, $welcomeText);
        }

        // [DEBUG] checkpoint 4: follow handler done
        webhook_log('follow event: done');
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
    $text = trim(preg_replace('/[ 　]+/u', ' ', $text));
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
    if ($text === '戻す') {
        if ($replyToken !== '') {
            line_reply($replyToken, '取り消すタスク番号を指定してください（例: 戻す 1）');
        }
        continue;
    }

    if (preg_match('/^(?:戻す|\/undo)\s+(\d+)$/u', $text, $matches)) {
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
        $replyText  = '現在のタスクはありません';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tz           = new DateTimeZone('Asia/Tokyo');
                $listToday    = (new DateTime('now', $tz))->format('Y-m-d');
                $listTomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');
                $allTasks     = $taskRepo->getOpenTasksByOwner($ownerId, $listToday, $listTomorrow);
                if (!empty($allTasks)) {
                    $map = [];
                    foreach ($allTasks as $i => $t) {
                        $map[(string)($i + 1)] = (int) $t['id'];
                    }
                    $page       = 1;
                    $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                    $replyText  = render_task_list_page($allTasks, $page, $listToday, $listTomorrow);
                    $quickReply = build_list_quick_reply($page, $totalPages);

                    if ($convStateRepo !== null) {
                        webhook_log('list map save attempt', ['owner_id' => $ownerId, 'total' => count($allTasks)]);
                        try {
                            $state                        = $convStateRepo->getState($ownerId);
                            $state['last_task_list_map']  = $map;
                            $state['last_list_page']      = $page;
                            $state['last_list_today']     = $listToday;
                            $state['last_list_tomorrow']  = $listTomorrow;
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
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Next page command
    if ($text === '次' || $text === '/next') {
        $replyText  = '先に「一覧」を表示してから「次」を使ってください';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null && $convStateRepo !== null) {
            try {
                $state        = $convStateRepo->getState($ownerId);
                $currentPage  = (int) ($state['last_list_page'] ?? 0);
                $lastToday    = $state['last_list_today'] ?? null;
                $lastTomorrow = $state['last_list_tomorrow'] ?? null;

                if ($currentPage > 0 && $lastToday !== null && $lastTomorrow !== null) {
                    $allTasks   = $taskRepo->getOpenTasksByOwner($ownerId, $lastToday, $lastTomorrow);
                    $pageSize   = 5;
                    $totalPages = max(1, (int) ceil(count($allTasks) / $pageSize));

                    if ($currentPage >= $totalPages) {
                        $replyText = 'これが最後のページです。';
                    } else {
                        $newPage = $currentPage + 1;
                        $map     = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_task_list_page($allTasks, $newPage, $lastToday, $lastTomorrow);
                        $quickReply = build_list_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('next page', ['owner_id' => $ownerId, 'page' => $currentPage, 'total_pages' => $totalPages]);
                }
            } catch (\Throwable $e) {
                webhook_log('next page failed', ['error' => $e->getMessage()]);
                $replyText = 'ページ表示に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Previous page command
    if ($text === '前' || $text === '/prev') {
        $replyText  = '先に「一覧」を表示してから「前」を使ってください';
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null && $convStateRepo !== null) {
            try {
                $state        = $convStateRepo->getState($ownerId);
                $currentPage  = (int) ($state['last_list_page'] ?? 0);
                $lastToday    = $state['last_list_today'] ?? null;
                $lastTomorrow = $state['last_list_tomorrow'] ?? null;

                if ($currentPage > 0 && $lastToday !== null && $lastTomorrow !== null) {
                    if ($currentPage <= 1) {
                        $replyText = 'これが最初のページです。';
                    } else {
                        $allTasks   = $taskRepo->getOpenTasksByOwner($ownerId, $lastToday, $lastTomorrow);
                        $newPage    = $currentPage - 1;
                        $totalPages = max(1, (int) ceil(count($allTasks) / 5));
                        $map        = [];
                        foreach ($allTasks as $i => $t) {
                            $map[(string)($i + 1)] = (int) $t['id'];
                        }
                        $replyText  = render_task_list_page($allTasks, $newPage, $lastToday, $lastTomorrow);
                        $quickReply = build_list_quick_reply($newPage, $totalPages);

                        $state['last_task_list_map'] = $map;
                        $state['last_list_page']     = $newPage;
                        $convStateRepo->saveState($ownerId, $state);
                    }
                    webhook_log('prev page', ['owner_id' => $ownerId, 'page' => $currentPage]);
                }
            } catch (\Throwable $e) {
                webhook_log('prev page failed', ['error' => $e->getMessage()]);
                $replyText = 'ページ表示に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // Task complete command
    if ($text === '完了') {
        if ($replyToken !== '') {
            line_reply($replyToken, '完了するタスク番号を指定してください（例: 完了 1）');
        }
        continue;
    }

    if (preg_match('/^(?:完了|\/done)\s*(\d+)$/u', $text, $matches)) {
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

        $replyText = '該当するタスクが見つかりません';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $done = $taskRepo->completeOpenTaskById($ownerId, $taskId);
                if ($done !== null) {
                    $tz           = new DateTimeZone('Asia/Tokyo');
                    $doneToday    = (new DateTime('now', $tz))->format('Y-m-d');
                    $doneTomorrow = (new DateTime('tomorrow', $tz))->format('Y-m-d');

                    $suffix = '';
                    if (!empty($done['due_date'])) {
                        if ($done['due_date'] === $doneToday) {
                            $dateLbl = '今日';
                        } elseif ($done['due_date'] === $doneTomorrow) {
                            $dateLbl = '明日';
                        } else {
                            $dateLbl = $done['due_date'];
                        }
                        $suffix = !empty($done['due_time'])
                            ? '（' . $dateLbl . ' ' . $done['due_time'] . '）'
                            : '（' . $dateLbl . '）';
                    } elseif (!empty($done['due_time'])) {
                        $suffix = '（' . $done['due_time'] . '）';
                    }

                    $remaining      = $taskRepo->countOpenTasksByOwner($ownerId);
                    $todayRemaining = $taskRepo->countTodayOpenTasksByOwner($ownerId, $doneToday);
                    if ($remaining === 0) {
                        $remainText = '今のタスクは0件です。';
                    } elseif ($todayRemaining === 0) {
                        $remainText = '今日のタスクは完了です。';
                    } else {
                        $remainText = "今日の残りは{$todayRemaining}件です。";
                    }
                    $replyText = "ナイスです。\n・" . $done['title'] . $suffix . " を完了しました。\n\n" . $remainText;
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
    if ($text === '削除') {
        if ($replyToken !== '') {
            line_reply($replyToken, '削除するタスク番号を指定してください（例: 削除 1）');
        }
        continue;
    }

    if (preg_match('/^(?:削除|\/delete|\/del)\s+(\d+)$/u', $text, $matches)) {
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

        $replyText = '該当するタスクが見つかりません';
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
        $nowJst   = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $hour     = (int) $nowJst->format('H');
        if ($hour >= 5 && $hour < 11) {
            $greeting = 'おはようございます。';
        } elseif ($hour < 18) {
            $greeting = 'こんにちは。';
        } else {
            $greeting = 'こんばんは。';
        }

        $replyText  = $greeting . "\n\n今日が期限のタスクはありません";
        $quickReply = null;
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $today      = $nowJst->format('Y-m-d');
                $todayTasks = $taskRepo->getTodayTasksByOwner($ownerId, $today);
                if (!empty($todayTasks)) {
                    $lines = [$greeting, '', '今日のタスク:'];
                    foreach ($todayTasks as $i => $t) {
                        $line = ($i + 1) . '. ' . $t['title'];
                        if (!empty($t['due_time'])) {
                            $line .= '（' . $t['due_time'] . '）';
                        }
                        $lines[] = $line;
                    }
                    $replyText  = implode("\n", $lines);
                    $quickReply = build_today_quick_reply(count($todayTasks));
                }
            } catch (\Throwable $e) {
                webhook_log('task today failed', ['error' => $e->getMessage()]);
                $replyText = '今日のタスク取得に失敗しました';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $replyText, $quickReply);
        }
        continue;
    }

    // /ping
    if ($text === '/ping') {
        if ($replyToken !== '') {
            line_reply($replyToken, 'pong');
        }
        continue;
    }

    // Brief on/off
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

    // Date search: "M月D日" or "M/D" alone → return open tasks for that date
    if (preg_match('/^(\d{1,2})月(\d{1,2})日[ 　]*$/u', $text, $dm)
        || preg_match('/^(\d{1,2})\/(\d{1,2})[ 　]*$/u', $text, $dm)) {
        $tz    = new DateTimeZone('Asia/Tokyo');
        $year  = (int) (new DateTime('now', $tz))->format('Y');
        $date  = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
        $label = (int) $dm[1] . '/' . (int) $dm[2];
        $msg   = $label . ' の予定はありません。';
        if ($ownerId !== null && $taskRepo !== null) {
            try {
                $tasks = $taskRepo->getOpenTasksByDate($ownerId, $date);
                if (!empty($tasks)) {
                    $lines = [$label . ' の予定（' . count($tasks) . '件）', ''];
                    foreach ($tasks as $i => $t) {
                        $line = ($i + 1) . '. ' . $t['title'];
                        if (!empty($t['due_time'])) {
                            $line .= '（' . $t['due_time'] . '）';
                        }
                        $lines[] = $line;
                    }
                    $msg = implode("\n", $lines);
                }
                webhook_log('date search', ['owner_id' => $ownerId, 'date' => $date, 'count' => count($tasks)]);
            } catch (\Throwable $e) {
                webhook_log('date search failed', ['owner_id' => $ownerId, 'date' => $date, 'error' => $e->getMessage()]);
                $msg = '検索に失敗しました。';
            }
        }
        if ($replyToken !== '') {
            line_reply($replyToken, $msg);
        }
        continue;
    }

    // Save task
    $isCommand = ($text === '一覧'
        || $text === '/list'
        || $text === '次'
        || $text === '/next'
        || $text === '前'
        || $text === '/prev'
        || $text === '履歴'
        || $text === '/history'
        || $text === 'help'
        || $text === 'ヘルプ'
        || $text === '/help'
        || $text === '/brief'
        || $text === 'ブリーフ');

    webhook_log('task attempt', ['owner_id' => $ownerId, 'text' => $text, 'is_command' => $isCommand]);

    if ($ownerId === null) {
        webhook_log('task skipped: owner_id is null', ['line_user_id' => $lineUserId]);
    }

    if (!$isCommand && $ownerId !== null && $taskRepo !== null && $text !== '') {
        // Detect prefix-only inputs with no content (e.g. "今日は", "明日の")
        if (preg_match('/^(?:今日|明日)(?:[ 　]+|の|は)?[ 　]*$/u', $text)) {
            webhook_log('task skipped: empty title after prefix strip', ['text' => $text, 'owner_id' => $ownerId]);
            if ($replyToken !== '') {
                line_reply($replyToken, implode("\n", [
                    '内容を入力してください。',
                    '',
                    '例：',
                    '「今日 13:30 歯医者」',
                    '「明日 税理士に連絡」',
                    '「請求書確認」',
                ]));
            }
            continue;
        }

        // Parse due_date from natural Japanese date prefixes
        // Order: specific → general (M月D日 / M/D before 今日 / 明日)
        $dueDate   = null;
        $dueTime   = null;
        $saveTitle = $text;
        $tz        = new DateTimeZone('Asia/Tokyo');

        if (preg_match('/^(\d{1,2})月(\d{1,2})日(?:[ 　]+|の)(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[3]);
            $year      = (int) (new DateTime('now', $tz))->format('Y');
            $dueDate   = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})[ 　]+(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[3]);
            $year      = (int) (new DateTime('now', $tz))->format('Y');
            $dueDate   = sprintf('%04d-%02d-%02d', $year, (int) $dm[1], (int) $dm[2]);
        } elseif (preg_match('/^今日(?:[ 　]+|の|は)(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[1]);
            $dueDate   = (new DateTime('now', $tz))->format('Y-m-d');
        } elseif (preg_match('/^明日(?:[ 　]+|の|は)(.+)$/u', $text, $dm)) {
            $saveTitle = trim($dm[1]);
            $d = new DateTime('now', $tz);
            $d->modify('+1 day');
            $dueDate = $d->format('Y-m-d');
        }

        // Parse due_time from the start of saveTitle (leading time: C / A / B patterns)
        // Separator (space / に / から / の) is absorbed into the pattern
        $timePattern = 'no_match';
        if ($saveTitle !== '') {
            if (preg_match('/^(\d{1,2}:\d{2})(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_hhmm';
            } elseif (preg_match('/^(\d{1,2}時\d{1,2}分)(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_ji_fun';
            } elseif (preg_match('/^(\d{1,2}時(?:半)?)(?:[ 　]+|に|から|の|まで|で)(.+)$/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim($tm[2]);
                $timePattern = 'leading_ji';
            }
        }

        // Parse trailing time (title + time word order: D pattern)
        // Only when date is set and time not yet found
        if ($dueDate !== null && $dueTime === null && $saveTitle !== '') {
            if (preg_match('/^(.+?)[ 　]+(\d{1,2}:\d{2})$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_hhmm';
            } elseif (preg_match('/^(.+?)[ 　]+(\d{1,2}時半)$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_ji_han';
            } elseif (preg_match('/^(.+?)[ 　]+(\d{1,2}時)$/u', $saveTitle, $tm)) {
                $saveTitle   = trim($tm[1]);
                $dueTime     = $tm[2];
                $timePattern = 'trailing_ji';
            }
        }

        // Fallback: inline time extraction — handles no-separator cases (e.g. "20時10分ラインチェック")
        // Only when neither leading nor trailing parse found a time
        // Order: 時分 > HH:MM > 時半 > 時 (specific to general; HH:MM before 時 to avoid 13:30 → 13時 mismatch)
        if ($dueTime === null && $saveTitle !== '') {
            if (preg_match('/(\d{1,2}時\d{1,2}分)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji_fun';
            } elseif (preg_match('/(\d{1,2}:\d{2})(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_hhmm';
            } elseif (preg_match('/(\d{1,2}時半)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji_han';
            } elseif (preg_match('/(\d{1,2}時)(?:に|から|の|まで|で|[ 　]+)?/u', $saveTitle, $tm)) {
                $dueTime     = $tm[1];
                $saveTitle   = trim(preg_replace('/' . preg_quote($tm[0], '/') . '/u', '', $saveTitle, 1));
                $timePattern = 'inline_ji';
            }
        }

        // Strip any remaining leading の from title
        $saveTitle = trim(preg_replace('/^の/u', '', $saveTitle));

        // Pre-save diagnostic log
        webhook_log('task parse result', [
            'pattern'  => $timePattern,
            'title'    => $saveTitle,
            'due_date' => $dueDate,
            'due_time' => $dueTime,
        ]);

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
            if ($replyToken !== '') {
                $msg = "登録しました:\n・" . $saveTitle;
                if ($dueDate !== null) {
                    $todayStr    = (new DateTime('now', $tz))->format('Y-m-d');
                    $tomorrowStr = (new DateTime('tomorrow', $tz))->format('Y-m-d');
                    if ($dueDate === $todayStr) {
                        $dateLbl = '今日';
                    } elseif ($dueDate === $tomorrowStr) {
                        $dateLbl = '明日';
                    } else {
                        $dateLbl = $dueDate;
                    }
                    if ($dueTime !== null) {
                        $msg .= "\n期限：" . $dateLbl . ' ' . $dueTime;
                    } else {
                        $msg .= "\n期限：" . $dateLbl;
                    }
                } elseif ($dueTime !== null) {
                    $msg .= "\n時刻：" . $dueTime;
                }
                line_reply($replyToken, $msg);
            }
        } catch (\Throwable $e) {
            webhook_log('task create failed', ['error' => $e->getMessage()]);
        }
        continue;
    }

    if ($replyToken === '') {
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

    if ($text === 'help' || $text === 'ヘルプ' || $text === '/help') {
        $helpText = implode("\n", [
            '【使い方】',
            '',
            '① タスクを送るだけで登録',
            '・今日 資料送る',
            '・明日 税理士に連絡',
            '・今日 13:30 歯医者',
            '',
            '② 今日のタスクを確認',
            '・今日',
            '',
            '👉 そのまま「完了1」で消せます',
            '',
            '③ 一覧を見る',
            '・一覧',
            '',
            '👉 「次」「前」で移動できます',
            '',
            '④ タスクを完了',
            '・完了1',
            '',
            '⑤ 特定の日を確認',
            '・3月20日',
            '・3/20',
            '',
            '---',
            '',
            '■ ポイント',
            '・自然な文章でOKです',
            '・時間や日付も自動で認識します',
            '・通知でやることをお知らせします',
            '・リッチメニューからも操作できます',
        ]);
        line_reply($replyToken, $helpText);
        continue;
    }

    // default fallback response
    line_reply($replyToken, implode("\n", [
        '理解できませんでした。',
        '',
        '例:',
        '・今日 資料送る',
        '・一覧',
        '・完了 1',
        '',
        '困ったら「ヘルプ」と送ってください。',
    ]));
}

http_response_code(200);
echo 'OK';