<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Picking = 'picking';
    case Packed = 'packed';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
}
