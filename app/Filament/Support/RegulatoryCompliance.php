<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Throwable;

/**
 * Regulatory compliance password (and optional reason) gate for App-panel actions that
 * change compliance dispositions or master/traceability data (void, delete, resolve,
 * quarantine, assortment, partners/sites/ATP, EPCIS ingest/reprocess).
 *
 * Do not apply to high-frequency floor ops (per-scan SSCC/SGTIN confirm), opening
 * scan workstations (transfer / receive / outbound create), opening exception cases
 * from document signals, read-only exports (Track & Trace PDFs), or low-risk workflow
 * notes/assignment — those are audited via activity logs or scan-line fields instead.
 * Session completion that authors EPCIS (ship transfer, complete scan-first receiving,
 * send shipment) is gated.
 *
 * Form modals open without a password prompt. The regulatory notice and password are
 * collected only after Create/Save/submit (modal footer confirmation), matching
 * full-page EditRecord/CreateRecord. Destructive one-shot actions with no form still
 * confirm on click with notice + password only.
 *
 * The password prompt is an authentication surface, so {@see assert()} rate limits per
 * user and action and records every rejected attempt on the activity log.
 */
final class RegulatoryCompliance
{
    public const NOTICE = 'This system has regulatory compliance controls activated. Please validate the changes you have made by entering your password in the textbox below.';

    private const DEFAULT_MAX_ATTEMPTS = 5;

    private const DEFAULT_LOCKOUT_SECONDS = 900;

    /** @var array<string, true> */
    private static array $verifiedActions = [];

    public static function enabled(): bool
    {
        return (bool) config('tracepharma.regulatory_compliance.password_gate', true);
    }

