<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

use PDO;

final class TaskRepository
{
    public function __construct(private Database $db) {}

    public function getOpenTasksByOwner(int $ownerId): array
    {
        $sql = 'SELECT id, title FROM tasks WHERE owner_id = :owner_id AND status = :status ORDER BY created_at DESC LIMIT 10';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open']);
        return $stmt->fetchAll();
    }

    public function completeOpenTaskById(int $ownerId, int $taskId): ?array
    {
        $selectSql = 'SELECT id, title FROM tasks WHERE id = :id AND owner_id = :owner_id AND status = :status LIMIT 1';
        $stmt = $this->db->pdo()->prepare($selectSql);
        $stmt->execute(['id' => $taskId, 'owner_id' => $ownerId, 'status' => 'open']);
        $task = $stmt->fetch();
        if ($task === false) {
            return null;
        }

        $updateSql = 'UPDATE tasks SET status = :status, completed_at = NOW(), updated_at = NOW() WHERE id = :id AND owner_id = :owner_id AND status = :current_status';
        $stmt = $this->db->pdo()->prepare($updateSql);
        $stmt->execute(['status' => 'done', 'id' => $taskId, 'owner_id' => $ownerId, 'current_status' => 'open']);

        return $task;
    }

    public function create(int $ownerId, string $title): int
    {
        $sql = 'INSERT INTO tasks (owner_id, title, status, source) VALUES (:owner_id, :title, :status, :source)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'owner_id' => $ownerId,
            'title'    => $title,
            'status'   => 'open',
            'source'   => 'line',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
