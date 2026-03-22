# CHANGELOG

All notable changes to Fujira Manager will be documented in this file.

---

## v1.3.7-dev - 2026-03-23

### Added
- logs 配下の .log を日次でバックアップする `cron/log_backup.php` を追加
- バックアップログを gzip 圧縮して `logs/backup/YYYY-MM-DD/` に保存するよう改善
- 30日を超えた古いバックアップを自動削除する世代管理を追加

### Improved
- 障害調査や課金トラブル時のためにログ保全運用を強化
- `logs/cron_log_backup.log` に実行結果を記録するよう改善

### Fixed
- `cron/log_backup.php` で `cron_log_backup.log` 自身をバックアップ対象から除外
- 実行中ログを読んで圧縮・truncate してしまう分かりにくい挙動を防止

---

## v1.3.6-dev - 2026-03-23

### Fixed
- `customer.subscription.updated` / `customer.subscription.deleted` で `current_period_end` 未取得時に `subscription_expires_at` が 1970-01-01 になる問題を修正
- `stripe_period_end_to_datetime()` を追加し、無効なUnix timestampを保存しないよう改善
- 有効な期限が取得できない場合は既存の `subscription_expires_at` を維持するよう修正

### Improved
- 解約後も `subscription_expires_at` まで利用可能な仕様を安定化
- Stripe Webhook の期限管理ロジックを堅牢化
本
### Confirmed
- サブスク登録 → 解約 → 期限まで利用 → 無料枠制限再開 の一連のフローが正常動作することを確認

---

## v1.3.5-dev - 2026-03-23

### Added
- 課金・解約導線用のワンタイムトークン管理テーブル `user_tokens` を追加
- TokenRepository を追加し、purpose付き短命トークンを発行・消費できるよう改善
- TokenRepository に `validateToken()` を追加し、GET時はトークン検証のみ行えるよう改善

### Changed
- `upgrade.php` を token 方式に変更し、GETではLP表示、POSTでStripe Checkout Sessionを生成するよう改善
- `stripe/portal.php` を token 方式に変更し、GETでは確認画面、POSTでBilling Portal Sessionを生成するよう改善
- LINEの課金導線・解約導線で LINE user_id をURLに出さないよう改善

### Fixed
- LINEリンクプレビューによる先行GETでワンタイムトークンが消費される問題を修正
- ユーザーの実操作（POST）時のみトークンを消費するよう変更

---

## v1.3.4-dev - 2026-03-23

### Added
- Stripe Customer Portal を利用した解約導線を追加
- stripe/portal.php を新規作成
  - stripe_customer_id を元に Billing Portal Session を生成
  - 303 リダイレクトで Portal に遷移

### Changed
- LINE の「解約」「プラン」「課金」コマンドで
  Customer Portal への導線を追加

### Security
- portal.php にてユーザーIDの簡易検証を追加

/---

## v1.3.3-dev - 2026-03-23

### Fixed
- 25件到達時の事前警告が動作しない不具合を修正
  - UserRepository に updateWarnedLimit() メソッドが未実装だった問題を解消

### Confirmed
- 無料枠30件制限 → 課金導線 → Stripe決済 → 有料化の一連のフローが正常動作することを確認

---

## v1.3.2-dev - 2026-03-23

### Changed
- LINEの課金導線を Stripe Checkout 直リンクから LP（upgrade.php）経由に変更
  - 無料枠超過メッセージのURLを /upgrade.php?uid= に変更
  - 25件到達時の事前警告メッセージのURLを /upgrade.php?uid= に変更

### Improvement
- 課金導線にLPを挟むことで、課金率（CV率）の改善を狙う構成に変更

---

## v1.3.1-dev - 2026-03-22

### Added
- 無料ユーザーが当月25件以上登録した際に、一度だけ事前課金警告を送る導線を追加
- users テーブルに warned_limit カラムを追加

### Changed
- 無料枠（月30件）超過時の課金案内メッセージを改善
- 有料プランの価値（無制限・自動リマインド・抜け漏れ防止）が伝わる文面に調整

---

## v1.3.0-dev - 2026-03-22

### Added
- Stripe Checkout による月額980円の有料プランを追加
- 無料プランは当月30件までタスク登録可能、超過時に課金案内をLINE送信
- 有料ユーザーはタスク登録無制限
- users テーブルに課金関連カラムを追加
  （is_paid / subscription_status / subscription_expires_at / stripe_customer_id / stripe_subscription_id）
