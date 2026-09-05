<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Auth;

use App\Support\Auth\DirectoryAttributes;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DirectoryAttributesTest extends TestCase
{
    #[Test]
    public function it_maps_entra_shaped_claims(): void
    {
        $socialite = (new SocialiteUser)
            ->map([
                'id' => 'sub-1',
                'name' => 'Ada Lovelace',
                'email' => 'ada@contoso.test',
            ])
            ->setRaw([
                'oid' => 'object-ada',
                'upn' => 'ada@contoso.test',
                'employeeId' => 'E1001',
                'given_name' => 'Ada',
                'family_name' => 'Lovelace',
                'jobTitle' => 'Pharmacist',
                'department' => 'Pharmacy',
                'companyName' => 'Contoso',
                'officeLocation' => 'Austin',
                'mobilePhone' => '+15551212',
                'businessPhones' => ['+15559876'],
                'groups' => ['sg-pharmacy', ['id' => 'gid-ops', 'displayName' => 'Ops']],
            ]);

        $mapped = DirectoryAttributes::fromSocialiteUser($socialite);

        $this->assertSame('object-ada', $mapped['directory_object_id']);
        $this->assertSame('ada@contoso.test', $mapped['user_principal_name']);
        $this->assertSame('E1001', $mapped['employee_id']);
        $this->assertSame('Ada', $mapped['given_name']);
        $this->assertSame('Lovelace', $mapped['surname']);
        $this->assertSame('Pharmacist', $mapped['job_title']);
        $this->assertSame('Pharmacy', $mapped['department']);
        $this->assertSame('Contoso', $mapped['company_name']);
        $this->assertSame('Austin', $mapped['office_location']);
        $this->assertSame('+15551212', $mapped['mobile_phone']);
        $this->assertSame('+15559876', $mapped['business_phone']);
        $this->assertSame(['sg-pharmacy', 'gid-ops'], $mapped['directory_groups']);
    }

    #[Test]
    public function fillable_updates_are_empty_when_no_claims(): void
    {
        $socialite = (new SocialiteUser)->map([
            'id' => 'sub-2',
            'name' => 'No Claims',
            'email' => 'none@contoso.test',
        ]);

        $this->assertSame([], DirectoryAttributes::fromSocialiteUser($socialite));
        $this->assertSame([], DirectoryAttributes::fillableUpdates([]));
    }
}
