<?php

namespace App\Enums;

enum ExceptionActivityKind: string
{
    case StatusChange = 'status_change';
    case Assignment = 'assignment';
    case Comment = 'comment';
    case Resolution = 'resolution';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::StatusChange => 'Status change',
            self::Assignment => 'Assignment',
            self::Comment => 'Comment',
            self::Resolution => 'Resolution',
            self::System => 'System',
        };
    }
}