- stripe/checkout.php — Checkout セッション開始エンドポイント（uid → Stripe リダイレクト）
- stripe/webhook.php — Stripe Webhook 受信・署名検証・DB更新
- stripe/success.php / stripe/cancel.php — 決済後ランディングページ
- app/config.secret.php.example — 秘匿設定テンプレート
- app/config.secret.php による秘匿情報の外部管理（git 管理外）
- UserRepository::findById() / findByStripeCustomerId() / updateSubscription() を追加
- TaskRepository::countMonthlyCreatedByOwner() を追加（range 検索、DATE_FORMAT 不使用）
- is_paid_user() ヘルパー関数を webhook.php に追加

### Changed
- app/config.php に stripe セクションを追加し config.secret.php による上書きに対応
- .gitignore に app/config.secret.php を追加
- タスク登録直前に無料枠判定を挿入。超過時は保存せず課金案内を返す
- 課金状態確認で例外が発生した場合は fail-closed（保存しない）とし、再試行を促すメッセージを返す
- customer.subscription.updated / customer.subscription.deleted では is_paid を即時変更せず、subscription_expires_at までは有料扱いを継続するよう修正
- 有料判定を is_paid=1 かつ subscription_expires_at > now() の複合条件に統一

### Billing rules
- 有料判定：is_paid = 1 かつ subscription_expires_at > now()
- is_paid = 1 にするのは invoice.payment_succeeded のみ
- customer.subscription.updated / customer.subscription.deleted では is_paid を変更しない
- 解約後も subscription_expires_at まで有料扱いを継続

---

## v1.2.1-dev - 2026-03-21

### Fixed
- 「今日」表示後に「完了N」を送ると別リストのタスクが完了される不具合を修正
- 「今日」コマンドが `last_task_list_map` を保存していなかったため、直前の「一覧」などのマップが残り誤完了が起きていた
- `完了N` ハンドラーの生IDフォールバックを除去。マップに存在しない番号はエラーを返すよう変更
- `次`/`前` ハンドラーの全タスク取得条件を `=== 'all'` に限定し、`today` モードで誤って全タスク検索が動かないよう修正

---

## v1.2.0-dev - 2026-03-21

### Added
- タスク登録時に文末の `#タグ名` を抽出して `tasks.tag` に保存する機能を追加
- `#タグ名` の単独送信で、そのタグの open タスク一覧を確認できるタグ検索機能を追加
- タグ一覧に 1ページ5件のページングと `完了N` 操作の整合を追加
- 競合確認後の登録でもタグを引き継げるよう対応
- ヘルプにタグ機能の案内を追加

### Changed
- `次` / `前` が `tag` モードでも動作するよう、`last_list_mode = tag` と `last_list_tag` を用いた状態管理を追加
- タスク登録・競合確認ログに `tag` フィールドを追加
　
---

## v1.1.22-dev - 2026-03-21

### Added
- 時刻付きタスク登録時、同日30分以内に既存タスクがある場合に確認メッセージを表示する機能を追加
- 確認後「登録する」で保存、「やめる」でキャンセルするクイックリプライ対応を追加

### Changed
- 競合確認中のタスク情報を `conv_state` に保持し、確認後に登録または破棄できるよう調整

---

## v1.1.21-dev - 2026-03-21

### Added
- 自然言語タスク登録ログに `raw_text`・`date_pattern`・`time_pattern` を追加
- 空タイトルで保存を拒否した際に `task parse rejected` ログを追加
- 保存成功時に `task parse result` / `task parse fallback` ログを追加し、通常保存と fallback 保存を判別できるよう改善

---

## v1.1.20-dev - 2026-03-21

### Fixed
- 「今日夜」「明日夕方」のように、日付語の後ろが時間帯ラベルだけの入力でタスク内容がない場合、内容入力エラーを返すよう修正
- 食事語（夕飯・ランチ等）はこの判定対象に含めず、タイトルに残るよう維持

---

## v1.1.19-dev - 2026-03-21

### Fixed
- 「今日夕飯はしんぱちにする」のような入力で、「夕飯」を時間帯ラベルとして誤って吸収していた問題を修正
- 食事系の語を含む入力で、タイトルから「夕飯」が消えないよう自然言語パースを調整

