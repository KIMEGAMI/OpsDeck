<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function canManageOrganization(): bool
    {
        return $this === self::Owner;
    }

    public function canManageOperationalData(): bool
    {
        return $this !== self::Viewer;
    }

    public function canApproveQuestionnaires(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canViewAuditLogs(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
