<?php

namespace App\Enums;

enum UserRole: string
{
    case Member = 'member';
    case Editor = 'editor';
    case Admin = 'admin';

    public function canAccessAdminPanel(): bool
    {
        return $this !== self::Member;
    }
}
