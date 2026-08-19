<?php

namespace App\Support\Tracing;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Transferring\TransferringScanLine;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Context-aware deep links for an instance EPC (receive / transfer / ship / verify).
 *
 * @phpstan-type ContextLink array{
 *     key: string,
 *     label: string,
 *     url: ?string,
 *     opens: bool
 * }
 */
final class EpcContextLinks
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ResolveOpenReceiveUrl $resolveOpenReceiveUrl,
    ) {}

    /**
     * @return list<ContextLink>
     */
    public function forEpc(?Epc $epc, ?string $scan = null, ?int $userId = null): array
    {
        $scan = filled($scan) ? ElementString::normalize(trim((string) $scan)) : null;

        if ($epc === null && filled($scan)) {
            $epc = $this->resolveEpcFromScan->handle((string) $scan)['epc'];
        }

        if ($epc === null) {
            return [];
        }

        $scan ??= AssetTrackingUrl::scanForEpc($epc);

        $links = [];

        if (
            ReceivingSessionResource::canAccess()
            && filled($scan)
            && $this->resolveOpenReceiveUrl->hasContext((string) $scan)
        ) {
            $preview = $this->resolveOpenReceiveUrl->previewUrl((string) $scan);
            $links[] = [
                'key' => 'open_receive',
                'label' => 'Open receive',
                'url' => $preview,
                // When preview is null, the UI must call handle() on click (may open a session).
                'opens' => $preview === null,
            ];
        }

        $transferUrl = $this->transferUrl($epc, $scan, $userId);
        if ($transferUrl !== null) {
            $links[] = [
                'key' => 'open_transfer',
                'label' => 'Open transfer',
                'url' => $transferUrl,
                'opens' => false,
            ];
        }

        $shipUrl = $this->shipUrl($epc, $scan, $userId);
        if ($shipUrl !== null) {
            $links[] = [
                'key' => 'open_ship',
                'label' => 'Open ship',
                'url' => $shipUrl,
                'opens' => false,
            ];
        }

        if (VerifyProduct::canAccess()) {
            $params = VerifyUrlParams::forEpc($epc);
            if ($params !== null) {
                $links[] = [
                    'key' => 'verify_product',
                    'label' => 'Verify product',
                    'url' => VerifyProduct::getUrl($params, panel: 'app'),
                    'opens' => false,
                ];
            }
        }

        return $links;
    }

    /**
     * @return list<ContextLink>
     */
    public function forScan(string $scan, ?int $userId = null): array
    {
        $normalized = ElementString::normalize(trim($scan));

        if ($normalized === '') {
            return [];
        }

        $epc = $this->resolveEpcFromScan->handle($normalized)['epc'];

        return $this->forEpc($epc, $normalized, $userId);
    }

    /**
     * Compact HTML for table/HUD chips (safe hrefs only; skips opens without url).
     *
     * @param  list<ContextLink>  $links
     */
    public static function renderHtml(array $links, string $class = 'link link-primary text-xs', bool $compact = true): string
    {
        $parts = [];

        foreach ($links as $link) {
            $url = $link['url'] ?? null;
            if (! filled($url)) {
                continue;
            }

            $label = $compact
                ? self::compactLabel((string) $link['key'], (string) $link['label'])
                : (string) $link['label'];

            $parts[] = '<a href="'.e((string) $url).'" class="'.e($class).'">'
                .e($label)
                .'</a>';
        }

        return implode(' · ', $parts);
    }

    public static function actionsColumn(): TextColumn
    {
        return TextColumn::make('context_actions')
            ->label('Actions')
            ->state(function (mixed $record): string|HtmlString {
                if (! $record instanceof Model) {
                    return '—';
                }

                $epc = $record->epc ?? null;
                if (! $epc instanceof Epc) {
                    return '—';
                }

                $html = self::renderHtml(
                    app(self::class)->forEpc($epc, AssetTrackingUrl::scanForEpc($epc), auth()->id()),
                );

                return $html !== '' ? new HtmlString($html) : '—';
            })
            ->html()
            ->toggleable();
    }

    private static function compactLabel(string $key, string $label): string
    {
        return match ($key) {
            'open_receive' => 'Receive',
            'open_transfer' => 'Transfer',
            'open_ship' => 'Ship',
            'verify_product' => 'Verify',
            default => $label,
        };
    }

    private function transferUrl(Epc $epc, ?string $scan, ?int $userId = null): ?string
    {
        if (! TransferringSessionResource::canAccess()) {
            return null;
        }

        $user = $this->resolveUser($userId);
        if ($user === null) {
            return null;
        }

        $line = TransferringScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereHas('session', function ($query) use ($user): void {
                $query->whereIn('status', ['open', 'in_transit']);

                if (! $user->can(Permissions::SitesAccessAll)) {
                    $siteIds = SiteAccess::userSiteIds($user);
                    $query->where(function ($sites) use ($siteIds): void {
                        $sites->whereIn('from_site_id', $siteIds)
                            ->orWhereIn('to_site_id', $siteIds);
                    });
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $params = ['record' => $line->transferring_session_id];
        if (filled($scan)) {
            $params['scan'] = $scan;
        }

        return TransferringSessionResource::getUrl('view', $params, panel: 'app');
    }

    private function shipUrl(Epc $epc, ?string $scan, ?int $userId = null): ?string
    {
        if (! OutboundShippingSessionResource::canAccess()) {
            return null;
        }

        $user = $this->resolveUser($userId);
        if ($user === null) {
            return null;
        }

        $line = OutboundShippingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->whereHas('session', function ($query) use ($user): void {
                $query->whereIn('status', ['open', 'in_progress']);

                if (! $user->can(Permissions::SitesAccessAll)) {
                    $query->whereIn('site_id', SiteAccess::userSiteIds($user));
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($line === null) {
            return null;
        }

        $params = ['record' => $line->outbound_shipping_session_id];
        if (filled($scan)) {
            $params['scan'] = $scan;
        }

        return OutboundShippingSessionResource::getUrl('view', $params, panel: 'app');
    }

    private function resolveUser(?int $userId): ?User
    {
        if ($userId !== null) {
            $user = User::query()->find($userId);

            return $user instanceof User ? $user : null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
