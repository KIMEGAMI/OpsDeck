<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Invited => '招待中',
            self::Active => '利用中',
            self::Suspended => '利用停止',
        };
    }
}
