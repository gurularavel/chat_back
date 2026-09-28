<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Service keeps working (widget, AI, inbox). */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue, self::Canceled], true);
    }
}
