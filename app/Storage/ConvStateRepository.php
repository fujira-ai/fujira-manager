<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class ConvStateRepository
{
    public function __construct(private Database $db) {}

    public function getState(int $ownerId): array
    {
        $sql = 'SELECT state_json FROM conv_state WHERE owner_id = :owner_id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }
        $decoded = json_decode((string)$row['state_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveState(int $ownerId, array $state): void
    {
        $sql = 'INSERT INTO conv_state (owner_id, state_json) VALUES (:owner_id, :state_json)
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'owner_id'   => $ownerId,
            'state_json' => json_encode($state, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
