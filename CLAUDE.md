# CLAUDE.md

## Project
Fujira Manager

## Goal
Build a server-based AI secretary for LINE on shared hosting using PHP + MySQL.

Current direction:
- Primary UI: LINE
- Runtime: PHP on Sakura shared server
- Storage: MySQL
- Base path: /fujira-manager/
- Future: SaaS-ready architecture with owner_id isolation

---

## Core Principles

### 1. Keep architecture stable
This project must follow the fixed app structure below:

- `app/Core` = business logic / AI engines
- `app/Storage` = MySQL access only
- `app/Services` = external integration / execution layer
- `app/Helpers` = stateless utility helpers
- `webhook.php` = thin entrypoint only

Do not introduce new top-level architecture patterns unless explicitly requested.

### 2. Never mix responsibilities
- Do not write SQL outside `app/Storage`
- Do not put business logic in `webhook.php`
- Do not put LINE API calls outside `app/Services/LineService.php`
- Do not access DB directly from `Core`
- Do not add utility logic into `config.php`

### 3. Minimize change surface
When implementing a feature:
- prefer the smallest safe change
- preserve existing behavior unless the requested feature explicitly changes it
- keep backward compatibility whenever practical

### 4. Respect owner isolation
All user data must be scoped by `owner_id`.

This is mandatory for:
- tasks
- memos
- schedules
- memories
- conv_state
- conversations
- any future per-user state

### 5. Priorities must remain dominant
Memory / recent-window / bias logic must never override strong priority signals.

Bias is allowed only as:
- weak tie-break
- close-score adjustment
- preference hint

Bias must not override:
- overdue
- due today
- explicit priority
- hard safety rules

---

## Directory Rules

### `app/Core`
Put business logic here.

Examples:
- `AiSecretary.php`
- `PriorityEngine.php`
- `RiskEngine.php`
- `SuggestionEngine.php`
- `ActionPlanEngine.php`
- `FirstMoveEngine.php`
- `FirstFiveEngine.php`
- `MomentumEngine.php`
- `EnergyEngine.php`
- `MemoryEngine.php`
- `ContextWindowEngine.php`
- `DebugEngine.php`
- `ResetEngine.php`

Rules:
- Core may call Storage and Helpers
- Core may return arrays / strings / structured results
- Core must not contain raw SQL

### `app/Storage`
Put persistence logic here.

Examples:
- `Database.php`
- `UserStorage.php`
- `TaskStorage.php`
- `MemoStorage.php`
- `ScheduleStorage.php`
- `MemoryStorage.php`
- `ConvStateStorage.php`
- `ConversationStorage.php`

Rules:
- SQL lives here only
- Use PDO
- Keep methods small and explicit
- Use `owner_id` filters

### `app/Services`
Put external services / execution bridges here.

Examples:
- `WebhookService.php`
- `LineService.php`
- `PushService.php`
- `CronService.php`

Rules:
- Services call Core / Storage as needed
- Services may call external APIs
- Services should not duplicate business rules from Core

### `app/Helpers`
Put stateless utility functions here.

Examples:
- `DateHelper.php`
- `TextHelper.php`
- `TaskTypeHelper.php`
- `ArrayHelper.php`

Rules:
- no DB
- no API calls
- no global mutable state

### `app/config.php`
Config only.
Return a config array.
Do not put logic here.

### `app/bootstrap.php`
Bootstrapping only.
Allowed:
- load config
- timezone setup
- require class files / autoload
- initialize shared dependencies

Not allowed:
- task logic
- webhook logic
- reply logic

---

## Naming Conventions

### PHP
- Classes: `PascalCase`
- Methods: `camelCase`
- Variables: `camelCase`

### Database
- Tables: `snake_case`
- Columns: `snake_case`

Examples:
- PHP: `$ownerId`, `getOpenTasksByOwner()`
- DB: `owner_id`, `created_at`, `completed_at`

### File names
File name must match class name when using one class per file.

Examples:
- `TaskStorage.php`
- `LineService.php`
- `PriorityEngine.php`

---

## Config Rules

Use array-return config style.

Example:
```php
$config = require __DIR__ . '/config.php';
$secret = $config['line']['channel_secret'];