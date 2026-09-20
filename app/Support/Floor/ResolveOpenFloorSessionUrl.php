<?php

namespace App\Support\Floor;

use App\Filament\App\Pages\BreakPackWorkstation;
use App\Filament\App\Pages\DecommissionWorkstation;
use App\Filament\App\Pages\MobileBreakPackWorkstation;
use App\Filament\App\Pages\MobilePackWorkstation;
use App\Filament\App\Pages\MobileUnpackWorkstation;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Pages\ReturnWorkstation;
use App\Filament\App\Pages\SaleableReturnWorkstation;
use App\Filament\App\Pages\UnpackWorkstation;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Disposition\DispositionSession;
use App\Models\Packing\PackingSession;
use App\Support\Receiving\ReceiveLayout;

/**
 * Deep-link to resume any blocking floor session type.
 */
final class ResolveOpenFloorSessionUrl
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function url(FloorSessionType $type, int $sessionId, array $parameters = []): ?string
    {
        $parameters = array_merge(['session' => $sessionId], $parameters);

        return match ($type) {
            FloorSessionType::Receiving => ReceiveLayout::sessionUrl($sessionId, $parameters),
            FloorSessionType::Shipping => OutboundShippingSessionResource::getUrl(
                FloorLayout::cookie() === FloorLayout::FLOOR ? 'floor' : 'view',
                array_merge(['record' => $sessionId], $parameters),
                panel: 'app',
            ),
            FloorSessionType::Transferring => TransferringSessionResource::getUrl(
                FloorLayout::cookie() === FloorLayout::FLOOR ? 'floor' : 'view',
                array_merge(['record' => $sessionId], $parameters),
                panel: 'app',
            ),
            FloorSessionType::Packing => $this->packingPageUrl($sessionId, $parameters),
            FloorSessionType::Disposition => $this->dispositionPageUrl($sessionId, $parameters),
        };
    }

    public function urlFromBlock(EpcExclusiveBlock $block, array $parameters = []): ?string
    {
        return $this->url($block->sessionType, $block->sessionId, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function packingPageUrl(int $sessionId, array $parameters): ?string
    {
        $session = PackingSession::query()->find($sessionId);
        if ($session === null) {
            return null;
        }

        $page = match ($session->session_kind->value) {
            'unpack' => FloorLayout::cookie() === FloorLayout::FLOOR
                ? MobileUnpackWorkstation::class
                : UnpackWorkstation::class,
            'break_pack' => FloorLayout::cookie() === FloorLayout::FLOOR
                ? MobileBreakPackWorkstation::class
                : BreakPackWorkstation::class,
            default => FloorLayout::cookie() === FloorLayout::FLOOR
                ? MobilePackWorkstation::class
                : PackWorkstation::class,
        };

        return $page::getUrl($parameters, panel: 'app');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function dispositionPageUrl(int $sessionId, array $parameters): ?string
    {
        $session = DispositionSession::query()->find($sessionId);
        if ($session === null) {
            return null;
        }

        $page = match ($session->biz_step) {
            'returning' => ReturnWorkstation::class,
            'saleable_return' => SaleableReturnWorkstation::class,
            default => DecommissionWorkstation::class,
        };

        return $page::getUrl($parameters, panel: 'app');
    }
}
