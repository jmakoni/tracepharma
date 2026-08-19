<?php

namespace App\Support\Marketing;

class DemoOrganizationSolutions
{
    public static function label(?string $organizationType): ?string
    {
        return match ($organizationType) {
            'independent_pharmacy' => 'Pharmacies',
            'hospital_health_system' => 'Pharmacies',
            'wholesaler' => 'Drug wholesalers',
            'manufacturer' => 'Drug manufacturers',
            'logistics_3pl' => '3PL & logistics',
            'buying_group' => 'Buying groups',
            'dental_medical' => 'Dental & medical supply',
            'prepackager' => 'Prepackagers',
            default => null,
        };
    }

    public static function routeName(?string $organizationType): ?string
    {
        return match ($organizationType) {
            'independent_pharmacy', 'hospital_health_system' => 'marketing.solutions.pharmacies',
            'wholesaler' => 'marketing.solutions.wholesalers',
            'manufacturer' => 'marketing.solutions.manufacturers',
            'logistics_3pl' => 'marketing.solutions.3pl',
            'buying_group' => 'marketing.solutions.buying-groups',
            'dental_medical' => 'marketing.solutions.dental-medical',
            'prepackager' => 'marketing.solutions.prepackagers',
            default => null,
        };
    }

    public static function url(?string $organizationType): ?string
    {
        $routeName = self::routeName($organizationType);

        return $routeName !== null ? route($routeName) : null;
    }
}