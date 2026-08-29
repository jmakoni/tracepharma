<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Packing\AuthorTransformationRepack;
use App\Domain\Gs1\Gtin14;
use App\Domain\Gs1\SgtinUri;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

/**
 * Prepackager-only MVP: author a TransformationEvent (input EPCs → output SGTINs).
 * Pack / BreakPack remain aggregation tools — this page does not redesign them.
 *
 * @property-read Schema $form
 */
class RepackTransformWorkstation extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingIn;

    protected static ?string $navigationLabel = 'Repack (transform)';

    protected static ?string $title = 'Repack (transform)';

    protected static ?int $navigationSort = 15;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.repack-transform-workstation';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'repack-transform';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsRepackTransform()
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $siteId = CurrentSite::id()
            ?? TenantSettings::forTenant(tenant())->defaultShipFromSiteId();

        $this->form->fill([
            'site_id' => $siteId,
            'input_epc_urns' => '',
            'output_gtin' => '',
            'output_serials' => '',
            'output_epc_urns' => '',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Author a TransformationEvent (input → output SGTINs). Pack/BreakPack stay aggregation; original-link TI is deferred.';
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Repack transform')
                    ->description('One TransformationEvent linking on-hand inputs to new or existing output SGTINs.')
                    ->compact()
                    ->columns(2)
                    ->schema([
                        Select::make('site_id')
                            ->label('Site')
                            ->options(fn (): array => $this->siteOptions())
                            ->required()
                            ->searchable()
                            ->columnSpanFull(),
                        Textarea::make('input_epc_urns')
                            ->label('Input EPC URNs')
                            ->helperText('One SGTIN/SSCC Pure Identity URN (or GS1 element string) per line. Must be on hand at the site.')
                            ->rows(5)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('output_gtin')
                            ->label('Output GTIN-14')
                            ->helperText('Optional when output URNs are provided below.')
                            ->maxLength(14),
                        Textarea::make('output_serials')
                            ->label('Output serials')
                            ->helperText('One serial per line; combined with Output GTIN-14 and org company prefix.')
                            ->rows(4),
                        Textarea::make('output_epc_urns')
                            ->label('Output SGTIN URNs (optional)')
                            ->helperText('Or paste full urn:epc:id:sgtin:… lines instead of GTIN+serials.')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('repackTransformForm')
                    ->livewireSubmitHandler('submit')
                    ->footer([
                        Action::make('submit')
                            ->label('Author transformation')
                            ->submit('submit')
                            ->color('primary'),
                    ]),
            ]);
    }

    public function submit(
        AuthorTransformationRepack $author,
        ResolveEpcFromScan $resolveEpcFromScan,
    ): void {
        try {
            $state = $this->form->getState();
            $siteId = (int) ($state['site_id'] ?? 0);
            if ($siteId <= 0) {
                throw ValidationException::withMessages(['data.site_id' => 'Select a site.']);
            }

            $this->assertSiteAccess($siteId);

            $inputIds = $this->resolveInputEpcIds(
                (string) ($state['input_epc_urns'] ?? ''),
                $resolveEpcFromScan,
            );
            $outputUris = $this->resolveOutputUris($state);

            $result = $author->handle(
                siteId: $siteId,
                inputEpcIds: $inputIds,
                outputUris: $outputUris,
                options: ['sync' => true, 'dispatch' => true],
            );

            Notification::make()
                ->title('Transformation authored')
                ->body(
                    'Document #'.$result['document']->getKey()
                    .' — '.$result['input_count'].' input(s) → '.$result['output_count'].' output(s).'
                    .' ID '.$result['transformation_id'],
                )
                ->success()
                ->send();

            $this->form->fill([
                'site_id' => $siteId,
                'input_epc_urns' => '',
                'output_gtin' => '',
                'output_serials' => '',
                'output_epc_urns' => '',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Cannot transform')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Transformation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, string>
     */
    private function siteOptions(): array
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return [];
        }

        return EligibleReceiveSites::options($user);
    }

    private function assertSiteAccess(int $siteId): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['data.site_id' => 'Sign in required.']);
        }

        if (! SiteAccess::allows($user, $siteId)) {
            throw ValidationException::withMessages(['data.site_id' => 'You do not have access to that site.']);
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site || ! $site->is_active) {
            throw ValidationException::withMessages(['data.site_id' => 'Site is missing or inactive.']);
        }
    }

    /**
     * @return list<int>
     */
    private function resolveInputEpcIds(string $raw, ResolveEpcFromScan $resolveEpcFromScan): array
    {
        $lines = preg_split('/\R+/', $raw) ?: [];
        $ids = [];

        foreach ($lines as $line) {
            $scan = ElementString::normalize(trim($line));
            if ($scan === '') {
                continue;
            }

            $resolved = $resolveEpcFromScan->handle($scan);
            $epc = $resolved['epc'] ?? null;
            if ($epc === null || blank($epc->epc_uri)) {
                throw ValidationException::withMessages([
                    'data.input_epc_urns' => "No EPC found for: {$scan}",
                ]);
            }

            $ids[] = (int) $epc->getKey();
        }

        if ($ids === []) {
            throw ValidationException::withMessages([
                'data.input_epc_urns' => 'Enter at least one input EPC URN or barcode.',
            ]);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function resolveOutputUris(array $state): array
    {
        $uris = [];
        $rawUrns = (string) ($state['output_epc_urns'] ?? '');
        foreach (preg_split('/\R+/', $rawUrns) ?: [] as $line) {
            $uri = trim($line);
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        $gtin = preg_replace('/\D+/', '', (string) ($state['output_gtin'] ?? '')) ?? '';
        $serialsRaw = (string) ($state['output_serials'] ?? '');
        $serials = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s),
            preg_split('/\R+/', $serialsRaw) ?: [],
        )));

        if ($gtin !== '' || $serials !== []) {
            if (strlen($gtin) !== 14) {
                throw ValidationException::withMessages([
                    'data.output_gtin' => 'Output GTIN-14 is required with serials (14 digits).',
                ]);
            }

            if ($serials === []) {
                throw ValidationException::withMessages([
                    'data.output_serials' => 'Enter at least one output serial when using GTIN.',
                ]);
            }

            $prefix = (string) (TenantSettings::forTenant(tenant())->companyPrefix() ?? '');
            if ($prefix === '') {
                throw ValidationException::withMessages([
                    'data.output_gtin' => 'Configure organization company prefix before building SGTINs from GTIN+serial.',
                ]);
            }

            try {
                $gtin14 = Gtin14::fromDigits($gtin);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'data.output_gtin' => $e->getMessage(),
                ]);
            }

            foreach ($serials as $serial) {
                try {
                    $uris[] = (string) SgtinUri::fromGtinAndSerial($gtin14, $serial, $prefix);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'data.output_serials' => $e->getMessage(),
                    ]);
                }
            }
        }

        $uris = array_values(array_unique($uris));
        if ($uris === []) {
            throw ValidationException::withMessages([
                'data.output_epc_urns' => 'Provide output SGTIN URNs and/or GTIN-14 + serials.',
            ]);
        }

        return $uris;
    }

    public static function getDocumentation(): array|string
    {
        return 'workflows.repack-transform';
    }
}
