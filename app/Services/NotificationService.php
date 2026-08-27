<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    public static function create(int $organizationId, int $userId, string $title, string $message, string $type = 'info', ?string $link = null): Notification
    {
        return Notification::create([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'is_read' => false,
            'link' => $link,
        ]);
    }

    /**
     * Send notification to specific user roles (admin, hr, manager, etc.)
     */
    public static function notifyRoles(int $organizationId, array $roleNames, string $title, string $message, string $type = 'info', ?string $link = null): void
    {
        $userIds = User::where('organization_id', $organizationId)
            ->whereHas('role', function ($q) use ($roleNames) {
                $q->whereIn('name', $roleNames);
            })
            ->pluck('id');

        foreach ($userIds as $userId) {
            self::create($organizationId, $userId, $title, $message, $type, $link);
        }
    }

    /**
     * Send notification to employee's manager + all Admin & HR managers
     */
    public static function notifyManagementChain(User $employee, string $title, string $message, string $type = 'info', ?string $link = null): void
    {
        $targetUserIds = User::where('organization_id', $employee->organization_id)
            ->whereHas('role', function ($q) {
                $q->whereIn('name', ['admin', 'hr', 'manager', 'company_manager']);
            })
            ->pluck('id')
            ->toArray();

        if ($employee->manager_id) {
            $targetUserIds[] = $employee->manager_id;
        }

        $targetUserIds = array_unique($targetUserIds);

        foreach ($targetUserIds as $userId) {
            self::create($employee->organization_id, $userId, $title, $message, $type, $link);
        }
    }
}
