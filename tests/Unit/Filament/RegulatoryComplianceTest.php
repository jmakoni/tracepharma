<?php

namespace Tests\Unit\Filament;

use App\Filament\Support\RegulatoryCompliance;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegulatoryComplianceTest extends TestCase
{
    #[Test]
    public function assert_accepts_correct_password_when_gate_enabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);

        $user = new User([
            'name' => 'Ops',
            'email' => 'ops-gate@example.test',
            'password' => 'password',
        ]);
        $this->actingAs($user);

        RegulatoryCompliance::assert(['regulatory_password' => 'password']);

        $this->assertTrue(true);
    }

    #[Test]
    public function assert_rejects_wrong_password_when_gate_enabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);

        $user = new User([
            'name' => 'Ops',
            'email' => 'ops-gate-bad@example.test',
            'password' => 'password',
        ]);
        $this->actingAs($user);

        try {
            RegulatoryCompliance::assert(['regulatory_password' => 'wrong']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('regulatory_password', $e->errors());
        }
    }

    #[Test]
    public function assert_is_noop_when_gate_disabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => false]);

        RegulatoryCompliance::assert(['regulatory_password' => 'anything']);

        $this->assertTrue(true);
    }

    #[Test]
    public function apply_skips_confirmation_outside_app_panel_when_gate_enabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $action = Action::make('demo')->action(fn () => null);
        $gated = RegulatoryCompliance::apply($action, 'demo_action', requireReason: true);

        $this->assertFalse($gated->isConfirmationRequired());
    }

    #[Test]
    public function apply_wraps_action_on_app_panel_when_gate_enabled(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('demo')->action(fn () => null);
        $gated = RegulatoryCompliance::apply($action, 'demo_action', requireReason: false);

        $this->assertTrue($gated->isConfirmationRequired());
    }

    #[Test]
    public function apply_does_not_require_confirmation_to_open_edit_or_create_modals(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $edit = RegulatoryCompliance::apply(
            EditAction::make()->schema([
                \Filament\Forms\Components\TextInput::make('name'),
            ])->action(fn () => null),
            'trading_partner_edit',
        );
        $create = RegulatoryCompliance::apply(
            CreateAction::make()->action(fn () => null),
            'trading_partner_create',
        );

        $this->assertFalse($edit->isConfirmationRequired());
        $this->assertFalse($create->isConfirmationRequired());
        $this->assertTrue(RegulatoryCompliance::isFormModalAction($edit));
        $this->assertTrue(RegulatoryCompliance::isFormModalAction($create));

        $schemaProperty = new \ReflectionProperty($edit, 'schema');
        $schemaProperty->setAccessible(true);
        $editSchema = $schemaProperty->getValue($edit);
        if ($editSchema instanceof \Closure) {
            $editSchema = $edit->evaluate($editSchema);
        }
        $this->assertIsArray($editSchema);
        $names = collect($editSchema)->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)->filter()->all();
        $this->assertContains('name', $names);
        $this->assertNotContains('regulatory_password', $names);
        $this->assertNotContains('regulatory_notice', $names);

        $submit = $edit->getModalSubmitAction();
        $this->assertNotNull($submit);
        $this->assertSame('submit', $submit->getName());
        $this->assertNotInstanceOf(EditAction::class, $submit);
        $this->assertTrue($submit->isConfirmationRequired());
        $this->assertSame("mountAction('submit')", $submit->getLivewireClickHandler());

        $submitSchemaProperty = new \ReflectionProperty($submit, 'schema');
        $submitSchemaProperty->setAccessible(true);
        $submitSchema = $submitSchemaProperty->getValue($submit);
        if ($submitSchema instanceof \Closure) {
            $submitSchema = $submit->evaluate($submitSchema);
        }
        $this->assertIsArray($submitSchema);
        $submitNames = collect($submitSchema)->map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null)->filter()->all();
        $this->assertContains('regulatory_password', $submitNames);
        $this->assertContains('regulatory_notice', $submitNames);
        $this->assertNotContains('name', $submitNames);
    }

    #[Test]
    public function apply_keeps_business_schema_off_the_password_modal(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('recordAtp')
            ->schema([
                \Filament\Forms\Components\TextInput::make('atp_verification_note'),
            ])
            ->action(fn () => null);

        $gated = RegulatoryCompliance::apply($action, 'record_atp_verification');

        $this->assertFalse($gated->isConfirmationRequired());

        $formNames = $this->schemaFieldNames($gated);
        $this->assertContains('atp_verification_note', $formNames);
        $this->assertNotContains('regulatory_notice', $formNames);
        $this->assertNotContains('regulatory_password', $formNames);

        $submit = $gated->getModalSubmitAction();
        $this->assertNotNull($submit);
        $this->assertTrue($submit->isConfirmationRequired());

        $confirmNames = $this->schemaFieldNames($submit);
        $this->assertContains('regulatory_notice', $confirmNames);
        $this->assertContains('regulatory_password', $confirmNames);
        $this->assertNotContains('atp_verification_note', $confirmNames);
    }

    #[Test]
    public function apply_keeps_existing_reason_on_the_form_not_the_password_modal(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('voidDocument')
            ->schema([
                \Filament\Forms\Components\Textarea::make('reason'),
            ])
            ->action(fn () => null);

        $gated = RegulatoryCompliance::apply(
            $action,
            'epcis_void',
            requireReason: true,
            existingReasonField: 'reason',
        );

        $this->assertFalse($gated->isConfirmationRequired());

        $formNames = $this->schemaFieldNames($gated);
        $this->assertContains('reason', $formNames);
        $this->assertNotContains('regulatory_notice', $formNames);
        $this->assertNotContains('regulatory_password', $formNames);
        $this->assertNotContains('compliance_reason', $formNames);

        $submit = $gated->getModalSubmitAction();
        $this->assertNotNull($submit);
        $this->assertTrue($submit->isConfirmationRequired());

        $confirmNames = $this->schemaFieldNames($submit);
        $this->assertContains('regulatory_notice', $confirmNames);
        $this->assertContains('regulatory_password', $confirmNames);
        $this->assertNotContains('reason', $confirmNames);
        $this->assertNotContains('compliance_reason', $confirmNames);
    }

    #[Test]
    public function apply_honors_modal_submit_action_false(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('authorizeMissing')
            ->schema([
                \Filament\Forms\Components\Placeholder::make('empty'),
            ])
            ->modalSubmitAction(false)
            ->action(fn () => null);

        $gated = RegulatoryCompliance::apply($action, 'authorize_missing_products');

        $this->assertNull($gated->getModalSubmitAction());
    }

    #[Test]
    public function require_verified_rejects_ungated_save(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        RegulatoryCompliance::consumeVerified('save_record');

        try {
            RegulatoryCompliance::requireVerified('save_record');
            $this->fail('Expected ValidationException');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('regulatory_password', $exception->errors());
        }
    }

    #[Test]
    public function require_verified_accepts_a_marked_action(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        RegulatoryCompliance::markVerified('save_record');
        RegulatoryCompliance::requireVerified('save_record');

        $this->assertTrue(true);
    }

    #[Test]
    public function apply_confirmation_without_business_schema_is_notice_and_password_only(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('demo')->action(fn () => null);
        $gated = RegulatoryCompliance::apply($action, 'demo_action');

        $this->assertTrue($gated->isConfirmationRequired());

        $names = $this->schemaFieldNames($gated);
        $this->assertContains('regulatory_notice', $names);
        $this->assertContains('regulatory_password', $names);
        $this->assertSame(
            ['regulatory_notice', 'regulatory_password'],
            array_values($names),
        );
    }

    #[Test]
    public function apply_skips_confirmation_when_when_callback_is_false(): void
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $action = Action::make('demo')->action(fn () => null);
        $gated = RegulatoryCompliance::apply(
            $action,
            'demo_action',
            requireReason: false,
            when: fn (): bool => false,
        );

        $this->assertFalse($gated->isConfirmationRequired());
    }

    #[Test]
    public function notice_text_matches_regulatory_copy(): void
    {
        $this->assertSame(
            'This system has regulatory compliance controls activated. Please validate the changes you have made by entering your password in the textbox below.',
            RegulatoryCompliance::NOTICE,
        );
    }

    #[Test]
    public function class_docblock_scopes_gate_away_from_floor_scan(): void
    {
        $reflection = new \ReflectionClass(RegulatoryCompliance::class);
        $doc = (string) $reflection->getDocComment();

        $this->assertStringContainsString('floor ops', $doc);
        $this->assertStringContainsString('receiving', strtolower($doc));
    }

    /**
     * @return list<string>
     */
    private function schemaFieldNames(Action $action): array
    {
        $schemaProperty = new \ReflectionProperty($action, 'schema');
        $schemaProperty->setAccessible(true);
        $schema = $schemaProperty->getValue($action);
        if ($schema instanceof \Closure) {
            $schema = $action->evaluate($schema);
        }

        $this->assertIsArray($schema);

        return collect($schema)
            ->map(fn ($component) => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->values()
            ->all();
    }
}
