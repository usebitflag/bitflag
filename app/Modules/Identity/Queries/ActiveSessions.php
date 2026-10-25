<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use Illuminate\Support\Facades\DB;

/**
 * A person's signed-in sessions, newest activity first, for the "Your sessions" list. A session is named to the page by a
 * short hash of its id, never the id itself, and the page can ask to end one only by that name.
 */
final class ActiveSessions
{
    /**
     * @return list<array{ref: string, device: string, location: string, lastActive: int, startedAt: int|null, current: bool}>
     */
    public function for(string $userId, string $currentSessionId): array
    {
        $rows = DB::table('sessions')->where('user_id', $userId)->orderByDesc('last_activity')->get();
        $sessions = [];

        foreach ($rows as $row) {
            $id = is_string($row->id) ? $row->id : '';
            $startedAt = is_numeric($row->created_at) ? (int) $row->created_at : 0;

            $sessions[] = [
                'ref' => self::ref($id),
                'device' => is_string($row->device_label) && $row->device_label !== '' ? $row->device_label : 'Unknown device',
                'location' => is_string($row->location_estimate) && $row->location_estimate !== '' ? $row->location_estimate : 'Unknown location',
                'lastActive' => is_numeric($row->last_activity) ? (int) $row->last_activity : 0,
                'startedAt' => $startedAt === 0 ? null : $startedAt,
                'current' => $id === $currentSessionId,
            ];
        }

        return $sessions;
    }

    public static function ref(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }
}
