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
}
