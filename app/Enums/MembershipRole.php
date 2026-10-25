<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => '組織責任者',
            self::Admin => '管理者',
            self::Operator => '担当者',
            self::Viewer => '閲覧のみ',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => '契約・組織設定を含めて管理できます。',
            self::Admin => 'メンバー管理と質問票の承認ができます。',
            self::Operator => 'IT台帳の登録・更新ができます。',
            self::Viewer => '情報の確認のみできます。',
        };
    }

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