---

## v1.1.18-dev - 2026-03-21

### Fixed
- 「明日17時に実家」「今日20時10分ラインチェック」のように、日付語の直後にセパレータなしで時刻が続く入力でも、due_date・due_time・title を正しく分離できるよう修正

---

## v1.1.17-dev - 2026-03-21

### Fixed
- 「今日夕飯はしんぱち」「明日昼は打ち合わせ」のように、日付語の直後に時間帯ラベル（朝・昼・夕方・夜・夕飯）が来る入力でも、due_date と title を正しく分離できるよう自然言語パースを改善
- 1字ラベル（朝・昼・夜）は `は` を必須とし、「朝活」などの誤検知を防ぐよう調整

---

## v1.1.16-dev - 2026-03-21

### Improved
- 「3月31日銀行処理」のような助詞なし入力でも、日付とタイトルを正しく分離できるよう改善
- 「3月31日は」「3月31日から」などの助詞付きパターンの解析精度を向上

---

## v1.1.15-dev - 2026-03-21

### Added
- `期限なし` / `未定` コマンドを追加し、期限なしタスクのみを一覧表示できるよう改善
- 期限なしタスク一覧に `次` / `前` によるページング対応を追加
- 期限なし専用クイックリプライ（`build_nodue_quick_reply()`）を新設し、一覧・今日へ戻れるよう導線を調整

---

## v1.1.14-dev - 2026-03-21

### Changed
- 「今日」コマンドでタスク0件時の文言を改善し、次の行動（タスク追加）を促す案内を追加

---

## v1.1.13-dev - 2026-03-21

### Changed
- オンボーディング文を改善し、登録→確認→完了の流れが最初に分かるよう調整
- フォローアップ文を3ステップ形式に変更し、初回利用導線を明確化
- 朝・昼通知の末尾に完了導線を追加し、通知から次の行動へ繋がるよう改善
- `LineService::pushMessage()` に quickReply オプションを追加

### Added
- 朝・昼通知に完了クイックリプライを追加（今日タスクが存在する場合のみ）

---

## v1.1.12-dev - 2026-03-21

### Changed
- ヘルプ文面を刷新し、機能一覧型から「最短で使い始められる」行動ベースの構成へ改善
- タスク登録・確認・完了の基本操作を①〜⑤の手順で整理
- 不要な機能説明（削除・履歴・戻す・brief）を削除し、初回ユーザー向けに簡素化

---

## v1.1.11-dev - 2026-03-20

### Added
- 「今日」コマンドの返信に、完了1〜完了3と一覧へのクイックリプライを追加

### Changed
- 一覧表示時のクイックリプライを「次 / 前 / 今日」に整理し、操作を簡潔に調整
- 「今日」コマンドで due_time があるタスクに時刻も表示するよう改善
- 完了コマンドで `完了1` のようなスペースなし入力も受け付けるよう改善

---

## v1.1.10-dev - 2026-03-20

### Fixed
- 「21時半で作業終わりにする」のような入力で、時間直後の「で」がタイトル先頭に残る問題を修正

### Changed
- タスク完了後の返信文を改善し、全体残件数ではなく今日の残り状況が分かるよう調整
- 今日の未完了タスク件数を取得する `countTodayOpenTasksByOwner()` を追加

---

## v1.1.9-dev - 2026-03-20

### Changed
- タスク完了時の返信文を改善し、完了したタスクと残件数が自然に分かるよう調整
- 残件数が0件の場合は「今のタスクは0件です。」と表示するよう改善

---

## v1.1.8-dev - 2026-03-20

### Changed
- 「今日」コマンドの挨拶を時間帯に応じて出し分けるよう改善
  05:00〜10:59: おはようございます
  11:00〜17:59: こんにちは
  18:00〜04:59: こんばんは

---

## v1.1.7-dev - 2026-03-20

### Added
- 一覧 / 次 / 前 の返信にクイックリプライを追加
- ページ状態に応じて「次 / 前 / 今日 / 一覧 / ヘルプ」を出し分けるよう改善

### Changed
- line_reply() に省略可能な quickReply 引数を追加し、一覧表示時のみクイックリプライを付与できるよう調整

