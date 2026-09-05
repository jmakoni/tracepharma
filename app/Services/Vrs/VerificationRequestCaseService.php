<?php

declare(strict_types=1);

namespace App\Services\Vrs;

use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Enums\VerificationRequestCaseStatus;
use App\Enums\VerificationRequestOutcome;
use App\Enums\VerificationRequestReason;
use App\Enums\VerificationRequestTrigger;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Product;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationRequestCase;
use App\Models\VerificationRequestResponse;
use App\Notifications\ManufacturerVerificationRequestMail;
use App\Notifications\VerificationRequestPositiveConfirmationMail;
use App\Services\Exceptions\ExceptionService;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class VerificationRequestCaseService
{
    public function __construct(
        private readonly ManufacturerVerificationNotifier $manufacturerNotifier,
        private readonly ResolveProductFromIdentifier $resolveProduct,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * @return array{case: VerificationRequestCase, secure_code: string}
     */
    public function openFromVerification(
        Verification $verification,
        VerificationRequestTrigger $trigger,
        ?User $actor = null,
        ?string $notes = null,
    ): array {
        if (! TenantFeatures::forTenant(tenant())->supportsManufacturerVerificationPortal()) {
            throw ValidationException::withMessages([
                'portal' => 'Manufacturer verification portal is not enabled for this organization.',
            ]);
        }

        $existing = VerificationRequestCase::query()
            ->where('verification_id', $verification->getKey())
            ->where('status', VerificationRequestCaseStatus::Pending)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'verification' => 'An open manufacturer verification request already exists for this scan.',
            ]);
        }

        $manufacturerEmail = $this->manufacturerNotifier->resolveManufacturerEmail($verification->gtin14);
        if ($manufacturerEmail === null) {
            throw ValidationException::withMessages([
                'manufacturer' => 'No manufacturer notify email is configured for this GTIN. Set vrs_notify_email on the manufacturer trading partner or link FDA labeler data.',
            ]);
        }

        $product = $this->resolveProduct->handle(gtin14: $verification->gtin14);
        $settings = TenantSettings::forTenant(tenant());
        $secureCode = (string) Str::uuid();
        $ttlDays = max(1, $settings->manufacturerVerificationPortalTtlBusinessDays());

        $case = VerificationRequestCase::query()->make([
            'verification_id' => $verification->getKey(),
            'exception_id' => $verification->exception_id,
            'manufacturer_trading_partner_id' => $this->manufacturerNotifier->resolveManufacturerPartnerId($verification->gtin14),
            'requestor_name' => (string) (tenant()?->name ?? 'Trading partner'),
            'requestor_gln' => $settings->gln(),
            'requestor_license' => $settings->stateLicenseNumber(),
            'requestor_notify_email' => $this->resolveRequestorNotifyEmail($actor, $settings),
            'vendor_number' => $settings->vendorNumber(),
            'gtin14' => $verification->gtin14,
            'serial' => $verification->serial,
            'lot' => $verification->lot,
            'expiry_yymmdd' => data_get($verification->response_payload, 'expiry_yymmdd'),
            'ndc11' => $product?->ndc11,
            'product_description' => $this->productDescription($product),
            'cin' => null,
            'trigger_reason' => $trigger,
            'notes' => $notes ?? $verification->message,
            'created_by' => $actor?->getKey(),
        ]);

        $case->forceFill([
            'uuid' => (string) Str::uuid(),
            'secure_code_hash' => Hash::make($secureCode),
            'status' => VerificationRequestCaseStatus::Pending,
            'expires_at' => now()->addWeekdays($ttlDays),
        ])->save();

        $verification->forceFill(['verification_request_case_id' => $case->getKey()])->save();

        $this->sendRequestMail($case, $manufacturerEmail, $secureCode);

        return ['case' => $case->refresh(), 'secure_code' => $secureCode];
    }

    public function caseUrl(VerificationRequestCase $case): string
    {
        return route('tenant.verification-request.show', ['caseUuid' => $case->uuid]);
    }

    public function verifySecureCode(VerificationRequestCase $case, string $secureCode): bool
    {
        return Hash::check(trim($secureCode), $case->secure_code_hash);
    }

    /**
     * @param  array{outcome: string, reason_code: string, comments?: ?string, responder_email: string, terms_accepted: bool}  $data
     */
    public function submitResponse(
        VerificationRequestCase $case,
        array $data,
        ?UploadedFile $attachment = null,
        ?string $responderIp = null,
    ): VerificationRequestResponse {
        if (! $case->isPending()) {
            if ($case->status === VerificationRequestCaseStatus::Responded) {
                throw ValidationException::withMessages([
                    'case' => 'This verification request has already been answered.',
                ]);
            }

            throw ValidationException::withMessages([
                'case' => 'This verification request is no longer open.',
            ]);
        }

        $outcome = VerificationRequestOutcome::from($data['outcome']);
        $reason = VerificationRequestReason::from($data['reason_code']);

        if (! ($data['terms_accepted'] ?? false)) {
            throw ValidationException::withMessages([
                'terms_accepted' => 'You must accept the terms before submitting.',
            ]);
        }

        $attachmentPath = null;
        if ($attachment !== null) {
            $attachmentPath = $attachment->store('verification-request/'.$case->uuid, 'local');
        }

        return DB::transaction(function () use ($case, $data, $outcome, $reason, $attachmentPath, $responderIp): VerificationRequestResponse {
            $response = VerificationRequestResponse::query()->create([
                'verification_request_case_id' => $case->getKey(),
                'outcome' => $outcome,
                'reason_code' => $reason,
                'comments' => filled($data['comments'] ?? null) ? (string) $data['comments'] : null,
                'responder_email' => strtolower(trim((string) $data['responder_email'])),
                'responder_ip' => $responderIp,
                'attachment_path' => $attachmentPath,
                'terms_accepted_at' => now(),
            ]);

            $case->forceFill([
                'status' => VerificationRequestCaseStatus::Responded,
                'responded_at' => now(),
            ])->save();

            $verification = $case->verification()->first();
            if ($verification !== null) {
                $this->applyOutcomeToVerification($verification, $case, $response);
            }

            if ($outcome === VerificationRequestOutcome::Positive) {
                $this->sendPositiveConfirmationMail($case->refresh());
            }

            return $response;
        });
    }

    public function rotateSecureCode(VerificationRequestCase $case): string
    {
        if ($case->status !== VerificationRequestCaseStatus::Pending) {
            throw new RuntimeException('Only pending cases can rotate the secure code.');
        }

        $secureCode = (string) Str::uuid();
        $case->forceFill(['secure_code_hash' => Hash::make($secureCode)])->save();

        $email = $this->manufacturerNotifier->resolveManufacturerEmail($case->gtin14);
        if ($email !== null) {
            $this->sendRequestMail($case->refresh(), $email, $secureCode);
        }

        return $secureCode;
    }

    private function applyOutcomeToVerification(
        Verification $verification,
        VerificationRequestCase $case,
        VerificationRequestResponse $response,
    ): void {
        $payload = is_array($verification->response_payload) ? $verification->response_payload : [];
        $payload['manufacturer_portal'] = [
            'case_uuid' => $case->uuid,
            'outcome' => $response->outcome->value,
            'reason_code' => $response->reason_code->value,
            'responder_email' => $response->responder_email,
            'responded_at' => $response->created_at?->toIso8601String(),
        ];

        if ($response->outcome === VerificationRequestOutcome::Positive) {
            $verification->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
                'message' => 'Manufacturer positive verification via portal.',
                'response_payload' => $payload,
            ])->save();

            $exception = $case->exception;
            if ($exception instanceof ExceptionCase) {
                $actor = User::query()->find($case->created_by);
                if ($actor instanceof User) {
                    try {
                        $this->exceptions->close(
                            $exception,
                            $actor,
                            'Manufacturer positive verification via portal.',
                        );
                    } catch (ValidationException) {
                        // Quarantine holds may block auto-close; verification still stands.
                    }
                }
            }

            return;
        }

        $verification->forceFill([
            'status' => 'failed',
            'message' => 'Manufacturer negative verification via portal.',
            'response_payload' => $payload,
        ])->save();
    }

    private function sendRequestMail(VerificationRequestCase $case, string $manufacturerEmail, string $secureCode): void
    {
        Notification::route('mail', $manufacturerEmail)->notify(
            new ManufacturerVerificationRequestMail($case, $this->caseUrl($case), $secureCode),
        );
    }

    private function sendPositiveConfirmationMail(VerificationRequestCase $case): void
    {
        Notification::route('mail', $case->requestor_notify_email)->notify(
            new VerificationRequestPositiveConfirmationMail($case),
        );
    }

    private function resolveRequestorNotifyEmail(?User $actor, TenantSettings $settings): string
    {
        $configured = $settings->vrsVerificationContactEmail();
        if (filled($configured)) {
            return strtolower(trim($configured));
        }

        if ($actor !== null && filled($actor->email)) {
            return strtolower(trim((string) $actor->email));
        }

        throw ValidationException::withMessages([
            'contact' => 'Configure a VRS verification contact email in organization settings before sending manufacturer requests.',
        ]);
    }

    private function productDescription(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $parts = array_filter([
            $product->name,
            $product->strength,
            $product->dosage_form,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
