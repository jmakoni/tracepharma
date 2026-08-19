<?php

namespace Tests\Unit\Filament;

use App\Filament\Support\RegulatoryCompliance;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RegulatoryComplianceThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.regulatory_compliance.password_gate' => true,
            'tracepharma.regulatory_compliance.max_attempts' => 3,
            'tracepharma.regulatory_compliance.lockout_seconds' => 600,
        ]);
    }

    #[Test]
    public function repeated_wrong_passwords_lock_the_action(): void
    {
        $this->actingAs($this->operator('lockout'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->assertSame(
                'The password you entered is incorrect.',
                $this->rejectionMessage('trading_partners_delete'),
                "Attempt {$attempt} should still be a plain rejection.",
            );
        }

        $this->assertStringContainsString('Too many incorrect passwords', $this->rejectionMessage('trading_partners_delete'));
    }

    #[Test]
    public function a_locked_action_rejects_even_the_correct_password(): void
    {
        $this->actingAs($this->operator('locked-correct'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->rejectionMessage('exception_quarantine_release');
        }

        $message = $this->rejectionMessage('exception_quarantine_release', 'password');

        $this->assertStringContainsString('Too many incorrect passwords', $message);
    }

    #[Test]
    public function the_lockout_reports_when_the_action_unlocks(): void
    {
        $this->actingAs($this->operator('lockout-window'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->rejectionMessage('save_record');
        }

        $this->assertMatchesRegularExpression('/Try again in \d+ minute/', $this->rejectionMessage('save_record'));
    }

    #[Test]
    public function a_correct_password_clears_the_attempt_counter(): void
    {
        $this->actingAs($this->operator('clears'));

        $this->rejectionMessage('trading_partners_delete');
        $this->rejectionMessage('trading_partners_delete');

        RegulatoryCompliance::assert(['regulatory_password' => 'password'], 'trading_partners_delete');

        // Without the clear, these two attempts would tip the action over max_attempts.
        $this->assertSame(
            'The password you entered is incorrect.',
            $this->rejectionMessage('trading_partners_delete'),
        );
        $this->assertSame(
            'The password you entered is incorrect.',
            $this->rejectionMessage('trading_partners_delete'),
        );
    }

    #[Test]
    public function the_throttle_is_scoped_per_action(): void
    {
        $this->actingAs($this->operator('per-action'));

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->rejectionMessage('trading_partners_delete');
        }

        $this->assertSame(
            'The password you entered is incorrect.',
            $this->rejectionMessage('exception_quarantine_release'),
            'A different action must not inherit the lockout.',
        );
    }

    #[Test]
    public function the_throttle_is_scoped_per_user(): void
    {
        $first = $this->operator('user-one', id: 4001);
        $second = $this->operator('user-two', id: 4002);

        $this->actingAs($first);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->rejectionMessage('trading_partners_delete');
        }

        $this->actingAs($second);
        $this->assertSame(
            'The password you entered is incorrect.',
            $this->rejectionMessage('trading_partners_delete'),
            'Another operator must not inherit the lockout.',
        );
    }

    #[Test]
    public function rejected_attempts_are_logged_with_the_action_and_user(): void
    {
        $user = $this->operator('audited', id: 4009);
        $this->actingAs($user);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Regulatory compliance password rejected.'
                    && $context['action'] === 'trading_partners_delete'
                    && $context['outcome'] === 'incorrect_password'
                    && (int) $context['user_id'] === 4009;
            });

        $this->rejectionMessage('trading_partners_delete');
    }

    #[Test]
    public function rejected_attempts_land_on_the_activity_log(): void
    {
        $user = $this->operator('activity', id: 4010);
        $this->actingAs($user);

        try {
            $this->rejectionMessage('trading_partners_portal_link_revoke');

            $activity = Activity::query()
                ->where('description', 'regulatory_compliance_failed')
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($activity, 'Expected a regulatory_compliance_failed activity entry.');
            $this->assertSame('trading_partners_portal_link_revoke', $activity->properties['action'] ?? null);
            $this->assertSame('incorrect_password', $activity->properties['outcome'] ?? null);
        } finally {
            Activity::query()->where('description', 'regulatory_compliance_failed')->delete();
        }
    }

    #[Test]
    public function the_throttle_is_skipped_when_the_gate_is_disabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => false]);
        $this->actingAs($this->operator('disabled'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            RegulatoryCompliance::assert(['regulatory_password' => 'wrong'], 'trading_partners_delete');
        }

        $this->assertTrue(true);
    }

    private function rejectionMessage(string $actionName, string $password = 'wrong'): string
    {
        try {
            RegulatoryCompliance::assert(['regulatory_password' => $password], $actionName);
        } catch (ValidationException $exception) {
            return (string) ($exception->errors()['regulatory_password'][0] ?? '');
        }

        $this->fail('Expected the compliance gate to reject the password.');
    }

    private function operator(string $slug, int $id = 4000): User
    {
        $user = new User([
            'name' => 'Ops',
            'email' => 'ops-'.$slug.'@example.test',
            'password' => 'password',
        ]);
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
