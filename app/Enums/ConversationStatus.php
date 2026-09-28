<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Ai = 'ai';
    case PendingHuman = 'pending_human';
    case Human = 'human';
    case Closed = 'closed';
}
