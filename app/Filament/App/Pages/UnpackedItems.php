<?php

namespace App\Filament\App\Pages;

use App\Models\Epcis\Epc;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Packing\UnpackedNotRepackedQuery;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\Tracing\Gs1DualDisplay;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class UnpackedItems extends Page implements HasKnowledgeBase, HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Unpacked items';

    protected static ?string $title = 'Unpacked items';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.unpacked-items';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'unpacked-items';
    }

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());
        $policy = ReceivingPolicy::forTenant(tenant());

        return ($features->supportsUnpacking()
            || $features->supportsPacking()
            || $policy->canUnpackAtReceive())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Items released by unpack that are not under a parent again. Pack them from Pack.';
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
            ->query(fn (): Builder => $this->tableQuery())
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identifier')
                    ->state(fn (Epc $record): string => Gs1DualDisplay::forEpc($record)['primary'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $inner) use ($like, $search): void {
                            $inner->where('epcs.epc_uri', 'like', $like)
                                ->orWhere('epcs.sscc18', 'like', $like)
                                ->orWhere('epcs.serial_number', 'like', $like)
                                ->orWhere('epcs.ai_01_21', 'like', $like)
                                ->orWhere('epcs.epc_uri', $search)
                                ->orWhere('epcs.sscc18', $search)
                                ->orWhere('epcs.ai_01_21', $search);
                        });
                    })
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->wrap(),
                TextColumn::make('epc_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('former_parent_uri')
                    ->label('Former parent')
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('latest_unpacked_at')
                    ->label('Unpacked at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('open_children_count')
                    ->label('Open children')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('epc_type')
                    ->label('Type')
                    ->options([
                        'sscc' => 'SSCC',
                        'sgtin' => 'SGTIN',
                    ]),
                TernaryFilter::make('still_holds_children')
                    ->label('Still holds children')
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereExists(function ($exists): void {
                            $exists->selectRaw('1')
                                ->from('aggregation_links')
                                ->whereColumn('aggregation_links.parent_epc_id', 'epcs.id')
                                ->whereNull('aggregation_links.valid_to');
                        }),
                        false: fn (Builder $query): Builder => $query->whereNotExists(function ($exists): void {
                            $exists->selectRaw('1')
                                ->from('aggregation_links')
                                ->whereColumn('aggregation_links.parent_epc_id', 'epcs.id')
                                ->whereNull('aggregation_links.valid_to');
                        }),
                    ),
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => EligibleReceiveSites::options())
                    ->searchable()
                    ->default(fn (): ?int => CurrentSite::id())
                    ->query(function (Builder $query, array $data): Builder {
                        $selected = filled($data['value'] ?? null) ? (int) $data['value'] : null;
                        $user = auth()->user();

                        if ($user instanceof User && ! $user->can(Permissions::SitesAccessAll)) {
                            $allowed = SiteAccess::userSiteIds($user);
                            if ($selected !== null && $allowed->contains($selected)) {
                                return UnpackedNotRepackedQuery::applySiteConstraint($query, $selected);
                            }

                            return UnpackedNotRepackedQuery::applySitesConstraint($query, $allowed->all());
                        }

                        if ($selected === null) {
                            return $query;
                        }

                        return UnpackedNotRepackedQuery::applySiteConstraint($query, $selected);
                    }),
            ])
            ->headerActions([
                Action::make('openPack')
                    ->label('Open Pack')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->url(fn (): string => PackWorkstation::getUrl(panel: 'app'))
                    ->visible(fn (): bool => PackWorkstation::canAccess()),
            ])
            ->recordActions([
                Action::make('trace')
                    ->label('Trace')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->url(fn (Epc $record): string => AssetTracking::getUrl([
                        'scan' => $this->scanFor($record),
                    ], panel: 'app')),
            ])
            ->defaultSort('latest_unpacked_at', 'desc')
            ->paginated([10, 25, 50])
            ->extremePaginationLinks()
            ->emptyStateHeading('No unpacked items')
            ->emptyStateDescription('Items appear here after unpack until they are packed again.');
    }

    /** @return Builder<Epc> */
    private function tableQuery(): Builder
    {
        $query = UnpackedNotRepackedQuery::builder(null)
            ->select('epcs.*')
            ->selectSub($this->latestUnpackEventTimeSubquery(), 'latest_unpacked_at')
            ->selectSub($this->formerParentLabelSubquery(), 'former_parent_uri')
            ->withCount([
                'aggregationLinksAsParent as open_children_count' => fn (Builder $query): Builder => $query->whereNull('valid_to'),
            ]);

        $user = auth()->user();
        if ($user instanceof User && ! $user->can(Permissions::SitesAccessAll)) {
            UnpackedNotRepackedQuery::applySitesConstraint($query, SiteAccess::userSiteIds($user)->all());
        }

        return $query;
    }

    private function latestUnpackEventTimeSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->whereColumn('ee.epc_id', 'epcs.id')
            ->where('ee.role', 'childEPC')
            ->where('ev.event_type', 'AggregationEvent')
            ->where('ev.action', 'DELETE')
            ->where(function ($biz): void {
                $biz->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                    ->orWhere('ev.biz_step', 'unpacking');
            })
            ->orderByDesc('ev.event_time')
            ->limit(1)
            ->select('ev.event_time');
    }

    private function formerParentLabelSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->join('event_epcs as pe', function ($join): void {
                $join->on('pe.event_id', '=', 'ee.event_id')
                    ->where('pe.role', '=', 'parentID');
            })
            ->join('epcs as parent_epcs', 'parent_epcs.id', '=', 'pe.epc_id')
            ->whereColumn('ee.epc_id', 'epcs.id')
            ->where('ee.role', 'childEPC')
            ->where('ev.event_type', 'AggregationEvent')
            ->where('ev.action', 'DELETE')
            ->where(function ($biz): void {
                $biz->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                    ->orWhere('ev.biz_step', 'unpacking');
            })
            ->orderByDesc('ev.event_time')
            ->limit(1)
            ->selectRaw('COALESCE(parent_epcs.sscc18, parent_epcs.ai_01_21, parent_epcs.epc_uri)');
    }

    private function scanFor(Epc $epc): string
    {
        if (filled($epc->sscc18)) {
            return (string) $epc->sscc18;
        }

        if (filled($epc->ai_01_21)) {
            return (string) $epc->ai_01_21;
        }

        return (string) $epc->epc_uri;
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.on-hand-and-unpacked';
    }
}
