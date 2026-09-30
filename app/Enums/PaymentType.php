<?php

namespace App\Enums;

enum PaymentType: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case SeatUpgrade = 'seat_upgrade';
    case Grant = 'grant'; // superadmin gave a plan for free (amount 0, no gateway)
}
