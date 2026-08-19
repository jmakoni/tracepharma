<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Admin;
use Spatie\Activitylog\Models\Activity;

final class AdminActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $description, Admin $causer, array $properties = []): Activity
    {
        $connection = (string) config('tenancy.database.central_connection', config('database.default'));

        $activity = new Activity;
        $activity->setConnection($connection);
        $activity->log_name = 'admin';
        $activity->description = $description;
        $activity->causer_type = $causer->getMorphClass();
        $activity->causer_id = $causer->getKey();
        $activity->properties = collect($properties);
        $activity->save();

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function logWithoutCauser(string $description, array $properties = []): Activity
    {
        $connection = (string) config('tenancy.database.central_connection', config('database.default'));

        $activity = new Activity;
        $activity->setConnection($connection);
        $activity->log_name = 'admin';
        $activity->description = $description;
        $activity->properties = collect($properties);
        $activity->save();

        return $activity;
    }
}
