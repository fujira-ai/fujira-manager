<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class MemoryRepository
{
    public function __construct(private Database $db) {}

    public function getByOwnerAndType(string $ownerId, string $type): array
    {
        $sql = 'SELECT * FROM memories WHERE owner_id = :owner_id AND type = :type ORDER BY id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'type' => $type]);
        return $stmt->fetchAll();
    }
}