---

## v1.1.6-dev - 2026-03-20

### Fixed
- inline fallback のパターン順を修正し、HH:MM を `時` より前に判定するよう調整
- タイトル生成時の時間除去を `preg_replace(..., 1)` に変更し、最初の一致だけ除去するよう修正

---

## v1.1.5-dev - 2026-03-20

### Fixed
- `20時5分` / `20時10分` / `9時05分` などの日本語「時 + 分」形式で、due_time が取得できない問題を修正

### Changed
- 自然言語登録の時間抽出で、日本語の「時 + 分」形式を `時` / `時半` より優先して判定するよう調整
- 時間前アラートの時刻判定で、日本語の「時 + 分」形式を扱えるよう改善

---

## v1.1.4-dev - 2026-03-20

### Fixed
- 「19時まで仕事」「13:30まで会議」のような「まで」付き時刻表現で、due_time が取得されない問題を修正

---

## v1.1.3-dev - 2026-03-20

### Added
- due_time がある当日タスクについて、予定時刻の10分前に送る時間前アラートを追加
- `cron/push_pre_alert.php` を追加
- `TaskRepository::getTodayAlertTasksByOwner()` を追加

### Changed
- conv_state を使って予定前アラートの二重送信を防ぐように調整
- 送信済み task_id を日付ごとに管理し、日付が変わると自動でリセットするよう調整

---

## v1.1.2-dev - 2026-03-20

### Added
- `3月20日` / `3/20` のような日付単独入力で、その日の予定を確認できる指定日検索を追加
- TaskRepository に `getOpenTasksByDate(int $ownerId, string $date): array` を追加

### Changed
- 日付単独入力を内容不足エラーではなく検索として扱うよう変更

---

## v1.1.1-dev - 2026-03-20

### Fixed
- 「20時に歯医者」のような日付なし・時刻あり入力で、due_time が保存されているにもかかわらず登録確認や一覧に表示されない問題を修正

### Changed
- 日付なし・時刻ありの登録確認で「時刻：XX時」を表示するよう改善
- 一覧の「期限なし」グループで、due_time があるタスクに時刻を表示するよう改善

### Added
- 保存直前の `task parse result` 診断ログを追加

---

## v1.1.0-dev - 2026-03-20

### Added
- 日付形式に M/D（3/20 形式）を追加
- 「3月20日の打ち合わせ」のように `の` で繋ぐ入力に対応
- 「今日 歯医者 13時」のようなタイトル＋時間の語順に対応（日付あり時のみ）

### Fixed
- 「3月20日」単体入力が誤ってタイトルとして保存される問題を修正
- 時間直後の「の」（例: 「13時の歯医者」）がタイトル先頭に残る問題を修正

### Changed
- 日付パース順を具体 → 汎用の順に整理（M月D日 / M/D → 今日 / 明日）
- バリデーションエラー返答に入力例を追加

---

## v1.0 - 2026-03-20

### Added
- LINEリッチメニューを追加（今日 / 一覧 / ヘルプ）
- 初回オンボーディングメッセージを実装
- 未操作ユーザーへのフォローアップ通知（cron）を実装

### Changed
- 一覧表示を最大5件に整理し、1ページ目の情報量を削減
- 一覧の表示順を「今日 → 今後の予定 → 期限なし」に最適化
- 一覧ヘッダーを「未完了タスク一覧（N/M）」形式に統一
- 日付表示を「M/D」形式に簡略化
- 一覧の末尾に完了・次ページへの行動導線を追加

### Fixed
- ページング時の表示・番号ズレの潜在的な不整合を修正

---

## v0.35 - 2026-03-20

### Changed
- 一覧表示を最大5件に整理し、1ページ目の情報量を削減
- 一覧の見出しを「今日」「今後の予定」「期限なし」で分かりやすく調整
- 一覧ヘッダーを「未完了タスク一覧（N/M）」形式に統一
- 一覧の末尾に完了・次ページへの行動導線を追加

---

## v0.34 - 2026-03-20

### Fixed
- 「今日 13時からカフェ作業」のような入力で、時間抽出後のタイトル先頭に「から」が残る問題を修正
- 「13時から請求書確認」のような日付なし時刻入力でも、時間を正しく抽出できるよう改善

---

## v0.33.1 - 2026-03-20

