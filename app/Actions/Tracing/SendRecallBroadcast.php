<?php

declare(strict_types=1);

namespace App\Actions\Tracing;

use App\Enums\TracingRequestNotificationStatus;
use App\Models\TracingRequest;
use App\Models\TracingRequestNotification;
use App\Models\TradingPartner;
use App\Models\User;
use App\Notifications\RecallBroadcastMail;
use App\Services\Tracing\RecallBroadcastAckService;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class SendRecallBroadcast
{
    /**
     * @param  list<int>  $partnerIds
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function execute(TracingRequest $request, array $partnerIds, User $actor): array
    {
        if (! $request->is_recall) {
            throw new InvalidArgumentException('Recall broadcast is only available for recall tracing requests.');
        }

        $partnerIds = array_values(array_unique(array_map(intval(...), $partnerIds)));

        if ($partnerIds === []) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $partners = TradingPartner::query()
            ->whereIn('id', $partnerIds)
            ->get()
            ->keyBy('id');

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($partnerIds as $partnerId) {
            $partner = $partners->get($partnerId);

            if ($partner === null || blank($partner->email)) {
                $skipped++;

                continue;
            }

            $notification = TracingRequestNotification::query()->updateOrCreate(
                [
                    'tracing_request_id' => $request->getKey(),
                    'trading_partner_id' => $partnerId,
                    'channel' => 'email',
                ],
                [
                    'status' => TracingRequestNotificationStatus::Pending,
                    'error_message' => null,
                ],
            );

            try {
                $ackUrl = app(RecallBroadcastAckService::class)->signedAckUrl($notification);

                Notification::route('mail', (string) $partner->email)
                    ->notifyNow(new RecallBroadcastMail($request, $partner, $ackUrl));

                $notification->update([
                    'status' => TracingRequestNotificationStatus::Sent,
                    'sent_at' => now(),
                    'error_message' => null,
                    'metadata' => array_merge($notification->metadata ?? [], [
                        'sent_by' => $actor->getKey(),
                        'recipient' => (string) $partner->email,
                    ]),
                ]);

                $sent++;
            } catch (\Throwable $throwable) {
                $notification->update([
                    'status' => TracingRequestNotificationStatus::Failed,
                    'error_message' => $throwable->getMessage(),
                ]);

                $failed++;
            }
        }

        return compact('sent', 'failed', 'skipped');
    }
}
