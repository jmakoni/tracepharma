<?php

namespace App\Filament\App\Resources\BuyingGroupMembers\Pages;

use App\Enums\BuyingGroupMemberStatus;
use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Models\BuyingGroupMember;
use App\Support\BuyingGroup\BuyingGroupEnrollmentAnalytics;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBuyingGroupMembers extends ListRecords
{
    protected static string $resource = BuyingGroupMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->tooltip('Download the member roster as CSV for GPO / partner onboarding.')
                ->action(fn (): StreamedResponse => $this->exportMembersCsv()),
            CreateAction::make(),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $summary = app(BuyingGroupEnrollmentAnalytics::class)->summarize();

        return sprintf(
            'Enrollment: %d soft · %d hard-linked · %s%% of roster rows have an affiliation code',
            $summary['soft_linked'],
            $summary['hard_linked'],
            rtrim(rtrim(number_format($summary['affiliation_code_pct'], 1, '.', ''), '0'), '.') ?: '0',
        );
    }

    private function exportMembersCsv(): StreamedResponse
    {
        $filename = 'buying-group-members-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'id',
                'name',
                'external_ref',
                'member_tenant_id',
                'status',
                'contact_email',
                'dea_number',
                'npi',
                'state_license_ref',
                'primary_gln',
                'affiliation_code',
                'program_sku',
                'sites_count',
                'notes',
                'updated_at',
            ]);

            BuyingGroupMember::query()
                ->orderBy('name')
                ->orderBy('id')
                ->cursor()
                ->each(function (BuyingGroupMember $member) use ($out): void {
                    fputcsv($out, [
                        $member->getKey(),
                        $member->name,
                        $member->external_ref,
                        $member->member_tenant_id,
                        $member->status instanceof BuyingGroupMemberStatus
                            ? $member->status->value
                            : (string) $member->status,
                        $member->contact_email,
                        $member->dea_number,
                        $member->npi,
                        $member->state_license_ref,
                        $member->primary_gln,
                        $member->affiliation_code,
                        $member->program_sku,
                        $member->sites_count,
                        $member->notes,
                        optional($member->updated_at)?->toIso8601String(),
                    ]);
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
