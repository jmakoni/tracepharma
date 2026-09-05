@extends('marketing.layout')

@section('title', $integration['name'].' integration — TracePharma')
@section('meta_description', $integration['meta_description'])

@section('content')
    <x-marketing.page-hero
        :eyebrow="'Integrations · '.$integration['category']"
        :title="'TracePharma + '.$integration['name']"
        :description="$integration['hero_description']"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.integrations.index') }}">Integrations</a>
            @if (! empty($breadcrumbParent))
                / <a href="{{ route($breadcrumbParent['route']) }}">{{ $breadcrumbParent['label'] }}</a>
            @endif
            / {{ $integration['name'] }}
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
            @if ($integration['compare_route'])
                <a href="{{ route($integration['compare_route']) }}">Compare vs {{ $integration['name'] }} →</a>
            @endif
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="flex flex-wrap gap-3">
            @if (! empty($integration['preset']))
                <span class="rounded-full border border-tp-accent-500/30 bg-tp-accent-500/10 px-3 py-1 text-xs font-semibold text-tp-accent-300">
                    Preset: <code class="font-mono">{{ $integration['preset'] }}</code>
                </span>
            @else
                <span class="rounded-full border border-tp-border px-3 py-1 text-xs text-tp-muted">Standards-based EPCIS transports (no named preset)</span>
            @endif
            @foreach ($integration['transports'] as $transport)
                <span class="rounded-full border border-tp-border px-3 py-1 text-xs text-tp-muted">{{ $transport }}</span>
            @endforeach
            @if ($integration['pulse_listed'])
                <span class="rounded-full border border-tp-border px-3 py-1 text-xs text-tp-muted">Pulse ecosystem vendor</span>
            @endif
        </div>

        <p class="mt-8 max-w-3xl text-lg leading-relaxed text-tp-muted">{{ $integration['summary'] }}</p>

        @if (! empty($integration['runbook']))
            <p class="mt-4 max-w-3xl text-sm leading-relaxed text-tp-muted">
                Operator runbook (implementation docs path):
                <code class="rounded border border-tp-border bg-black/20 px-1.5 py-0.5 font-mono text-xs text-tp-ink">{{ $integration['runbook'] }}</code>
                — same <code class="font-mono text-xs">POST /api/v1/dispense-check</code> endpoint; no per-vendor product routes.
            </p>
        @endif
    </section>

    <section class="border-y border-tp-border bg-tp-canvas">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
            <div class="grid gap-6 lg:grid-cols-2">
                <x-marketing.detail-section title="Inbound interoperability" :items="$integration['inbound']" />
                <x-marketing.detail-section title="Outbound &amp; compliance" :items="$integration['outbound']" />
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <h2 class="text-2xl font-semibold tracking-tight text-tp-ink">Typical cutover steps</h2>
        <ol class="mt-8 space-y-4">
            @foreach ($integration['cutover'] as $index => $step)
                <li class="tp-card flex gap-4 p-5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-tp-accent-500/15 text-sm font-semibold text-tp-accent-300">{{ $index + 1 }}</span>
                    <span class="text-sm leading-relaxed text-tp-muted">{{ $step }}</span>
                </li>
            @endforeach
        </ol>

        <h2 class="mt-14 text-xl font-semibold text-tp-ink">Best for</h2>
        <ul class="mt-6 space-y-3">
            @foreach ($integration['best_for'] as $item)
                <li class="flex gap-3 text-sm leading-relaxed text-tp-muted">
                    <span class="text-tp-teal-400">→</span>
                    {{ $item }}
                </li>
            @endforeach
        </ul>

        @if (! empty($integration['copy_lines']))
            <h2 class="mt-14 text-xl font-semibold text-tp-ink">Connection details (tenant provisioning)</h2>
            <ul class="mt-6 space-y-2 rounded-xl border border-tp-border bg-black/20 p-6 font-mono text-xs text-tp-muted">
                @foreach ($integration['copy_lines'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-tp-muted">Placeholder values are replaced with your tenant ID and connection ID after onboarding.</p>
        @endif
    </section>

    @if ($integration['faq'] !== [])
        <x-marketing.faq :items="$integration['faq']" :title="$integration['name'].' interoperability FAQ'" />
    @endif

    <x-marketing.cta-banner
        :title="'Connect '.$integration['name'].' in your tenant'"
        description="We'll walk through inbound preset setup, test receiving, and ACK monitoring on a profile-tuned demo workspace."
    />
@endsection

@push('head')
    @if ($integration['faq'] !== [])
        @php
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ], $integration['faq']),
            ];
        @endphp
        <x-marketing.json-ld :data="$faqSchema" />
    @endif
@endpush
