<?php

namespace App\Filament\App\Pages;

use App\Enums\ComplianceReportType;
use App\Models\Epcis\EpcisDocument;
use App\Models\User;
use App\Services\Dscsa\AuditPackageZipGenerator;
use App\Services\Dscsa\DscsaComplianceReportGenerator;
use App\Services\Dscsa\TiHistoryExportGenerator;
use App\Services\Dscsa\TransactionReportGenerator;
use App\Support\Auth\SiteAccess;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ComplianceReports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Compliance reports';

    protected static ?string $title = 'Compliance reports';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.compliance-reports';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsComplianceReports())
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public function mount(): void
    {
        $this->form->fill([
            'report_type' => ComplianceReportType::TransactionReport->value,
            'document_id' => request()->integer('document_id') ?: null,
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Generate TracePharma DSCSA PDF, CSV, and ZIP audit exports from a parsed inbound EPCIS document you can access.';
    }

    /**
     * @return list<array{type: string, label: string, description: string}>
     */
    public function reportCatalog(): array
    {
        return collect(ComplianceReportType::cases())
            ->map(fn (ComplianceReportType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ])
            ->all();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Generate export')
                    ->description('Pick a report type and a parsed or validated inbound EPCIS document.')
                    ->schema([
                        Select::make('report_type')
                            ->label('Report type')
                            ->options(collect(ComplianceReportType::cases())->mapWithKeys(
                                fn (ComplianceReportType $type): array => [$type->value => $type->label()]
                            ))
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('document_id')
                            ->label('EPCIS document')
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->getSearchResultsUsing(function (?string $search): array {
                                $query = $this->accessibleDocumentsQuery();

                                if (filled($search)) {
                                    $query->where(function (Builder $inner) use ($search): void {
                                        $inner->where('original_filename', 'like', '%'.$search.'%')
                                            ->orWhere('asn_number', 'like', '%'.$search.'%')
                                            ->orWhere('customer_po', 'like', '%'.$search.'%');

                                        if (is_numeric($search)) {
                                            $inner->orWhere('id', (int) $search);
                                        }
                                    });
                                }

                                return $query
                                    ->latest('id')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (EpcisDocument $document): array => [
                                        $document->id => $this->documentOptionLabel($document),
                                    ])
                                    ->all();
                            })
                            ->getOptionLabelUsing(function (mixed $value): ?string {
                                if (! filled($value)) {
                                    return null;
                                }

                                $document = $this->accessibleDocumentsQuery()->find($value);

                                return $document === null ? null : $this->documentOptionLabel($document);
                            }),
                    ])
                    ->columns(2),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        $reportType = ComplianceReportType::tryFrom((string) ($this->data['report_type'] ?? ''));

        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('generateReport')
            ->footer([
                Actions::make([
                    Action::make('generateReport')
                        ->label($reportType?->downloadLabel() ?? 'Download')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->submit('generateReport'),
                ])->key('form-actions'),
            ]);
    }

    public function generateReport(): ?StreamedResponse
    {
        $state = $this->form->getState();
        $reportType = ComplianceReportType::tryFrom((string) ($state['report_type'] ?? ''));
        $documentId = (int) ($state['document_id'] ?? 0);

        if ($reportType === null || $documentId < 1) {
            Notification::make()
                ->title('Report selection required')
                ->body('Choose a report type and EPCIS document.')
                ->warning()
                ->send();

            return null;
        }

        $document = $this->accessibleDocumentsQuery()->find($documentId);

        if ($document === null) {
            Notification::make()
                ->title('Document unavailable')
                ->body('Select a parsed or validated EPCIS document you can access.')
                ->danger()
                ->send();

            return null;
        }

        $actor = Auth::user();
        if (! $actor instanceof User) {
            return null;
        }

        $result = match ($reportType) {
            ComplianceReportType::TransactionReport => app(TransactionReportGenerator::class)->generate($document, $actor),
            ComplianceReportType::DscsaComplianceReport => app(DscsaComplianceReportGenerator::class)->generate($document, $actor),
            ComplianceReportType::TiHistory => app(TiHistoryExportGenerator::class)->generate($document, $actor),
            ComplianceReportType::AuditPackage => app(AuditPackageZipGenerator::class)->generate($document, $actor),
        };

        $contentType = $result['content_type'] ?? $reportType->contentType();

        activity()
            ->performedOn($document)
            ->causedBy($actor)
            ->withProperties([
                'report_type' => $reportType->value,
                'filename' => $result['filename'],
            ])
            ->log('Downloaded compliance report from hub');

        return response()->streamDownload(
            static function () use ($result): void {
                echo $result['binary'];
            },
            $result['filename'],
            ['Content-Type' => $contentType],
        );
    }

    /**
     * @return Builder<EpcisDocument>
     */
    private function accessibleDocumentsQuery(): Builder
    {
        $query = EpcisDocument::query()
            ->where('direction', 'inbound')
            ->whereIn('status', ['parsed', 'validated']);

        return SiteAccess::constrainShipToSite($query);
    }

    private function documentOptionLabel(EpcisDocument $document): string
    {
        $parts = array_filter([
            '#'.$document->id,
            $document->original_filename,
            $document->customer_po ? 'PO '.$document->customer_po : null,
            $document->asn_number ? 'ASN '.$document->asn_number : null,
            $document->status,
        ]);

        return implode(' · ', $parts);
    }
}