### Internal
- cron/push_onboarding_followup.php に全条件の診断ログを追加
  skip理由・push試行前後・集計を出力するようにした（条件変更なし）

---

## v0.33 - 2026-03-19

### Added
- 友だち追加時に送るオンボーディングメッセージを追加
- followイベントでユーザー登録とonboarding状態のconv_state保存を追加
- タスク未登録ユーザーに1時間後フォローアップを1回だけ送るcronを追加

---

## v0.32 - 2026-03-19

### Added
- 未完了タスク一覧のページ送り機能を追加
- 「次」「前」で11件目以降のタスクを確認できるよう改善

### Changed
- 一覧表示を10件単位に整理し、ページ番号を表示するよう変更
- 完了番号がページを跨いでも通し番号で使えるよう維持

---

## v0.31 - 2026-03-19

### Fixed
- 「3月27日 家賃引落確認」のようなM月D日形式の入力が期限なしとして登録されていた問題を修正
- M月D日形式の入力からdue_dateを当年日付として正しく保存するよう改善

### Changed
- タスク登録確認メッセージで、時間未設定でも期限日を表示するよう変更

---

## v0.30 - 2026-03-18

### Changed
- 昼通知に時間情報を追加し、今やるべきタスクが判断しやすいよう改善
- 昼通知の末尾メッセージを見直し、行動につながる表現に調整

---

## v0.29 - 2026-03-18

### Changed
- 昼通知の表示を最大3件に制限し、行動しやすい形に改善
- 昼通知では今日のタスクと期限なしタスクのみを対象にし、未来タスクを除外
- 今日の時間指定タスクが時刻順で並ぶよう調整
- 朝通知の文言を「まずは『1』から進めてください」に変更

---

## v0.28 - 2026-03-18

### Added
- 昼のタスクリマインド通知を追加
- 明日の予定がある場合に送る前日リマインド通知を追加

### Changed
- 朝のbrief通知を、件数と一覧が分かる形式に改善
- 明日のタスク取得処理でdue_timeを扱えるようにし、時間ありタスクが先に並ぶよう調整
- 通知文面を自然で簡潔な日本語に調整
い

## v0.27 - 2026-03-18

### Added
- 完了後の残件数表示用にcountOpenTasksByOwner()を追加

### Changed
- タスク一覧を「今日 / 明日 / その他 / 期限なし」で整理し、見やすさを改善
- 時間あり・時間なしタスクを自然に表示できるよう一覧表示を調整
- 完了メッセージを「✅ 完了：タイトル（期限）」形式に改善
- 完了後に残りタスク件数を表示するよう変更

---

## v0.25.2 - 2026-03-18

### Changed
- エラーメッセージから内部用語「open task」を削除
- 「該当する open task が見つかりません」→「該当するタスクが見つかりません」に修正

### Result
ユーザーにとって自然な日本語表現に改善

---

## v0.26-due-time-display

### Changed
- open task list now includes due_date / due_time context when available
- task creation confirmation now shows due date and time when available

### Result
Users can more easily understand when time-based tasks are scheduled

---

## v0.25.1-task-save-fallback-fix

### Fixed
- prevented fallback response after successful task creation
- task creation now replies with a registration confirmation
- isCommand narrowed to pure non-task commands only
- /ping and brief toggle handlers moved before task-save flow

### Result
Natural task input now registers cleanly without falling through to echo-style fallback responses

---

## v0.25-input-normalization

### Improved
- normalized user input (trim + whitespace normalization)
- added validation messages for incomplete task commands
- improved fallback guidance for unknown input

### Result
Users can interact more naturally without polluting tasks or triggering ambiguous command matches

---

## v0.24-brief-quiet-mode

### Improved
- morning brief push is skipped when there are no today tasks and no undated open tasks

### Result
Users no longer receive empty morning brief notifications

---

## v0.23-onboarding-message

### Improved
- follow event message updated with onboarding guide

### Result
New users can start using Fujira Manager without external instructions

---

## v0.22-help-command

### Added
- LINE command `help` / `ヘルプ` / `/help`

### Result
Users can now check the basic usage of Fujira Manager directly from LINE

---

## v0.21-brief-ordering

### Improved
- today tasks in brief are now ordered with due_time tasks first
- due_time is now shown in today brief output when available

