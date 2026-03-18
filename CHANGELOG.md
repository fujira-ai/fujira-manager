# CHANGELOG

All notable changes to Fujira Manager will be documented in this file.

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
- `tasks.due_time` column
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
- `users.brief_enabled` column
- `UserRepository::getAllBriefEnabledUsers(): array`
- `UserRepository::updateBriefEnabled(int $ownerId, int $enabled): void`
- LINE commands `brief on` / `brief off` / `ブリーフオン` / `ブリーフオフ`

### Changed
- morning brief cron now targets only users with `brief_enabled = 1`

### Result
Users can now control whether they receive morning brief pushes

---

## v0.17-morning-brief-push

### Added
- `UserRepository::getAllUsers(): array`
- `LineService::pushMessage(string $lineUserId, string $message): void`
- `cron/push_morning_brief.php`

### Result
Users can now receive automatic morning brief messages via LINE Push

---

## v0.16-brief-view

### Added
- TaskRepository: `getNoDueDateTasksByOwner(int $ownerId): array`
- webhook: `/brief` / `ブリーフ` で今日の期限タスク＋未期限タスクをまとめて表示する brief 機能を追加

### Changed
- webhook: `/brief` のテスト用スタブを本実装に置き換え
- webhook: isCommand に `ブリーフ` を追加

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