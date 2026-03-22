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

    public function getAllBriefEnabledUsers(): array
    {
        $sql = 'SELECT id, line_user_id FROM users WHERE line_user_id IS NOT NULL AND line_user_id <> \'\' AND brief_enabled = 1 ORDER BY id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function updateBriefEnabled(int $ownerId, int $enabled): void
    {
        $sql  = 'UPDATE users SET brief_enabled = :enabled WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['enabled' => $enabled, 'id' => $ownerId]);
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

    public function findById(int $id): ?array
    {
        $sql  = 'SELECT * FROM users WHERE id = :id LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByStripeCustomerId(string $customerId): ?array
    {
        $sql  = 'SELECT * FROM users WHERE stripe_customer_id = :cid LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateWarnedLimit(int $ownerId): void
    {
        $sql  = 'UPDATE users SET warned_limit = 1, updated_at = NOW() WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $ownerId]);
    }

    /**
     * Update billing-related columns only.
     * Accepted keys: is_paid, subscription_status, subscription_expires_at,
     *                stripe_customer_id, stripe_subscription_id
     */
    public function updateSubscription(int $ownerId, array $data): void
    {
        $allowed = [
            'is_paid',
            'subscription_status',
            'subscription_expires_at',
            'stripe_customer_id',
            'stripe_subscription_id',
        ];
        $sets   = [];
        $params = ['id' => $ownerId];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $sets[]       = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (empty($sets)) {
            return;
        }
        $sql  = 'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }
}
