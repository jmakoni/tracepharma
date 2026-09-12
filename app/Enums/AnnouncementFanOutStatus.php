<?php

namespace App\Enums;

enum AnnouncementFanOutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
