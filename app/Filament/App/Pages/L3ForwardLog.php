<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Enums\TenantProfile;
use App\Filament\Notifications\Notification;
use App\Jobs\Labeling\ForwardCommissioningToL3;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use UnitEnum;

class L3ForwardLog extends Page implements HasKnowledgeBase, HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'L3 forward log';

    protected static ?string $title = 'L3 forward log';

    protected static ?int $navigationSort = 18;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.l3-forward-log';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'l3-forward-log';
    }

    public static function canAccess(): bool
    {
        $tenant = tenant();
        if ($tenant === null) {
            return false;
        }

        if ($tenant->profile !== TenantProfile::Manufacturer) {
            return false;
        }

        $features = TenantFeatures::forTenant($tenant);
        $settings = TenantSettings::forTenant($tenant);

        if (! $settings->l3Enabled() && ! $features->supportsCommissioning()) {
            return false;
        }

        return JobRoleAccess::allowsOwnerOrAny(
            Permissions::NavCompliance,
            Permissions::NavIntegrations,
        );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Commissioning EPCIS forward status only — not allocation / Guardian / reconcile.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->forwardLogQuery())
            ->columns([
                TextColumn::make('id')
                    ->label('Document')
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('Filename')
                    ->limit(40)
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('forward_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (EpcisDocument $record): string => $this->forwardStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Forwarded' => 'success',
                        'Failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('l3_forwarded_at')
                    ->label('Forwarded at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label('Last error')
                    ->state(fn (EpcisDocument $record): ?string => $this->lastErrorSnippet($record))
                    ->limit(80)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Retry L3 forward?')
                    ->modalDescription('Re-dispatch ForwardCommissioningToL3 for this commissioning document.')
                    ->visible(fn (EpcisDocument $record): bool => $this->isRetryEligible($record))
                    ->action(function (EpcisDocument $record): void {
                        abort_unless($this->isRetryEligible($record), 403);

                        $tenant = tenant();
                        abort_if($tenant === null, 403);

                        Bus::dispatch(new ForwardCommissioningToL3(
                            (string) $tenant->getKey(),
                            (int) $record->getKey(),
                        ));

                        Notification::make()
                            ->title('L3 forward queued')
                            ->body('ForwardCommissioningToL3 dispatched for document #'.$record->getKey().'.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->extremePaginationLinks()
            ->emptyStateHeading('No L3 forwards yet')
            ->emptyStateDescription('Forwarded commissioning documents and open L3_TRANSMISSION_FAILURE rows appear here.');
    }

    /**
     * @return Builder<EpcisDocument>
     */
    private function forwardLogQuery(): Builder
    {
        return EpcisDocument::query()
            ->with(['exceptions' => function ($query): void {
                $query
                    ->where('exception_type', 'L3_TRANSMISSION_FAILURE')
                    ->where('status', 'open')
                    ->orderByDesc('id');
            }])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('l3_forwarded_at')
                    ->orWhereHas('exceptions', function (Builder $exceptions): void {
                        $exceptions
                            ->where('exception_type', 'L3_TRANSMISSION_FAILURE')
                            ->where('status', 'open');
                    });
            })
            ->orderByDesc('id');
    }

    private function forwardStatus(EpcisDocument $record): string
    {
        if ($this->openL3Failure($record) !== null) {
            return 'Failed';
        }

        if ($record->l3_forwarded_at !== null) {
            return 'Forwarded';
        }

        return 'Pending';
    }

    private function lastErrorSnippet(EpcisDocument $record): ?string
    {
        $description = $this->openL3Failure($record)?->description;

        if (! filled($description)) {
            return null;
        }

        return Str::limit((string) $description, 120);
    }

    private function openL3Failure(EpcisDocument $record): ?EpcisException
    {
        $loaded = $record->relationLoaded('exceptions')
            ? $record->exceptions
            : $record->exceptions()
                ->where('exception_type', 'L3_TRANSMISSION_FAILURE')
                ->where('status', 'open')
                ->orderByDesc('id')
                ->get();

        return $loaded->first(
            fn ($exception): bool => $exception->exception_type === 'L3_TRANSMISSION_FAILURE'
                && $exception->status === 'open',
        );
    }

    private function isRetryEligible(EpcisDocument $record): bool
    {
        if (! filled($record->payload_path)) {
            return false;
        }

        $settings = TenantSettings::forTenant(tenant());

        return $settings->l3Enabled() && filled($settings->l3EndpointUrl());
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.l3-forward-log';
    }
}
