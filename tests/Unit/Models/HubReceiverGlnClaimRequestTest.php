<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Models\Admin;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HubReceiverGlnClaimRequestTest extends TestCase
{
    #[Test]
    public function it_casts_status_and_exposes_pending_state(): void
    {
        $request = new HubReceiverGlnClaimRequest([
            'status' => HubReceiverGlnClaimRequestStatus::Pending->value,
        ]);

        $this->assertSame(HubReceiverGlnClaimRequestStatus::Pending, $request->status);
        $this->assertTrue($request->isPending());

        $request->setAttribute('status', HubReceiverGlnClaimRequestStatus::Approved);

        $this->assertFalse($request->isPending());
    }

    #[Test]
    public function it_exposes_central_relationships(): void
    {
        $request = new HubReceiverGlnClaimRequest;

        $this->assertInstanceOf(BelongsTo::class, $request->tenant());
        $this->assertInstanceOf(Tenant::class, $request->tenant()->getRelated());
        $this->assertInstanceOf(BelongsTo::class, $request->reviewedBy());
        $this->assertInstanceOf(Admin::class, $request->reviewedBy()->getRelated());

        $tenant = new Tenant;

        $this->assertInstanceOf(HasMany::class, $tenant->hubReceiverGlnClaimRequests());
        $this->assertInstanceOf(
            HubReceiverGlnClaimRequest::class,
            $tenant->hubReceiverGlnClaimRequests()->getRelated(),
        );
    }

    #[Test]
    public function pending_scope_filters_by_pending_status(): void
    {
        $query = HubReceiverGlnClaimRequest::query()->pending();

        $this->assertSame(
            [HubReceiverGlnClaimRequestStatus::Pending->value],
            $query->getBindings(),
        );
        $this->assertStringContainsString('`status` = ?', $query->toSql());
    }

    #[Test]
    public function duplicate_identity_is_rejected_even_when_the_request_status_differs(): void
    {
        $tenantId = (string) Str::uuid();
        $attributes = [
            'tenant_id' => $tenantId,
            'gln' => '0366159000010',
            'provider' => 'systech',
            'reason' => 'Enable EPCIS hub receiving.',
            'requested_by' => 'owner@example.com',
            'status' => HubReceiverGlnClaimRequestStatus::Pending,
        ];

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            HubReceiverGlnClaimRequest::query()->create([
                ...$attributes,
                'status' => HubReceiverGlnClaimRequestStatus::Approved,
            ]);

            try {
                HubReceiverGlnClaimRequest::query()->create($attributes);
                $this->fail('The database must reject a duplicate tenant, provider, and GLN identity.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->getCode());
            }
        } finally {
            HubReceiverGlnClaimRequest::query()->where('tenant_id', $tenantId)->delete();
            DB::table('tenants')->where('id', $tenantId)->delete();
        }
    }
}
