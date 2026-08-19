<?php

namespace App\Actions\Fda3911;

use App\Enums\ExceptionDisposition;
use App\Enums\Fda3911Classification;
use App\Enums\Fda3911ReportStatus;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Fda3911Report;
use App\Models\Product;
use App\Models\TradingPartner;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;

class PrefillFda3911Report
{
    public function execute(
        User $user,
        ExceptionCase $exception,
        ?TradingPartner $tradingPartner = null,
    ): Fda3911Report {
        $exception->loadMissing(['epcs.ilmd', 'epcs.product', 'tradingPartner', 'type']);

        $verification = Verification::query()
            ->where('exception_id', $exception->getKey())
            ->latest('id')
            ->first();

        $epc = $exception->epcs->first(
            fn (Epc $candidate): bool => filled($candidate->gtin14)
        ) ?? $exception->epcs->first();

        $gtin = $verification?->gtin14 ?? $epc?->gtin14;
        $serial = $verification?->serial ?? $epc?->serial_number;
        $lot = $verification?->lot
            ?? $epc?->ilmd?->lot_number;

        $product = $epc?->product
            ?? ($gtin !== null
                ? Product::query()->where('gtin', $gtin)->first()
                : null);

        $tradingPartner ??= $exception->tradingPartner;
        $determinedAt = $exception->resolved_at ?? now();
        $classification = $this->deriveClassification($exception);
        $circumstances = $this->buildCircumstances($exception, $verification);

        return DB::transaction(function () use (
            $user,
            $exception,
            $verification,
            $tradingPartner,
            $product,
            $gtin,
            $serial,
            $lot,
            $circumstances,
            $determinedAt,
            $classification,
        ): Fda3911Report {
            $report = Fda3911Report::query()->create([
                'status' => Fda3911ReportStatus::Draft,
                'classification' => $classification,
                'verification_id' => $verification?->getKey(),
                'exception_id' => $exception->getKey(),
                'trading_partner_id' => $tradingPartner?->getKey(),
                'determined_at' => $determinedAt,
                'due_at' => $determinedAt->copy()->addHours(24),
                'notifier_name' => $user->name,
                'notifier_email' => $user->email,
                'facility_name' => tenant('name'),
                'facility_gln' => tenant('gln'),
                'product_ndc' => $product?->ndc11 ?? $product?->ndc ?? $product?->package_ndc,
                'product_name' => $product?->name ?? 'Unknown product',
                'product_gtin' => $gtin,
                'lot' => $lot,
                'serial' => $serial,
                'strength' => $product?->strength,
                'dosage_form' => $product?->dosage_form,
                'circumstances' => $circumstances,
                'metadata' => array_filter([
                    'verification_status' => $verification?->status,
                    'verification_message' => $verification?->message,
                    'exception_type' => $exception->type?->code,
                    'exception_disposition' => $exception->disposition?->value,
                    'epc_id' => $exception->epcs->first()?->getKey(),
                    'requires_human_review' => true,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
                'created_by' => $user->id,
            ]);

            app(GenerateFda3911Pdf::class)->execute($report);

            return $report->refresh();
        });
    }

    /**
     * DSCSA distinguishes "suspect" (investigation underway) from "illegitimate"
     * (determination complete). Illegitimate disposition or a resolved case supports
     * the Illegitimate classification; otherwise High risk of illegitimacy.
     */
    private function deriveClassification(ExceptionCase $exception): Fda3911Classification
    {
        if ($exception->disposition === ExceptionDisposition::Illegitimate
            || $exception->resolved_at !== null) {
            return Fda3911Classification::Illegitimate;
        }

        return Fda3911Classification::HighRisk;
    }

    private function buildCircumstances(ExceptionCase $exception, ?Verification $verification): string
    {
        $parts = [];

        $parts[] = trim(($exception->title ?? 'Suspect product').': '.($exception->description ?? ''));

        if ($exception->disposition !== null) {
            $parts[] = 'Disposition: '.$exception->disposition->label();
        }

        if (filled($exception->resolution_notes)) {
            $parts[] = 'Resolution notes: '.$exception->resolution_notes;
        }

        if ($verification !== null) {
            $parts[] = 'VRS verification status: '.($verification->status ?? 'unknown');

            if (filled($verification->message)) {
                $parts[] = 'Verification message: '.$verification->message;
            }
        }

        $joined = implode("\n\n", array_filter($parts, fn (string $part): bool => filled(trim($part))));

        return $joined !== ''
            ? $joined
            : 'Suspect or illegitimate product identified during DSCSA investigation.';
    }
}
