<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Retailer = 'retailer';
    case Staff = 'staff';
}
