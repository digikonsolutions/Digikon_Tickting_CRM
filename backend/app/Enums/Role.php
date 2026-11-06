<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case TeamLead = 'team_lead';
    case Employee = 'employee';
    case Qa = 'qa';
    case AgencyOwner = 'agency_owner';
    case AgencyMember = 'agency_member';

    public function isInternal(): bool
    {
        return in_array($this, [self::Admin, self::TeamLead, self::Employee, self::Qa], true);
    }

    public function isAgencyRole(): bool
    {
        return in_array($this, [self::AgencyOwner, self::AgencyMember], true);
    }
}
