<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class ConvStateRepository
{
    public function __construct(private Database $db) {}

    public function getState(string $ownerId): ?array
    {
        $sql = 'SELECT state_json FROM conv_state WHERE owner_id = :owner_id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId]);
        $row = $stmt->fetch();
        return $row ? json_decode((string)$row['state_json'], true) : null;
    }
}
