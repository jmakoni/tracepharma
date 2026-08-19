<?php

namespace App\Actions\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\File;

/**
 * Create tenant-suffixed storage directories and make them group-writable for PHP-FPM.
 */
final class EnsureTenantStorageDirectories
{
    /**
     * @return array{path: string, created: list<string>}
     */
    public function handle(Tenant $tenant): array
    {
        $root = storage_path('tenant'.$tenant->getTenantKey());

        $directories = [
            $root,
            $root.'/app',
            $root.'/app/private',
            $root.'/app/public',
            $root.'/app/livewire-tmp',
            $root.'/app/labels',
            $root.'/app/labels/sscc',
            $root.'/app/epcis',
            $root.'/app/epcis/uploads',
            $root.'/app/epcis/inbound',
            $root.'/app/epcis/outbound',
            $root.'/framework',
            $root.'/framework/cache',
            $root.'/framework/sessions',
            $root.'/framework/views',
            $root.'/logs',
        ];

        $created = [];

        foreach ($directories as $directory) {
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0775, true);
                $created[] = $directory;
            }

            $this->makeGroupWritable($directory);
        }

        return [
            'path' => $root,
            'created' => $created,
        ];
    }

    private function makeGroupWritable(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        @chmod($path, 0775);

        $groupAssigned = false;

        if (function_exists('posix_getgrnam')) {
            $group = posix_getgrnam('www-data');
            if (is_array($group) && isset($group['gid'])) {
                $groupAssigned = @chgrp($path, (int) $group['gid']);
            }
        }

        // CLI users outside www-data cannot chgrp; escalate when passwordless sudo is available.
        if (! $groupAssigned && is_executable('/usr/bin/sudo')) {
            $escaped = escapeshellarg($path);
            @exec('sudo -n chgrp www-data '.$escaped.' 2>/dev/null', result_code: $chgrpCode);
            $groupAssigned = $chgrpCode === 0;
            if (is_dir($path)) {
                @exec('sudo -n chmod 2775 '.$escaped.' 2>/dev/null');
            }
        }

        // When the CLI user is not in www-data, chgrp fails; ACL still grants PHP-FPM write.
        if (! $groupAssigned && is_executable('/usr/bin/setfacl')) {
            @exec('setfacl -m g:www-data:rwx '.escapeshellarg($path).' 2>/dev/null');
            if (is_dir($path)) {
                @exec('setfacl -d -m g:www-data:rwx '.escapeshellarg($path).' 2>/dev/null');
            }
        }
    }
}
