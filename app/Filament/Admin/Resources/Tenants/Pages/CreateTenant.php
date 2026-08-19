<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Actions\Tenants\ProvisionTenantPair;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /** @var list<string> */
    private const ADDRESS_KEYS = [
        'street_address',
        'street_address_2',
        'city',
        'state',
        'zipcode',
        'country_code',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $slug = strtolower((string) ($data['tenant_slug'] ?? ''));
        $owner = [
            'name' => (string) ($data['owner_name'] ?? ''),
            'email' => (string) ($data['owner_email'] ?? ''),
            'password' => (string) ($data['owner_password'] ?? ''),
        ];
        unset(
            $data['tenant_slug'],
            $data['inbound_environment'],
            $data['owner_name'],
            $data['owner_email'],
            $data['owner_password'],
        );

        $address = self::extractAddressData($data);

        try {
            /** @var Tenant $tenant */
            $tenant = app(ProvisionTenantPair::class)->create($slug, $data, $address, $owner);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'tenant_slug' => $e->getMessage(),
            ]);
        }

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function extractAddressData(array &$data): array
    {
        $address = [];

        foreach (self::ADDRESS_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $address[$key] = $data[$key];
            unset($data[$key]);
        }

        return $address;
    }
}
