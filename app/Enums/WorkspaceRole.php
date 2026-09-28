<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';

    public function canManage(): bool
    {
        return $this !== self::Operator;
    }
}
