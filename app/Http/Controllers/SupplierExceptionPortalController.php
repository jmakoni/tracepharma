<?php

namespace App\Http\Controllers;

use App\Models\Exceptions\ExceptionCase;
use App\Models\TradingPartner;
use App\Support\Exceptions\InvestigatorSlaClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class SupplierExceptionPortalController extends Controller
{
    public function index(Request $request, string $portalShareUuid): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $partner = TradingPartner::query()
            ->where('portal_share_uuid', $portalShareUuid)
            ->first(['id', 'name', 'portal_share_uuid', 'is_active']);

        // A revoked or rotated uuid, and a partner that has since been deactivated, both
        // read as the same thing to the supplier: this link no longer grants access.
        abort_if($partner === null || ! $partner->is_active, 403, 'This supplier portal link is no longer active.');

        $clock = app(InvestigatorSlaClock::class);
        $agingDays = max(1, (int) config('tracepharma.supplier_exception_notify.aging_days', 3));

        $cases = ExceptionCase::query()
            ->open()
            ->where('trading_partner_id', $partner->getKey())
            ->whereNotNull('share_uuid')
            ->with([
                'type:id,name,code',
            ])
            ->withCount([
                'quarantineHolds as open_holds_count' => fn ($q) => $q->where('status', 'open'),
            ])
            ->orderByDesc('id')
            ->get([
                'id',
                'exception_type_id',
                'title',
                'status',
                'severity',
                'created_at',
                'share_uuid',
                'share_expires_at',
                'trading_partner_id',
            ]);

        $linkTtlDays = max(1, (int) config('tracepharma.supplier_portal.link_ttl_days', 30));
        $caseLinks = $cases->mapWithKeys(
            fn (ExceptionCase $case): array => [(int) $case->getKey() => URL::temporarySignedRoute(
                'tenant.supplier-quarantine.show',
                $case->share_expires_at ?? now()->addDays($linkTtlDays),
                ['shareUuid' => $case->share_uuid],
            )],
        );

        $lastNotified = $cases->mapWithKeys(
            fn (ExceptionCase $case): array => [
                (int) $case->getKey() => $clock->lastSupplierEmailAt($case),
            ],
        );

        return view('supplier-exceptions.index', [
            'partner' => $partner,
            'cases' => $cases,
            'caseLinks' => $caseLinks,
            'lastNotified' => $lastNotified,
            'agingDays' => $agingDays,
        ]);
    }
}
