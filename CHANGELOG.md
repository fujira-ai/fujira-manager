# CHANGELOG

All notable changes to Fujira Manager will be documented in this file.

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