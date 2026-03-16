<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

use PDO;

final class TaskRepository
{
    public function __construct(private Database $db) {}

    public function getOpenTasksByOwner(string $ownerId): array
    {
        $sql = 'SELECT * FROM tasks WHERE owner_id = :owner_id AND status = :status ORDER BY due_date IS NULL, due_date ASC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'open']);
        return $stmt->fetchAll();
    }
}
