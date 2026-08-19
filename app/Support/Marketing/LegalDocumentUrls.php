<?php

namespace App\Support\Marketing;

use Illuminate\Support\Facades\Route;

class LegalDocumentUrls
{
    public static function marketingBaseUrl(): string
    {
        $domain = (string) config('tracepharma.marketing_domain', config('tracepharma.platform_base_domain', 'tracepharma.io'));
        $scheme = str_contains($domain, 'localhost') ? 'http' : 'https';

        return $scheme.'://'.$domain;
    }

    public static function termsUrl(): string
    {
        if (Route::has('marketing.tos')) {
            return route('marketing.tos', absolute: true);
        }

        return self::marketingBaseUrl().'/tos';
    }

    public static function privacyUrl(): string
    {
        if (Route::has('marketing.privacy')) {
            return route('marketing.privacy', absolute: true);
        }

        return self::marketingBaseUrl().'/privacy';
    }

    public static function legalSummaryUrl(): string
    {
        if (Route::has('marketing.legal')) {
            return route('marketing.legal', absolute: true);
        }

        return self::marketingBaseUrl().'/legal';
    }
}
