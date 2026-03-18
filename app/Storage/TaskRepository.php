<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

use PDO;

final class TaskRepository
{
    public function __construct(private Database $db) {}

    public function getOpenTasksByOwner(int $ownerId, string $today, string $tomorrow): array
    {
        $sql = 'SELECT id, title, due_date, due_time FROM tasks WHERE owner_id = :owner_id AND status = :status ORDER BY CASE WHEN due_date IS NULL THEN 3 WHEN due_date = :today THEN 0 WHEN due_date = :tomorrow THEN 1 ELSE 2 END, due_date ASC, CASE WHEN due_time IS NULL OR due_time = \'\' THEN 1 ELSE 0 END, due_time ASC, id DESC LIMIT 10';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open', 'today' => $today, 'tomorrow' => $tomorrow]);
        return $stmt->fetchAll();
    }

    public function completeOpenTaskById(int $ownerId, int $taskId): ?array
    {
        $selectSql = 'SELECT id, title, due_date, due_time FROM tasks WHERE id = :id AND owner_id = :owner_id AND status = :status LIMIT 1';
        $stmt = $this->db->pdo()->prepare($selectSql);
        $stmt->execute(['id' => $taskId, 'owner_id' => $ownerId, 'status' => 'open']);
        $task = $stmt->fetch();
        if ($task === false) {
            return null;
        }

        $updateSql = 'UPDATE tasks SET status = :status, completed_at = NOW(), updated_at = NOW() WHERE id = :id AND owner_id = :owner_id AND status = :current_status';
        $stmt = $this->db->pdo()->prepare($updateSql);
        $stmt->execute(['status' => 'done', 'id' => $taskId, 'owner_id' => $ownerId, 'current_status' => 'open']);

        // SELECT後でも並行更新等で更新件数が0になる場合は失敗扱い
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $task;
    }

    public function deleteOpenTaskById(int $ownerId, int $taskId): ?array
    {
        $selectSql = 'SELECT id, title FROM tasks WHERE id = :id AND owner_id = :owner_id AND status = :status LIMIT 1';
        $stmt = $this->db->pdo()->prepare($selectSql);
        $stmt->execute(['id' => $taskId, 'owner_id' => $ownerId, 'status' => 'open']);
        $task = $stmt->fetch();
        if ($task === false) {
            return null;
        }

        $deleteSql = 'DELETE FROM tasks WHERE id = :id AND owner_id = :owner_id AND status = :status';
        $stmt = $this->db->pdo()->prepare($deleteSql);
        $stmt->execute(['id' => $taskId, 'owner_id' => $ownerId, 'status' => 'open']);

        // SELECT後でも並行更新等で削除件数が0になる場合は失敗扱い
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $task;
    }

    public function reopenTaskById(int $ownerId, int $taskId): ?array
    {
        $selectSql = 'SELECT id, title FROM tasks WHERE id = :id AND owner_id = :owner_id AND status = :status LIMIT 1';
        $stmt = $this->db->pdo()->prepare($selectSql);
        $stmt->execute(['id' => $taskId, 'owner_id' => $ownerId, 'status' => 'done']);
        $task = $stmt->fetch();
        if ($task === false) {
            return null;
        }

        $updateSql = 'UPDATE tasks SET status = :status, completed_at = NULL, updated_at = NOW() WHERE id = :id AND owner_id = :owner_id AND status = :current_status';
        $stmt = $this->db->pdo()->prepare($updateSql);
        $stmt->execute(['status' => 'open', 'id' => $taskId, 'owner_id' => $ownerId, 'current_status' => 'done']);

        // SELECT後でも並行更新等で更新件数が0になる場合は失敗扱い
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $task;
    }

    public function getNoDueDateTasksByOwner(int $ownerId): array
    {
        $sql = 'SELECT id, title FROM tasks WHERE owner_id = :owner_id AND status = :status AND due_date IS NULL ORDER BY id DESC LIMIT 5';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open']);
        return $stmt->fetchAll();
    }

    public function getTodayTasksByOwner(int $ownerId, string $today): array
    {
        $sql = 'SELECT id, title, due_date, due_time FROM tasks WHERE owner_id = :owner_id AND status = :status AND due_date = :due_date ORDER BY CASE WHEN due_time IS NULL OR due_time = \'\' THEN 1 ELSE 0 END, id DESC LIMIT 10';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open', 'due_date' => $today]);
        return $stmt->fetchAll();
    }

    public function getTomorrowTasksByOwner(int $ownerId, string $tomorrow): array
    {
        $sql = 'SELECT id, title, due_date, due_time FROM tasks WHERE owner_id = :owner_id AND status = :status AND due_date = :due_date ORDER BY CASE WHEN due_time IS NULL OR due_time = \'\' THEN 1 ELSE 0 END, due_time ASC, id DESC LIMIT 10';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open', 'due_date' => $tomorrow]);
        return $stmt->fetchAll();
    }

    public function getDoneTasksByOwner(int $ownerId): array
    {
        $sql = 'SELECT id, title, completed_at FROM tasks WHERE owner_id = :owner_id AND status = :status ORDER BY completed_at DESC, id DESC LIMIT 10';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'done']);
        return $stmt->fetchAll();
    }

    public function countOpenTasksByOwner(int $ownerId): int
    {
        $sql = 'SELECT COUNT(*) FROM tasks WHERE owner_id = :owner_id AND status = :status';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open']);
        return (int) $stmt->fetchColumn();
    }

    public function create(int $ownerId, string $title, ?string $dueDate = null, ?string $dueTime = null): int
    {
        $sql = 'INSERT INTO tasks (owner_id, title, status, source, due_date, due_time) VALUES (:owner_id, :title, :status, :source, :due_date, :due_time)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'owner_id' => $ownerId,
            'title'    => $title,
            'status'   => 'open',
            'source'   => 'line',
            'due_date' => $dueDate,
            'due_time' => $dueTime,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
