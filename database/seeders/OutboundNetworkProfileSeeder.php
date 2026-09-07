<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OutboundNetworkProfile;
use App\Support\Integrations\OutboundNetworkProfileSeed;
use Illuminate\Database\Seeder;

class OutboundNetworkProfileSeeder extends Seeder
{
    public function __construct(
        private readonly bool $force = false,
    ) {}

    public function run(): void
    {
        foreach (OutboundNetworkProfileSeed::definitions() as $definition) {
            $existing = OutboundNetworkProfile::query()
                ->where('network_slug', $definition['network_slug'])
                ->where('environment', $definition['environment'])
                ->first();

            if ($existing === null) {
                OutboundNetworkProfile::query()->create($definition);

                continue;
            }

            if ($this->force) {
                $existing->update($definition);

                continue;
            }

            if (! $existing->is_locked) {
                continue;
            }

            $updates = [];

            foreach (['endpoint_url', 'as2_url', 'as2_to', 'as2_subject', 'notes'] as $field) {
                if (blank($existing->{$field}) && filled($definition[$field] ?? null)) {
                    $updates[$field] = $definition[$field];
                }
            }

            if ($updates !== []) {
                $existing->update($updates);
            }
        }
    }
}
