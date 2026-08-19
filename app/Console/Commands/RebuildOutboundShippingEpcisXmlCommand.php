<?php

namespace App\Console\Commands;

use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Epcis\PersistEpcisXmlPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RebuildOutboundShippingEpcisXmlCommand extends Command
{
    protected $signature = 'tracepharma:rebuild-outbound-shipping-epcis
        {tenant : Tenant UUID or domain}
        {session : Outbound shipping session id}';

    protected $description = 'Rebuild a full-history EPCIS 1.2 XML payload for an outbound shipping session';

    public function handle(BuildFullHistoryShippingEpcisXml $builder, PersistEpcisXmlPayload $persist): int
    {
        $tenantKey = (string) $this->argument('tenant');
        $sessionId = (int) $this->argument('session');

        $tenant = Tenant::query()->find($tenantKey)
            ?? Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $tenantKey))->first();

        if (! $tenant instanceof Tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $session = OutboundShippingSession::query()->find($sessionId);
            if (! $session instanceof OutboundShippingSession) {
                $this->error('Shipping session not found.');

                return self::FAILURE;
            }

            $built = $builder->handle($session);
            $document = $session->epcisDocument;
            if (! $document instanceof EpcisDocument) {
                $this->error('Session has no EPCIS document.');

                return self::FAILURE;
            }

            $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
            $oldPath = $document->payload_path;
            $oldDisk = $document->payloadFilesystemDisk();

            $document->forceFill([
                'document_uuid' => $built['instance_id'],
                'original_filename' => $built['filename'],
                'payload_path' => $built['path'],
                'dscsa_affirm' => true,
                'creation_date' => $built['ship_event_time']->copy()->addSeconds(4),
            ])->save();

            $persist->handle(
                $document,
                $built['xml'],
                $built['path'],
                $disk,
                'Rebuild outbound shipping EPCIS',
            );

            if (
                is_string($oldPath)
                && $oldPath !== ''
                && ($oldPath !== $built['path'] || $oldDisk !== $document->fresh()->payloadFilesystemDisk())
            ) {
                Storage::disk($oldDisk)->delete($oldPath);
            }

            $document->refresh();
            $this->info('Wrote '.$document->payload_path);
            $this->info('Disk '.$document->payload_disk);
            $this->info('Filename '.$built['filename']);
            $this->info('InstanceIdentifier '.$built['instance_id']);
            $this->info('Bytes '.strlen($built['xml']));

            return self::SUCCESS;
        } finally {
            tenancy()->end();
        }
    }
}
