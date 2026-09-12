<?php

namespace Tests\Unit\Models;

use App\Models\Admin;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSecurityMassAssignmentTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function tearDown(): void
    {
        foreach ($this->adminIds as $id) {
            Admin::query()->whereKey($id)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function mass_assignment_cannot_flip_account_security_flags(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'mass-admin-'.Str::lower(Str::random(8)).'@example.test',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        $admin->fill([
            'is_active' => false,
            'must_change_password' => true,
            'name' => 'Renamed Admin',
        ])->save();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertFalse((bool) $admin->must_change_password);
        $this->assertSame('Renamed Admin', $admin->name);

        $admin->forceFill([
            'is_active' => false,
            'must_change_password' => true,
        ])->save();

        $admin->refresh();
        $this->assertFalse($admin->is_active);
        $this->assertTrue((bool) $admin->must_change_password);
    }
}
