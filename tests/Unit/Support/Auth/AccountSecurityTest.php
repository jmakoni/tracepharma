<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Auth;

use App\Models\Admin;
use App\Support\Auth\AccountSecuritySession;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
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
    public function record_failed_login_locks_after_threshold(): void
    {
        config([
            'tracepharma.account_security.max_failed_logins' => 3,
            'tracepharma.account_security.lockout_minutes' => 15,
        ]);

        $admin = Admin::factory()->create([
            'email' => 'sec-lock-'.Str::lower(Str::random(8)).'@example.com',
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        $admin->recordFailedLogin();
        $admin->recordFailedLogin();
        $this->assertFalse($admin->fresh()->isLocked());

        $admin->recordFailedLogin();
        $admin = $admin->fresh();

        $this->assertTrue($admin->isLocked());
        $this->assertFalse($admin->isUsable());
        $this->assertSame(3, (int) $admin->failed_login_count);
        $this->assertNotNull($admin->locked_until);
    }

    #[Test]
    public function disable_and_enable_toggle_usability(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'sec-disable-'.Str::lower(Str::random(8)).'@example.com',
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        $admin->disable('test');
        $admin = $admin->fresh();

        $this->assertFalse($admin->is_active);
        $this->assertFalse($admin->isUsable());
        $this->assertSame('test', $admin->disabled_reason);
        $this->assertGreaterThan(0, (int) $admin->session_version);

        $admin->enable();
        $admin = $admin->fresh();

        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isUsable());
        $this->assertNull($admin->disabled_at);
    }

    #[Test]
    public function password_change_clears_must_change_password_flag(): void
    {
        $admin = Admin::factory()->mustChangePassword()->create([
            'email' => 'sec-pw-'.Str::lower(Str::random(8)).'@example.com',
        ]);
        $this->adminIds[] = (int) $admin->getKey();
        $this->assertTrue($admin->mustChangePassword());

        $admin->forceFill(['password' => 'new-password-123'])->save();
        $admin = $admin->fresh();

        $this->assertFalse($admin->mustChangePassword());
        $this->assertNotNull($admin->password_changed_at);
    }

    #[Test]
    public function same_save_temp_password_and_force_change_keeps_must_change_flag(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'sec-force-'.Str::lower(Str::random(8)).'@example.com',
            'must_change_password' => false,
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        $admin->forceFill([
            'password' => 'temp-password-123',
            'must_change_password' => true,
        ])->save();
        $admin = $admin->fresh();

        $this->assertTrue($admin->mustChangePassword());
        $this->assertNotNull($admin->password_changed_at);
    }

    #[Test]
    public function null_session_version_does_not_rebind_after_bump(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'sec-null-'.Str::lower(Str::random(8)).'@example.com',
            'session_version' => 2,
        ]);
        $this->adminIds[] = (int) $admin->getKey();
        AccountSecuritySession::clear();

        $this->assertFalse(AccountSecuritySession::matches($admin));
    }

    #[Test]
    public function revoke_clears_remember_token(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'sec-remember-'.Str::lower(Str::random(8)).'@example.com',
            'remember_token' => 'keep-me',
        ]);
        $this->adminIds[] = (int) $admin->getKey();

        app(\App\Support\Auth\SessionRevoker::class)->revoke($admin);
        $admin = $admin->fresh();

        $this->assertNull($admin->remember_token);
    }

    #[Test]
    public function unlock_clears_lock_state(): void
    {
        $admin = Admin::factory()->locked()->create([
            'email' => 'sec-unlock-'.Str::lower(Str::random(8)).'@example.com',
        ]);
        $this->adminIds[] = (int) $admin->getKey();
        $this->assertTrue($admin->isLocked());

        $admin->unlock();
        $admin = $admin->fresh();

        $this->assertFalse($admin->isLocked());
        $this->assertSame(0, (int) $admin->failed_login_count);
    }

    #[Test]
    public function session_version_mismatch_is_detected(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'sec-sess-'.Str::lower(Str::random(8)).'@example.com',
            'session_version' => 1,
        ]);
        $this->adminIds[] = (int) $admin->getKey();
        AccountSecuritySession::bind($admin);

        $admin->forceFill(['session_version' => 2])->saveQuietly();

        $this->assertFalse(AccountSecuritySession::matches($admin->fresh()));
    }
}
