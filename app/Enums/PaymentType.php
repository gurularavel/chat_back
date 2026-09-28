<?php

namespace App\Enums;

enum PaymentType: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case SeatUpgrade = 'seat_upgrade';
}
