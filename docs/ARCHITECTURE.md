---

# 2. `docs/ARCHITECTURE.md`

こちらは **人間向けの正式設計書** です。  
淳さんと私の共通認識用です。

```md
# Fujira Manager Architecture v0.1

## Overview
Fujira Manager is a LINE-first AI secretary system designed to run on PHP + MySQL under shared hosting.

Deployment target:
- `https://fujira.tokyo/fujira-manager/`

Main runtime goals:
- stable LINE webhook handling
- cron-based push notifications
- owner-scoped data separation
- future SaaS readiness

---

## System Positioning

The project started as a CLI-based AI secretary prototype.

It is now evolving into:
- LINE-based AI secretary
- server-hosted always-available assistant
- behavior-aware task and action support system

CLI remains useful as:
- R&D environment
- behavior engine testbed
- debugging reference

Production direction is:
- PHP
- MySQL
- LINE webhook
- cron-based automation

---

## Architectural Layers

### 1. Interface Layer
Handles incoming and outgoing communication.

Examples:
- `webhook.php`
- LINE reply / push
- admin screens
- future web UI

Responsibilities:
- receive user input
- pass data into services/core
- render outputs
- never hold core business rules

### 2. Service Layer
Bridges external systems and internal core logic.

Examples:
- `WebhookService`
- `LineService`
- `PushService`
- `CronService`

Responsibilities:
- call LINE API
- orchestrate request flow
- dispatch work to Core/Storage
- isolate integration details

### 3. Core Layer
Holds AI secretary logic and decision engines.

Examples:
- Priority
- Risk
- Suggestion
- Action Plan
- First Move
- First Five Minutes
- Momentum
- Energy
- Memory Context
- Context Window
- Debug / Explainability
- Reset logic

Responsibilities:
- determine behavior
- generate suggestions
- apply memory / bias safely
- stay storage-independent

### 4. Storage Layer
Responsible for persistence only.

Responsibilities:
- SQL
- CRUD
- owner-based filtering
- retrieving and saving state

Storage must not:
- interpret business logic
- generate user-facing advice

### 5. Helper Layer
Pure reusable helper functions.

Examples:
- date utilities
- text normalization
- task type classification
- array formatting helpers

---

## Directory Structure

```text
fujira-manager/
├─ index.php
├─ webhook.php
├─ app/
│  ├─ bootstrap.php
│  ├─ config.php
│  ├─ Core/
│  ├─ Helpers/
│  ├─ Services/
│  └─ Storage/
├─ cron/
├─ admin/
└─ logs/