    public static function isAppPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'app';
    }

    /**
     * Modal Create/Edit actions already expose Create/Save — do not requireConfirmation
     * on the header/table click that only opens the form.
     */
    public static function isFormModalAction(Action $action): bool
    {
        return $action instanceof CreateAction || $action instanceof EditAction;
    }

    /**
     * Prominent notice banner component (place first in the modal).
     */
    public static function noticeField(): Placeholder
    {
        return Placeholder::make('regulatory_notice')
            ->hiddenLabel()
            ->content(fn (): HtmlString => new HtmlString(
                view('filament.app.forms.regulatory-compliance-notice', [
                    'notice' => self::NOTICE,
                ])->render(),
            ))
            ->columnSpanFull();
    }

    /**
     * Password (+ optional reason) fields for mutating action modals.
     *
     * @return list<Component|\Filament\Forms\Components\Component>
     */
    public static function credentialFields(bool $requireReason = false): array
    {
        $fields = [
            TextInput::make('regulatory_password')
                ->label('Password')
                ->password()
                ->revealable()
                ->required()
                ->autocomplete('current-password')
                ->columnSpanFull(),
        ];

        if ($requireReason) {
            $fields[] = Textarea::make('compliance_reason')
                ->label('Reason for this action')
                ->required()
                ->rows(2)
                ->maxLength(2000)
                ->columnSpanFull();
        }

        return $fields;
    }

    /**
     * Full compliance schema: prominent notice, then credentials.
     *
     * @return list<Component|\Filament\Forms\Components\Component>
     */
    public static function fields(bool $requireReason = false): array
    {
        return [
            self::noticeField(),
            ...self::credentialFields(requireReason: $requireReason),
        ];
    }

    /**
     * Append compliance fields to an action schema and verify password in before().
     *
     * @param  string|null  $existingReasonField  When set, require that field instead of adding compliance_reason
     * @param  Closure|null  $when  Optional runtime gate; when it returns false, skip password modal/assert
     * @param  Model|Closure|null  $subject  Optional audit subject (Page actions have no getRecord())
     */
    public static function apply(
        Action $action,
        string $actionName,
        bool $requireReason = false,
        ?string $existingReasonField = null,
        ?Closure $when = null,
        Model|Closure|null $subject = null,
    ): Action {
        // Wrap whenever the gate is enabled. Panel is checked at runtime so App actions
        // still gate when the current panel is resolved after action construction (Livewire),
        // while Admin panel shared helpers remain unaffected.
        if (! self::enabled()) {
            return $action;
        }

        $schemaProperty = new ReflectionProperty($action, 'schema');
        $schemaProperty->setAccessible(true);
        /** @var array<mixed>|Closure|null $previous */
        $previous = $schemaProperty->getValue($action);

        // Filament's before() overwrites, so keep any guard the caller already registered.
        $beforeProperty = new ReflectionProperty($action, 'before');
        $beforeProperty->setAccessible(true);
        /** @var Closure|null $previousBefore */
        $previousBefore = $beforeProperty->getValue($action);

        $addComplianceReasonField = $requireReason && $existingReasonField === null;

        $shouldGate = function () use ($action, $when): bool {
            if (! self::enabled() || ! self::isAppPanel()) {
                return false;
            }

            if ($when === null) {
                return true;
            }

            return (bool) $action->evaluate($when);
        };

        // Forms open without the notice. Password (+ notice) is a second modal after submit.
        // One-shot actions with no business fields confirm on the click that runs them.
        if (self::isFormModalAction($action) || self::hasBusinessSchema($previous)) {
            return self::gateFormModalSubmit(
                $action,
                $actionName,
                $requireReason,
                $existingReasonField,
                $shouldGate,
                $previousBefore,
                $subject,
            );
        }

        $action->requiresConfirmation($shouldGate);

        $action->schema(function () use ($action, $previous, $addComplianceReasonField, $shouldGate) {
            $components = self::resolveSchemaComponents($action, $previous);

            if (! $shouldGate()) {
                return $components;
            }

            return self::fields(requireReason: $addComplianceReasonField);
        });

        return $action->before(function (Action $action) use ($actionName, $requireReason, $existingReasonField, $shouldGate, $previousBefore, $subject): void {
            if ($previousBefore !== null) {
                $action->evaluate($previousBefore);
            }

            if (! $shouldGate()) {
                return;
            }

            $data = $action->getData();
            self::assert($data, $actionName, $action);

            $reason = null;
            if ($requireReason) {
                $key = $existingReasonField ?? 'compliance_reason';
                $reason = isset($data[$key]) ? trim((string) $data[$key]) : '';
                if ($reason === '') {
                    self::throwValidation([
                        $key => 'A reason for this action is required.',
                    ], $action);
                }
            }

            $auditSubject = $subject instanceof Closure
                ? $action->evaluate($subject)
                : ($subject ?? $action->getRecord());

            self::audit($actionName, $auditSubject, $reason);
        });
    }

    /**
     * Password-gate modal Create/Save (and Create another) without polluting the form schema.
     *
     * Filament injects the footer button as the named parameter {@see $action} — that name
     * must be kept (see AppServiceProvider modalSubmitAction styling).
     *
     * @param  Closure(): bool  $shouldGate
     */
    private static function gateFormModalSubmit(
        Action $parent,
        string $actionName,
        bool $requireReason,
        ?string $existingReasonField,
        Closure $shouldGate,
        ?Closure $previousBefore,
        Model|Closure|null $subject,
    ): Action {
        $parent->before(function (Action $action) use (
            $previousBefore,
            $shouldGate,
            $actionName,
            $requireReason,
            $existingReasonField,
            $subject,
        ): void {
            if ($previousBefore !== null) {
                $action->evaluate($previousBefore);
            }

            if (! $shouldGate()) {
                return;
            }

            if (self::consumeVerified($actionName)) {
                return;
            }

            $payload = self::parentMountedData($action);
            self::assert($payload, $actionName, $action);

            $reason = self::reasonFromPayload(
                $payload,
                $requireReason,
                $existingReasonField,
                errorAction: $action,
            );
            $auditSubject = $subject instanceof Closure
                ? $action->evaluate($subject)
                : ($subject ?? $action->getRecord());

            self::audit($actionName, $auditSubject, $reason);
        });

        $modalSubmitProperty = new ReflectionProperty($parent, 'modalSubmitAction');
        $modalSubmitProperty->setAccessible(true);
        /** @var Action|bool|Closure|null $previousModalSubmit */
        $previousModalSubmit = $modalSubmitProperty->getValue($parent);

        $parent->modalSubmitAction(function (Action $action) use (
            $parent,
            $actionName,
            $requireReason,
            $existingReasonField,
            $shouldGate,
            $subject,
            $previousModalSubmit,
        ): Action|false {
            if ($previousModalSubmit instanceof Closure) {
                $evaluated = $parent->evaluate($previousModalSubmit, ['action' => $action]);
                if ($evaluated === false) {
                    return false;
                }
                $action = $evaluated ?? $action;
            } elseif ($previousModalSubmit instanceof Action) {
                $action = $previousModalSubmit;
            } elseif ($previousModalSubmit === false) {
                return false;
            }

            if (! $shouldGate()) {
                return $action;
            }

            return self::passwordConfirmFooterAction(
                $action,
                $parent,
                $actionName,
                $requireReason,
                $existingReasonField,
                $subject,
                self::passwordConfirmHeading($parent),
            );
        });

        if ($parent instanceof CreateAction) {
            $createAnotherProperty = new ReflectionProperty($parent, 'modifyCreateAnotherActionUsing');
            $createAnotherProperty->setAccessible(true);
            /** @var Closure|null $previousCreateAnother */
            $previousCreateAnother = $createAnotherProperty->getValue($parent);

            $parent->createAnotherAction(function (Action $action) use (
                $parent,
                $actionName,
                $requireReason,
                $existingReasonField,
                $shouldGate,
                $subject,
                $previousCreateAnother,
            ): Action {
                if ($previousCreateAnother instanceof Closure) {
                    $action = $parent->evaluate($previousCreateAnother, ['action' => $action]) ?? $action;
                }

                if (! $shouldGate()) {
                    return $action;
                }

                return self::passwordConfirmFooterAction(
                    $action,
                    $parent,
                    $actionName,
                    $requireReason,
                    $existingReasonField,
                    $subject,
                    'Confirm create',
                );
            });
        }

        return $parent;
    }

    /**
     * @param  array<mixed>|Closure|null  $previous
     */
    private static function hasBusinessSchema(mixed $previous): bool
    {
        if ($previous instanceof Closure) {
            return true;
        }

        return is_array($previous) && $previous !== [];
    }

    /**
     * @param  array<mixed>|Closure|null  $previous
     * @return list<mixed>
     */
    private static function resolveSchemaComponents(Action $action, mixed $previous): array
    {
        if ($previous instanceof Closure) {
            $resolved = $action->evaluate($previous);

            return is_array($resolved) ? $resolved : [];
        }

        return is_array($previous) ? $previous : [];
    }

    private static function passwordConfirmHeading(Action $parent): string
    {
        if ($parent instanceof CreateAction) {
            return 'Confirm create';
        }

        if ($parent instanceof EditAction) {
            return 'Confirm save';
        }

        return 'Confirm';
    }

    /**
     * Footer submit: confirmation modal with notice + password, then resume the parent action.
     */
    private static function passwordConfirmFooterAction(
        Action $footer,
        Action $parent,
        string $actionName,
        bool $requireReason,
        ?string $existingReasonField,
        Model|Closure|null $subject,
        string $modalHeading,
    ): Action {
        $addComplianceReasonField = $requireReason && $existingReasonField === null;

        return $footer
            ->submit(null)
            ->callParent(null)
            ->requiresConfirmation()
            ->modalHeading($modalHeading)
            ->schema(self::fields(requireReason: $addComplianceReasonField))
            ->action(function (array $data) use (
                $footer,
                $parent,
                $actionName,
                $requireReason,
                $existingReasonField,
                $subject,
            ): void {
                self::assert($data, $actionName, $footer);

                $parentData = self::parentMountedData($parent);
                $reason = self::reasonFromPayload(
                    array_merge($parentData, $data),
                    $requireReason,
                    $existingReasonField,
                    popPasswordModal: $parent,
                );

                $auditSubject = $subject instanceof Closure
                    ? $parent->evaluate($subject)
                    : ($subject ?? $parent->getRecord());

                self::audit($actionName, $auditSubject, $reason);
                self::markVerified($actionName);

                $livewire = $parent->getLivewire();
                // Pop the password confirm; keep Create/Edit mounted with form data.
                $livewire->unmountAction(cancelParentActions: false);
                $livewire->callMountedAction($footer->getArguments());

                // The footer submit is a nested modal action with no HasActions Livewire.
                // After the parent Create/Edit finishes, Filament would still run this
                // action's success redirect and crash (getDefaultActionSuccessRedirectUrl
                // on null). Halt so only the parent notifies/redirects.
                $footer->halt();
            });
    }

    /**
     * Verify the acting user's password, rate limited per user and action.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $actionName  Scopes the throttle and the failure log entry
     * @param  Action|null  $action  When set, validation keys are prefixed to the mounted
     *                               schema state path so Filament field wrappers show errors.
     *
     * @throws ValidationException
     */
    public static function assert(array $data, ?string $actionName = null, ?Action $action = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            self::throwValidation([
                'regulatory_password' => 'You must be signed in to confirm this action.',
            ], $action);
        }

        $key = self::throttleKey($user, $actionName);

        if (RateLimiter::tooManyAttempts($key, self::maxAttempts())) {
            $availableIn = RateLimiter::availableIn($key);
            self::auditFailure($actionName, $user, 'locked_out', $availableIn);

            self::throwValidation([
                'regulatory_password' => 'Too many incorrect passwords for this action. Try again in '
                    .max(1, (int) ceil($availableIn / 60)).' minute(s).',
            ], $action);
        }

        $password = (string) ($data['regulatory_password'] ?? '');
        $hashed = $user->getAuthPassword();

        if ($password === '' || ! is_string($hashed) || $hashed === '' || ! Hash::check($password, $hashed)) {
            RateLimiter::hit($key, self::lockoutSeconds());
            self::auditFailure($actionName, $user, 'incorrect_password', null);

            self::throwValidation([
                'regulatory_password' => 'The password you entered is incorrect.',
            ], $action);
        }

        RateLimiter::clear($key);
    }

    public static function markVerified(string $actionName): void
    {
        self::$verifiedActions[$actionName] = true;
    }

    public static function consumeVerified(string $actionName): bool
    {
        $ok = isset(self::$verifiedActions[$actionName]);
        unset(self::$verifiedActions[$actionName]);

        return $ok;
    }

    /**
     * @throws ValidationException
     */
    public static function requireVerified(string ...$actionNames): void
    {
        if (! self::enabled() || ! self::isAppPanel()) {
            return;
        }

        foreach ($actionNames as $actionName) {
            if (self::consumeVerified($actionName)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'regulatory_password' => 'This action must be confirmed with your password.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parentMountedData(Action $parent): array
    {
        $data = $parent->getData();

        try {
            $raw = $parent->getRawData();
            if ($raw !== []) {
                return $raw;
            }
        } catch (Throwable) {
        }

        try {
            $mounted = $parent->getLivewire()->mountedActions ?? [];
        } catch (Throwable) {
            return $data;
        }

        $index = $parent->getNestingIndex();
        if ($index === null && $mounted !== []) {
            $index = max(0, count($mounted) - 2);
        }

        if ($index !== null && isset($mounted[$index]['data']) && is_array($mounted[$index]['data'])) {
            return $mounted[$index]['data'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function reasonFromPayload(
        array $payload,
        bool $requireReason,
        ?string $existingReasonField,
        ?Action $popPasswordModal = null,
        ?Action $errorAction = null,
    ): ?string {
        if (! $requireReason) {
            return null;
        }

        $key = $existingReasonField ?? 'compliance_reason';
        $reason = isset($payload[$key]) ? trim((string) $payload[$key]) : '';
        if ($reason !== '') {
            return $reason;
        }

        if ($popPasswordModal !== null) {
            try {
                $popPasswordModal->getLivewire()->unmountAction(cancelParentActions: false);
            } catch (Throwable) {
            }
        }

        self::throwValidation([
            $key => 'A reason for this action is required.',
        ], $errorAction ?? $popPasswordModal);
    }

    /**
     * Filament action schemas bind at mountedActions.{n}.data — bare field keys never
     * appear under the Password input. Prefix when an Action context is available.
     *
     * @param  array<string, string|list<string>>  $messages
     *
     * @throws ValidationException
     */
    private static function throwValidation(array $messages, ?Action $action = null): never
    {
        throw ValidationException::withMessages(self::prefixValidationMessages($messages, $action));
    }

    /**
     * @param  array<string, string|list<string>>  $messages
     * @return array<string, string|list<string>>
     */
    private static function prefixValidationMessages(array $messages, ?Action $action = null): array
    {
        $prefix = self::mountedDataStatePath($action);
        if ($prefix === null) {
            return $messages;
        }

        $prefixed = [];
        foreach ($messages as $key => $message) {
            $prefixed[str_contains((string) $key, '.') ? $key : "{$prefix}.{$key}"] = $message;
        }

        return $prefixed;
    }

    private static function mountedDataStatePath(?Action $action): ?string
    {
        if ($action === null) {
            return null;
        }

        $index = $action->getNestingIndex();

        if ($index === null) {
            try {
                $mounted = $action->getLivewire()->mountedActions ?? [];
            } catch (Throwable) {
                return null;
            }

            if ($mounted === []) {
                return null;
            }

            $index = array_key_last($mounted);
        }

        if ($index === null) {
            return null;
        }

        return "mountedActions.{$index}.data";
    }

    public static function audit(string $actionName, mixed $subject = null, ?string $reason = null): void
    {
        if (! self::enabled() || ! function_exists('activity')) {
            return;
        }

        $logger = activity()->withProperties(array_filter([
            'action' => $actionName,
            'user_id' => auth()->id(),
            'reason' => $reason,
            'panel' => Filament::getCurrentPanel()?->getId(),
        ], static fn ($v) => $v !== null && $v !== ''));

        if ($subject instanceof Model) {
            $logger->performedOn($subject);
        }

        $logger->log('regulatory_compliance_acknowledged');
    }

    /**
     * Rejected attempts are the signal an auditor looks for, so they are logged whether or
     * not the action itself proceeds. Logging never blocks the credential check: a failed
     * write must not turn a wrong password into a server error that hides the lockout.
     */
    private static function auditFailure(
        ?string $actionName,
        Authenticatable $user,
        string $outcome,
        ?int $availableIn,
    ): void {
        $properties = array_filter([
            'action' => $actionName,
            'user_id' => $user->getAuthIdentifier(),
            'outcome' => $outcome,
            'available_in_seconds' => $availableIn,
            'panel' => Filament::getCurrentPanel()?->getId(),
            'ip' => request()->ip(),
        ], static fn ($v) => $v !== null && $v !== '');

        Log::warning('Regulatory compliance password rejected.', $properties);

        if (! function_exists('activity')) {
            return;
        }

        try {
            $logger = activity()->withProperties($properties);

            if ($user instanceof Model) {
                $logger->causedBy($user);
            }

            $logger->log('regulatory_compliance_failed');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Keyed per user and action so one locked-out action cannot block the rest of the
     * operator's work, and tenant-scoped because user ids repeat across tenant databases.
     */
    private static function throttleKey(Authenticatable $user, ?string $actionName): string
    {
        $tenantId = function_exists('tenant') && tenancy()->initialized
            ? (string) tenant()?->getTenantKey()
            : 'central';

        return 'regulatory-compliance:'.$tenantId.':'
            .$user->getAuthIdentifier().':'
            .($actionName ?? 'unscoped');
    }

    private static function maxAttempts(): int
    {
        return max(1, (int) config(
            'tracepharma.regulatory_compliance.max_attempts',
            self::DEFAULT_MAX_ATTEMPTS,
        ));
    }

    private static function lockoutSeconds(): int
    {
        return max(1, (int) config(
            'tracepharma.regulatory_compliance.lockout_seconds',
            self::DEFAULT_LOCKOUT_SECONDS,
        ));
    }
}