### Result
Daily brief is easier to scan and closer to real execution order

---

## v0.20-task-due-time

### Added
- tasks.due_time column
- minimal time parsing for inputs such as `23時`, `23時半`, `13:30`

### Fixed
- titles are normalized after time extraction to avoid unnatural leading particles

### Result
Users can now create tasks with lightweight due-time information while staying in the task model

---

## v0.19.1-date-prefix-empty-guard

### Fixed
- date-prefix-only inputs such as `今日は`, `今日の`, `明日は`, `明日の` are now rejected safely

### Result
Prefix-only inputs no longer fall through to normal task creation

---

## v0.19-natural-date-prefix

### Improved
- due_date parsing now supports natural prefixes such as `今日の〜`, `今日は〜`, `明日の〜`, `明日は〜`

### Fixed
- empty titles after date-prefix input are rejected safely

### Result
Users can enter date-prefixed tasks in more natural Japanese

---

## v0.18-brief-toggle

### Added
- users.brief_enabled column
- UserRepository::getAllBriefEnabledUsers(): array
- UserRepository::updateBriefEnabled(int $ownerId, int $enabled): void
- LINE commands `brief on` / `brief off` / `ブリーフオン` / `ブリーフオフ`

### Changed
- morning brief cron now targets only users with brief_enabled = 1

### Result
Users can now control whether they receive morning brief pushes

---

## v0.17-morning-brief-push

### Added
- UserRepository::getAllUsers(): array
- LineService::pushMessage(string $lineUserId, string $message): void
- cron/push_morning_brief.php

### Result
Users can now receive automatic morning brief messages via LINE Push

---

## v0.16-brief-view

### Added
- TaskRepository: getNoDueDateTasksByOwner(int $ownerId): array
- webhook: `/brief` / `ブリーフ` で今日の期限タスク＋未期限タスクをまとめて表示するbrief機能を追加

### Changed
- webhook: `/brief` のテスト用スタブを本実装に置き換え
- webhook: isCommandに`ブリーフ`を追加

### Result
Users can now review a compact daily brief from LINE

---

## v0.13.1-due-date-space-fix

### Fixed
- `今日 XXX` / `明日 XXX` due_date parsing now supports full-width spaces

### Result
Tasks entered with Japanese full-width spacing can now be saved with due_date correctly

---

## v0.15-tomorrow-tasks

### Added
- LINE command `明日` / `/tomorrow`
- `TaskRepository::getTomorrowTasksByOwner(int $ownerId, string $tomorrow): array`

### Result
Users can now view open tasks due tomorrow directly from LINE

---

## v0.14-today-tasks

### Added
- LINE command `今日` / `/today`
- `TaskRepository::getTodayTasksByOwner(int $ownerId, string $today): array`

### Result
Users can now view open tasks due today directly from LINE

---

## v0.13-task-due-date

### Added
- support for `今日` / `明日` prefix to set due_date automatically
- due_date is now stored when creating tasks

### Changed
- TaskRepository::create() now accepts optional due_date

### Result
Users can create tasks with implicit due dates using natural input

---

## v0.12-task-undo

### Added
- LINE command `戻す {n}` / `/undo {n}`
- `TaskRepository::reopenTaskById(int $ownerId, int $taskId): ?array`
- `last_done_task_list_map` storage in conv_state for history-based undo

### Result
Users can restore completed tasks back to open state from history

---

## v0.11-task-history

### Added
- LINE command `履歴` / `/history`
- `TaskRepository::getDoneTasksByOwner(int $ownerId): array`

### Result
Users can now review recently completed tasks from LINE

---

## v0.10-stability-hardening

### Improved
- stronger validation for task completion and deletion commands
- repository update/delete operations now verify affected rows
- failure logging improved for command resolution and DB operations

### Result
Task operations are now safer and easier to debug without changing user-facing behavior

---

## [v0.9]

### Changed
- webhook: 一覧表示を「open タスクのみ」に統一
- webhook: 一覧0件時の文言を「現在 open のタスクはありません」に変更

### Confirmed
- TaskRepository::getOpenTasksByOwner() が status='open' で正しく絞り込みされていることを確認
- last_task_list_map が open タスクのみを対象に構築されていることを確認

### UX
- 一覧 / 完了 / 削除 の対象が完全一致するようになり、操作の直感性を改善

