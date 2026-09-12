<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class OutboundNetworkProfile extends Model
{
    use CentralConnection;

    protected $fillable = [
        'network_slug',
        'environment',
        'label',
        'default_transport',
        'allowed_transports',
        'endpoint_url',
        'as2_url',
        'as2_to',
        'as2_subject',
        'notes',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'allowed_transports' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public static function networkSlugs(): array
    {
        return [
            'tracepharma',
            'systech',
            'unitrace',
            'custom_as2',
            'custom_https',
            'sap_ich',
            'tracelink',
            'lspedia',
            'advasur',
            'axway',
            'rfxcel',
            'gateway_checker',
            'jennason',
            'infinitrak',
            'the_systems_house',
            'tracktracerx',
        ];
    }

    public function isPlatformHub(): bool
    {
        return $this->network_slug === 'tracepharma';
    }

    public function displayLabel(): string
    {
        return $this->label.' ('.$this->environment.')';
    }
}
