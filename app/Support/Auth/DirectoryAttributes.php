<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Arr;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Maps common Entra ID / Okta / generic OIDC claims onto local directory columns.
 *
 * @phpstan-type DirectoryAttributeMap array{
 *     directory_object_id?: string,
 *     user_principal_name?: string,
 *     employee_id?: string,
 *     given_name?: string,
 *     surname?: string,
 *     job_title?: string,
 *     department?: string,
 *     company_name?: string,
 *     office_location?: string,
 *     mobile_phone?: string,
 *     business_phone?: string,
 *     directory_groups?: list<string>,
 * }
 */
final class DirectoryAttributes
{
    /**
     * @return list<string>
     */
    public static function columnNames(): array
    {
        return [
            'directory_object_id',
            'user_principal_name',
            'employee_id',
            'given_name',
            'surname',
            'job_title',
            'department',
            'company_name',
            'office_location',
            'mobile_phone',
            'business_phone',
            'directory_groups',
        ];
    }

    /**
     * @return DirectoryAttributeMap
     */
    public static function fromSocialiteUser(SocialiteUser $socialiteUser): array
    {
        $raw = self::rawClaims($socialiteUser);

        $attributes = array_filter([
            'directory_object_id' => self::firstString($raw, ['oid', 'object_id', 'objectId']),
            'user_principal_name' => self::firstString($raw, ['upn', 'userPrincipalName', 'preferred_username']),
            'employee_id' => self::firstString($raw, ['employeeid', 'employee_id', 'employeeId']),
            'given_name' => self::firstString($raw, ['given_name', 'givenName']),
            'surname' => self::firstString($raw, ['family_name', 'surname', 'familyName']),
            'job_title' => self::firstString($raw, ['jobTitle', 'job_title', 'title']),
            'department' => self::firstString($raw, ['department']),
            'company_name' => self::firstString($raw, ['companyName', 'company_name', 'organization', 'org']),
            'office_location' => self::firstString($raw, ['officeLocation', 'office_location']),
            'mobile_phone' => self::firstString($raw, ['mobile_phone', 'mobilePhone', 'phone_number']),
            'business_phone' => self::businessPhone($raw),
            'directory_groups' => self::groups($raw),
        ], static fn (mixed $value): bool => $value !== null);

        /** @var DirectoryAttributeMap $attributes */
        return $attributes;
    }

    /**
     * Keep existing non-empty local values when incoming claims are blank.
     *
     * @param  DirectoryAttributeMap  $incoming
     * @return array<string, mixed>
     */
    public static function fillableUpdates(array $incoming): array
    {
        if ($incoming === []) {
            return [];
        }

        return array_merge($incoming, [
            'directory_synced_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawClaims(SocialiteUser $socialiteUser): array
    {
        $raw = [];

        if (isset($socialiteUser->user) && is_array($socialiteUser->user)) {
            $raw = $socialiteUser->user;
        }

        if (method_exists($socialiteUser, 'getRaw') && is_array($socialiteUser->getRaw())) {
            $raw = array_merge($raw, $socialiteUser->getRaw());
        }

        if (isset($socialiteUser->attributes) && is_array($socialiteUser->attributes)) {
            $raw = array_merge($raw, $socialiteUser->attributes);
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $keys
     */
    private static function firstString(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($raw, $key);

            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function businessPhone(array $raw): ?string
    {
        $direct = self::firstString($raw, ['business_phone', 'businessPhone', 'telephoneNumber']);
        if ($direct !== null) {
            return $direct;
        }

        $phones = Arr::get($raw, 'businessPhones');
        if (! is_array($phones) || $phones === []) {
            return null;
        }

        $first = reset($phones);

        return is_string($first) && trim($first) !== '' ? trim($first) : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>|null
     */
    private static function groups(array $raw): ?array
    {
        $candidates = [];

        foreach (['groups', 'roles'] as $key) {
            $value = Arr::get($raw, $key);
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $candidates[] = trim($item);

                    continue;
                }

                if (is_array($item)) {
                    $id = Arr::get($item, 'id') ?? Arr::get($item, 'value') ?? Arr::get($item, 'displayName');
                    if (is_string($id) && trim($id) !== '') {
                        $candidates[] = trim($id);
                    }
                }
            }
        }

        $candidates = array_values(array_unique($candidates));

        return $candidates === [] ? null : $candidates;
    }
}
