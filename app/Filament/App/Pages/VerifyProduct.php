<?php

namespace App\Filament\App\Pages;

use App\Actions\Vrs\RunProductVerification;
use App\Exceptions\VrsConfigurationException;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Filament\Notifications\Notification;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\Gtin;
use App\Support\TenantFeatures;
use App\Support\Vrs\VerificationScorecardMetrics;
use Filament\Pages\Page;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnitEnum;

class VerifyProduct extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Dispense / verify';

    protected static ?string $title = 'Dispense / verify';

    protected static ?int $navigationSort = 25;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected string $view = 'filament.app.pages.verify-product';

    public ?string $scan = '';

    public ?string $lastScanTone = null;

    public ?string $lastScanMessage = null;

    public ?string $lastScanDetail = null;

    public ?int $lastVerificationId = null;

    public ?int $lastExceptionId = null;

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && JobRoleAccess::allows(Permissions::NavVerify);
    }

    public function mount(): void
    {
        $barcode = request()->query('barcode');
        $gtin = request()->query('gtin');
        $serial = request()->query('serial');

        if (filled($barcode)) {
            $this->scan = (string) $barcode;
        } elseif (filled($gtin) && filled($serial)) {
            $gtin14 = Gtin::fromUpc((string) $gtin);
            if ($gtin14 !== null) {
                $this->scan = '(01)'.$gtin14.'(21)'.(string) $serial;
            }
        }

        if (filled($this->scan)) {
            $this->verifyScan(app(RunProductVerification::class));
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scan a unit label to verify with the Verification Router Service, or call POST /api/v1/dispense-check from your PMS.';
    }

    public function showScorecard(): bool
    {
        $user = auth()->user();

        return TenantFeatures::forTenant(tenant())->supportsVrs()
            && $user instanceof User
            && $user->can(Permissions::SitesAccessAll);
    }

    /**
     * @return array{
     *     allowed: int,
     *     blocked: int,
     *     deferred: int,
     *     unavailable: int,
     *     since: string
     * }|null
     */
    public function scorecardMetrics(): ?array
    {
        if (! $this->showScorecard()) {
            return null;
        }

        return app(VerificationScorecardMetrics::class)->handle();
    }

    public function verifyScan(?string $raw = null): void
    {
        if ($raw !== null) {
            $this->scan = ElementString::normalize(trim($raw));
        }

        $verification = app(RunProductVerification::class);

        $scan = ElementString::normalize(trim((string) $this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            $this->setLastScan('error', 'Scan a product barcode to verify.');

            Notification::make()
                ->title('Scan required')
                ->danger()
                ->send();

            $this->dispatch('focus-scan');

            return;
        }

        try {
            $result = $verification->handle($scan, auth()->user());

            $this->scan = '';
            $this->lastVerificationId = (int) $result['verification']->getKey();
            $this->lastExceptionId = $result['exception_id'];

            $detail = $result['verification']->gtin14.' · '.$result['verification']->serial;
            if (filled($result['verification']->lot)) {
                $detail .= ' · Lot '.$result['verification']->lot;
            }

            $this->setLastScan($result['tone'], $result['title'].' — '.$result['body'], $detail);

            $notification = Notification::make()
                ->title($result['title'])
                ->body($result['body']);

            match ($result['tone']) {
                'ok' => $notification->success(),
                'warn' => $notification->warning(),
                default => $notification->danger(),
            };

            $notification->send();

            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: $result['tone']);
        } catch (InvalidArgumentException $exception) {
            $this->setLastScan('error', $exception->getMessage());

            Notification::make()
                ->title('Invalid scan')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');
        } catch (VrsConfigurationException $exception) {
            $this->setLastScan('error', $exception->getMessage());

            Notification::make()
                ->title('VRS not configured')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');
        }
    }

    /**
     * @return Collection<int, Verification>
     */
    public function todaysVerifications()
    {
        return SiteAccess::constrainVerifications(
            Verification::query()
                ->select([
                    'id',
                    'gtin14',
                    'serial',
                    'lot',
                    'status',
                    'scanned_barcode',
                    'verified_by',
                    'created_at',
                    'exception_id',
                ])
                ->whereDate('created_at', today())
                ->with(['verifiedByUser:id,name'])
                ->orderByDesc('created_at')
                ->limit(15),
            'exception',
        )->get();
    }

    /**
     * Rows for the shared scanner confirmed table (status variant).
     *
     * @return Collection<int, array{
     *     line_id: int,
     *     identifier: string,
     *     scanned_at: string,
     *     urn: string,
     *     present: bool,
     *     status_label: string,
     *     status_badge_class: string
     * }>
     */
    public function verificationTableRows(): Collection
    {
        return $this->todaysVerifications()
            ->map(function (Verification $verification): array {
                $identifier = filled($verification->scanned_barcode)
                    ? (string) $verification->scanned_barcode
                    : ((filled($verification->gtin14) && filled($verification->serial))
                        ? '(01)'.$verification->gtin14.'(21)'.$verification->serial
                        : (string) ($verification->gtin14 ?? '—'));

                $detail = trim(implode(' · ', array_filter([
                    $verification->gtin14,
                    $verification->serial,
                    filled($verification->lot) ? 'Lot '.$verification->lot : null,
                ], static fn (?string $part): bool => filled($part))));

                return [
                    'line_id' => (int) $verification->getKey(),
                    'identifier' => $identifier !== '' ? $identifier : '—',
                    'scanned_at' => $verification->created_at?->format('Y-m-d H:i:s') ?? '—',
                    'urn' => $detail !== '' ? $detail : '—',
                    'present' => true,
                    'status_label' => $this->statusLabel((string) $verification->status),
                    'status_badge_class' => $this->statusBadgeClass((string) $verification->status),
                ];
            })
            ->values();
    }

    public function exceptionUrl(): ?string
    {
        if ($this->lastExceptionId === null || ! ExceptionResource::canAccess()) {
            return null;
        }

        return ExceptionResource::getUrl('view', ['record' => $this->lastExceptionId]);
    }

    public function verificationHistoryUrl(): ?string
    {
        if (! VerificationResource::canAccess()) {
            return null;
        }

        return VerificationResource::getUrl('index', [
            'tableFilters' => [
                'created_at' => [
                    'from' => today()->toDateString(),
                    'until' => today()->toDateString(),
                ],
            ],
        ]);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'verified' => 'Verified',
            'failed' => 'Failed',
            'suspect' => 'Suspect',
            'deferred' => 'Deferred',
            'unavailable' => 'VRS unavailable',
            'quarantined' => 'Quarantined',
            'error' => 'Error',
            default => ucfirst($status),
        };
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'verified' => 'badge-success',
            'deferred' => 'badge-warning',
            'unavailable' => 'badge-warning',
            'suspect' => 'badge-warning',
            'quarantined' => 'badge-error',
            default => 'badge-error',
        };
    }

    private function setLastScan(string $tone, string $message, ?string $detail = null): void
    {
        $this->lastScanTone = $tone;
        $this->lastScanMessage = $message;
        $this->lastScanDetail = $detail;
    }

    public static function getDocumentation(): array|string
    {
        return 'workflows.verify-product';
    }
}
