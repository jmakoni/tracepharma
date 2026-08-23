<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AttachReceivingSessionInvoice
{
    public function handle(
        ReceivingSession $session,
        string $absolutePath,
        ?string $originalFilename = null,
        ?int $userId = null,
    ): ReceivingSession {
        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            throw new DomainException('Receiving is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $session = $session->fresh() ?? $session;

        $actor = $this->resolveActor($userId);
        if ($actor !== null) {
            $this->assertCanAccessSessionSite($actor, $session);
        }

        if (! $session->isScanFirst()) {
            throw new DomainException('Invoice attach is only available for scan-first receiving sessions.');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new DomainException('Invoice file is missing or unreadable.');
        }

        $sha256 = hash_file('sha256', $absolutePath);
        if ($sha256 === false) {
            throw new DomainException('Unable to hash invoice file.');
        }

        $safeName = $this->safeOriginalFilename($originalFilename ?? basename($absolutePath));
        $disk = (string) config('tracepharma.epcis.payload_disk', 'local');
        $extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'bin';
        }

        $relativePath = 'receiving/invoices/'.$session->getKey().'/'.(string) Str::uuid().'.'.$extension;
        $previousDisk = filled($session->invoice_disk) ? (string) $session->invoice_disk : null;
        $previousPath = filled($session->invoice_path) ? (string) $session->invoice_path : null;

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new DomainException('Unable to read invoice file.');
        }

        $stored = Storage::disk($disk)->put($relativePath, $contents);
        if ($stored === false || ! Storage::disk($disk)->exists($relativePath)) {
            throw new DomainException('Unable to store invoice file.');
        }

        $session->forceFill([
            'invoice_disk' => $disk,
            'invoice_path' => $relativePath,
            'invoice_original_filename' => $safeName,
            'invoice_sha256' => $sha256,
        ])->save();

        if ($previousPath !== null && $previousDisk !== null && ($previousDisk !== $disk || $previousPath !== $relativePath)) {
            Storage::disk($previousDisk)->delete($previousPath);
        }

        return $session->refresh();
    }

    private function safeOriginalFilename(string $originalFilename): string
    {
        $basename = basename(str_replace('\\', '/', $originalFilename));
        $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?? '';
        $basename = trim($basename);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return 'invoice.bin';
        }

        if (strlen($basename) > 255) {
            $extension = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
            $stem = pathinfo($basename, PATHINFO_FILENAME);
            $keep = 255 - ($extension !== '' ? strlen($extension) + 1 : 0);
            $basename = substr($stem, 0, max(1, $keep)).($extension !== '' ? '.'.$extension : '');
        }

        return $basename;
    }

    private function assertCanAccessSessionSite(User $user, ReceivingSession $session): void
    {
        if ($session->site_id === null) {
            if (! $user->can(Permissions::SitesAccessAll)) {
                throw new AuthorizationException('You do not have access to this receiving session.');
            }

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
    }

    private function resolveActor(?int $userId): ?User
    {
        $user = auth()->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($userId === null) {
            return null;
        }

        $resolved = User::query()->find($userId);

        return $resolved instanceof User ? $resolved : null;
    }
}
