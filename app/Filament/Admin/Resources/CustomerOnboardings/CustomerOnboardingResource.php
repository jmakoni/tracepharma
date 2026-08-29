<?php

namespace App\Filament\Admin\Resources\CustomerOnboardings;

use App\Enums\CustomerOnboardingStatus;
use App\Filament\Admin\Resources\CustomerOnboardings\Pages\ListCustomerOnboardings;
use App\Filament\Admin\Resources\CustomerOnboardings\Pages\ViewCustomerOnboarding;
use App\Models\Admin;
use App\Models\CustomerOnboarding;
use App\Support\Auth\Permissions;
use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerOnboardingResource extends Resource implements HasKnowledgeBase
{
    protected static ?string $model = CustomerOnboarding::class;

    protected static ?string $slug = 'customer-onboardings';

    protected static ?string $navigationLabel = 'Customer onboarding';

    protected static ?string $modelLabel = 'Customer onboarding';

    protected static ?string $pluralModelLabel = 'Customer onboardings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Tenants';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'company_display_name';

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::TenantsManage);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (CustomerOnboardingStatus $state): string => $state->label()),
                        TextEntry::make('legal_company_name'),
                        TextEntry::make('company_display_name'),
                        TextEntry::make('organization_type')
                            ->formatStateUsing(fn (string $state): string => OrganizationTypeMapper::options()[$state] ?? $state),
                        TextEntry::make('gln')
                            ->label('GLN')
                            ->placeholder('—'),
                        TextEntry::make('message')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
                        TextEntry::make('contact_name'),
                        TextEntry::make('contact_email'),
                        TextEntry::make('contact_phone')
                            ->placeholder('—'),
                        TextEntry::make('contact_role')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Legal acceptance (application)')
                    ->schema([
                        TextEntry::make('terms_version'),
                        TextEntry::make('privacy_version'),
                        TextEntry::make('terms_accepted_at')
                            ->dateTime(),
                        TextEntry::make('privacy_accepted_at')
                            ->dateTime(),
                        TextEntry::make('acceptance_ip')
                            ->label('IP address')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Provisioning')
                    ->schema([
                        TextEntry::make('tenant_slug')
                            ->placeholder('—'),
                        TextEntry::make('tenant.name')
                            ->label('Tenant')
                            ->placeholder('Not provisioned'),
                        TextEntry::make('owner_name')
                            ->placeholder('—'),
                        TextEntry::make('owner_email')
                            ->placeholder('—'),
                        TextEntry::make('approved_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('provisioned_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('approvedBy.name')
                            ->label('Approved by')
                            ->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->placeholder('—')
                            ->visible(fn (CustomerOnboarding $record): bool => $record->status === CustomerOnboardingStatus::Rejected)
                            ->columnSpanFull(),
                        TextEntry::make('admin_notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('company_display_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->searchable(),
                TextColumn::make('contact_email')
                    ->searchable(),
                TextColumn::make('organization_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => OrganizationTypeMapper::options()[$state] ?? $state),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerOnboardingStatus $state): string => $state->label()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CustomerOnboardingStatus::cases())->mapWithKeys(
                        fn (CustomerOnboardingStatus $status): array => [$status->value => $status->label()]
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerOnboardings::route('/'),
            'view' => ViewCustomerOnboarding::route('/{record}'),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'tenants.customer-onboarding';
    }
}
