<?php

namespace App\Enums;

enum AnnouncementSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
