<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class ConversationRepository
{
    public function __construct(private Database $db) {}

    public function append(string $ownerId, string $role, string $message): void
    {
        $sql = 'INSERT INTO conversations (owner_id, role, message, created_at) VALUES (:owner_id, :role, :message, NOW())';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'owner_id' => $ownerId,
            'role' => $role,
            'message' => $message,
        ]);
    }
}
