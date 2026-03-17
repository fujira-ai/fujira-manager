<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class UserRepository
{
    public function __construct(private Database $db) {}

    public function findByLineUserId(string $lineUserId): ?array
    {
        $sql = 'SELECT * FROM users WHERE line_user_id = :line_user_id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['line_user_id' => $lineUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAllUsers(): array
    {
        $sql = 'SELECT id, line_user_id FROM users WHERE line_user_id IS NOT NULL AND line_user_id <> \'\' ORDER BY id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(string $lineUserId, ?string $displayName = null): int
    {
        $sql = 'INSERT INTO users (line_user_id, display_name) VALUES (:line_user_id, :display_name)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'line_user_id' => $lineUserId,
            'display_name' => $displayName,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
