<?php
declare(strict_types=1);

namespace FujiraManager\Storage;

final class ScheduleRepository
{
    public function __construct(private Database $db) {}

    public function getUpcomingSchedulesByOwner(string $ownerId): array
    {
        $sql = 'SELECT * FROM schedules WHERE owner_id = :owner_id AND status = :status ORDER BY schedule_date ASC, schedule_time ASC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['owner_id' => $ownerId, 'status' => 'active']);
        return $stmt->fetchAll();
    }
}