---

## v0.8-task-delete

### Added
- LINE command `削除 {n}` / `/delete {n}` for deleting open tasks
- `TaskRepository::deleteOpenTaskById(int $ownerId, int $taskId): ?array`

### Fixed
- delete commands are excluded from task storage

### Improved
- task deletion now respects open status only

### Result
Users can remove unnecessary open tasks directly from LINE

---

## v0.7.1-conv-state-save-fix

### Fixed
- `ConvStateRepository::saveState()` rewritten from UPSERT syntax to explicit SELECT + INSERT/UPDATE
- added debug logs for task list map saving
- added debug logs for task completion state loading and task id resolution

### Result
Numbered task list state can now be stored reliably in `conv_state`, improving `完了 {n}` / `/done {n}` handling

---

## v0.7-list-number-complete

### Added
- numbered task list output
- number-to-task-id mapping stored in conv_state
- `完了 {n}` / `/done {n}` resolves list numbers

### Fixed
- conv_state save logic stabilized
- added debug logs for state save/load and task resolution
- command messages excluded from task storage

### Result
Users can complete tasks naturally from LINE after viewing task list

---

## v0.6-task-complete

### Added
- LINE command `完了 {id}` / `/done {id}`
- `TaskRepository::completeOpenTaskById(int $ownerId, int $taskId): ?array`

### Result
Users can now mark their own open tasks as done from LINE

---

## v0.5-task-list

### Added
- LINE command `一覧` / `/list` for open task listing
- `TaskRepository::getOpenTasksByOwner(int $ownerId): array`

### Result
Users can now review their current open tasks from LINE

---

## v0.4-task-pipeline-complete

### Added
- TaskRepository create() implementation
- webhook integration for saving LINE text messages

### Fixed
- implemented UserRepository::create()
- removed legacy owner_id from users schema

### Changed
- normalized owner_id to users.id (INT UNSIGNED)
- rebuilt canonical schema.sql

### Result
- LINE message → user registration → task storage pipeline is fully functional

---

## v0.4.3-schema-rebuild

### Changed
- rebuilt canonical `schema.sql` from scratch

### Design
- `users.id` is the internal owner_id
- all owner-scoped tables now use `owner_id INT UNSIGNED`
- existing `tasks` structure is preserved

### Result
- database structure is now aligned with current Fujira Manager architecture

---

## v0.4.2-users-schema-fix

### Fixed
- removed legacy `owner_id` design from `users` table
- standardized internal owner model around `users.id`

### Result
- `users.id` is now the single internal owner_id
- `line_user_id` remains the external LINE identifier

---

## v0.4.1-user-registration-fix

### Fixed
- implemented missing `UserRepository::create()`

### Result
- LINE users can now be auto-registered correctly
- owner_id resolution can proceed through `users.id`

---

## v0.4-task-storage

### Added
- tasks table
- TaskRepository (create)
- webhook integration for saving tasks

### Result
- LINE messages are now persisted as tasks
- first functional step toward AI secretary

---

## v0.3-user-foundation

### Added
- LINE webhook integration (message receive + reply)
- UTF-8 safe webhook handling (json_encode fix)
- logging system (webhook_log)
- bootstrap loader (config + autoload integration)
- Database connection layer (PDO wrapper)
- UserRepository
  - findByLineUserId()
  - create()
- automatic user registration from LINE webhook

### Changed
- webhook.php refactored to use bootstrap.php
- user identification model redesigned

### Design
- users.id is the internal owner_id (INT)
- line_user_id is treated as external identifier
- owner_id column removed from users table

### Result
- LINE user → internal owner_id mapping established
- foundation for task / memo / schedule ownership completed

---

## v0.2-infrastructure

### Added
- config.php (app / db / line / paths)
- schema.sql (users table)
- basic directory structure
  - app/
  - Storage/
  - logs/

### Fixed
- LINE webhook 400 error ("Could not read the request body")
- json_encode failure (UTF-8 encoding issue)

### Result
- stable webhook communication with LINE
- server-side logging enabled
- DB connectivity verified

---

## v0.1-initial-setup

### Added
- initial project setup
- simple webhook endpoint
- basic LINE reply (echo response)
- deployment to Sakura server

### Result
- LINE → server → response loop confirmed