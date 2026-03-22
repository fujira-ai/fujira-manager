<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

use DateTimeInterface;

final class TokenRepository
{
    public function __construct(private Database $db) {}

    /**
     * Generate a random token, persist it, and return the token string.
     */
    public function createToken(int $userId, string $purpose, DateTimeInterface $expiresAt): string
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex

        $sql  = 'INSERT INTO user_tokens (user_id, token, purpose, expires_at)
                 VALUES (:user_id, :token, :purpose, :expires_at)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'user_id'    => $userId,
            'token'      => $token,
            'purpose'    => $purpose,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Validate and consume a token.
     * Returns user_id on success, null if token is missing / wrong purpose / expired.
     * The row is deleted on success (one-time use).
     */
    public function consumeToken(string $token, string $purpose): ?int
    {
        $sql  = 'SELECT id, user_id FROM user_tokens
                 WHERE token = :token
                   AND purpose = :purpose
                   AND expires_at > NOW()
                 LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['token' => $token, 'purpose' => $purpose]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $deleteSql  = 'DELETE FROM user_tokens WHERE id = :id';
        $deleteStmt = $this->db->pdo()->prepare($deleteSql);
        $deleteStmt->execute(['id' => $row['id']]);

        return (int) $row['user_id'];
    }
}
