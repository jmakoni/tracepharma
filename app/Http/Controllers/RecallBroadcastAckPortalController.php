<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tracing\AcknowledgeRecallBroadcast;
use App\Enums\TracingRequestNotificationStatus;
use App\Models\TracingRequestNotification;
use App\Services\Tracing\RecallBroadcastAckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecallBroadcastAckPortalController extends Controller
{
    public function show(Request $request, string $ackShareUuid): View
    {
        if (! $request->hasValidSignature()) {
            return view('recall-broadcast-ack.invalid');
        }

        $notification = $this->findNotification($ackShareUuid);

        if ($notification === null) {
            return view('recall-broadcast-ack.invalid');
        }

        $ackService = app(RecallBroadcastAckService::class);

        return view('recall-broadcast-ack.show', [
            'notification' => $notification,
            'request' => $notification->tracingRequest,
            'partner' => $notification->tradingPartner,
            'alreadyAcknowledged' => $notification->status === TracingRequestNotificationStatus::Acknowledged,
            'ackSubmitUrl' => $ackService->signedAckSubmitUrl($notification),
        ]);
    }

    public function acknowledge(Request $request, string $ackShareUuid): RedirectResponse|View
    {
        if (! $request->hasValidSignature()) {
            return view('recall-broadcast-ack.invalid');
        }

        $notification = $this->findNotification($ackShareUuid);

        if ($notification === null) {
            return view('recall-broadcast-ack.invalid');
        }

        app(AcknowledgeRecallBroadcast::class)->execute($notification);

        return redirect()
            ->to(app(RecallBroadcastAckService::class)->signedAckUrl($notification->refresh()))
            ->with('acknowledged', true);
    }

    private function findNotification(string $ackShareUuid): ?TracingRequestNotification
    {
        return TracingRequestNotification::query()
            ->where('ack_share_uuid', $ackShareUuid)
            ->whereHas('tradingPartner', fn ($partner) => $partner->where('is_active', true))
            ->with([
                'tracingRequest:id,title,gtin,lot,expiry,notes,is_recall',
                'tradingPartner:id,name,is_active',
            ])
            ->first();
    }
}
