@extends('marketing.layout')

@section('title', 'Dental & medical supply — TracePharma')
@section('meta_description', 'DSCSA traceability for dental and medical supply distributors: lot-level ASN confirmation, mixed Rx/non-Rx catalog, practice ship-to GLNs, and serialized receiving for Rx lines.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Solutions · Dental & medical supply"
        title="Mixed catalogs, practice ship-tos — profile-tuned for supply distributors"
        description="Before TracePharma, mixed-catalog DCs often confirm lot ASNs in one system and chase Rx EPCIS in another—delaying ship when serialized and lot-only lines share a pallet. TracePharma is the L4 workspace for dental and medical supply DCs: confirm lot-level ASNs before Rx EPCIS arrives, ship serialized Rx and lot-only supplies on the same order, and label practice ship-to GLNs—with distributor language, not pharmacy-dispenser UX."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.home') }}">Home</a> / Industries / Dental &amp; medical supply
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a dental/medical demo →</a>
            <a href="{{ route('marketing.features.show', 'receiving') }}">EPCIS receiving →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.pipeline-steps
            :steps="[
                ['phase' => 'Confirm', 'title' => 'Lot-level ASN', 'description' => 'Prominent ASN confirmation panel records audit trail before serialized EPCIS.'],
                ['phase' => 'Receive', 'title' => 'Serialized Rx EPCIS', 'description' => 'Ingest manufacturer EPCIS for Rx lines via SFTP, AS2, or webhook.'],
                ['phase' => 'Catalog', 'title' => 'Mixed SKU types', 'description' => 'Requires-serialization toggle per SKU — gloves and supplies stay lot-only.'],
                ['phase' => 'Ship', 'title' => 'Practice outbound', 'description' => 'Lot-level and serialized ship orders to practice ship-to GLNs.'],
                ['phase' => 'Monitor', 'title' => 'Operations scorecard', 'description' => 'Manufacturer supplier health and practice customer ACK status.'],
            ]"
        />
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <x-marketing.module-grid
                :modules="[
                    ['title' => 'Lot-level ASN confirmation', 'description' => 'Confirmation summary with lots, quantities, and supplier before Rx serialization files arrive—so receiving clerks record audit evidence before serial matching starts.'],
                    ['title' => 'Mixed product catalog', 'description' => 'Per-SKU requires-serialization flag—Rx serialized, non-Rx supplies lot-only—so one order can mix gloves and serialized Rx without manual workarounds.', 'href' => route('marketing.features.show', 'receiving')],
                    ['title' => 'Practice ship-to GLNs', 'description' => 'Customer partner labeling for dental practices—not just corporate sold-to—so outbound EPCIS routes to the chair-side ship-to.'],
                    ['title' => 'Lot-level shipping', 'description' => 'Ship gloves, instruments, and supplies by GTIN + lot + quantity without serials—so non-Rx lines ship on the same outbound workflow as Rx.'],
                    ['title' => 'Serialized Rx receiving', 'description' => 'Scan-first and file-first receiving for manufacturer EPCIS on Rx lines—so DC staff match serials before product hits sellable inventory.'],
                    ['title' => 'Product trace', 'description' => 'Trace serialized Rx inventory by GTIN + serial for recalls—so investigations start from event history, not partner phone calls.', 'href' => route('marketing.features.show', 'compliance')],
                ]"
            />
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-marketing.compliance-pillars
            :pillars="[
                ['title' => 'Dental/medical profile', 'description' => 'Navigation and copy tuned for supply distributors — not independent pharmacy dispenser workflows.', 'items' => ['Lot-level ship UX defaults', 'Practice customer labeling', 'Distributor operations scorecard']],
                ['title' => 'Mixed Rx and non-Rx', 'description' => 'One DC handles serialized pharmaceuticals alongside lot-tracked supplies on the same platform.', 'items' => ['Per-SKU serialization flag', 'Lot + serial mixed shipments', 'ASN audit trail before EPCIS']],
                ['title' => 'Partner connectivity', 'description' => 'Connect directly to manufacturers and practices — presets for major serialization platforms.', 'items' => ['TraceLink and regional SFTP presets', 'Direct partner EPCIS — no mandatory network fees', 'ACK monitoring per practice customer']],
            ]"
        />
    </section>

    <x-marketing.cta-banner
        title="See dental/medical supply workflows live"
        description="Request a dental/medical demo—we'll walk through lot-level ASN confirmation, mixed catalog setup, serialized Rx receiving, practice ship-to outbound, and operations scorecard on a demo tenant."
    />
@endsection