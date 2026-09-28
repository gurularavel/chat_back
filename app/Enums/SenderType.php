<?php

namespace App\Enums;

enum SenderType: string
{
    case Visitor = 'visitor';
    case Ai = 'ai';
    case Operator = 'operator';
    case System = 'system';
}
