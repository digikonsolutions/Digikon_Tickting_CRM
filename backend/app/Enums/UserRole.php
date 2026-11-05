<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Client = 'client';
    case AgencyOwner = 'agency_owner';
    case Employee = 'employee';
}
