# CHANGELOG

All notable changes to Fujira Manager will be documented in this file.

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