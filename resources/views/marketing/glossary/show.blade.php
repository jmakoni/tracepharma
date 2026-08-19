@extends('marketing.layout')

@section('title', $term['title'].' — DSCSA glossary — TracePharma')
@section('meta_description', $term['meta_description'])

@section('content')
    <x-marketing.page-hero
        eyebrow="Glossary"
        :title="$term['title']"
        :description="$term['summary']"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.glossary') }}">Glossary</a> / {{ $term['title'] }}
        </x-slot:breadcrumb>
        <x-slot:actions>
            @if ($term['learn_more_route'])
                <a href="{{ route($term['learn_more_route'], $term['learn_more_params'] ?? []) }}">{{ $term['learn_more_label'] }} →</a>
            @endif
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-5 leading-relaxed text-tp-muted">
            @foreach ($term['definition'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </div>

        <div class="mt-12 tp-card-accent border-tp-accent-500/30 p-8">
            <h2 class="text-lg font-semibold text-tp-ink">In TracePharma</h2>
            <ul class="mt-5 space-y-3">
                @foreach ($term['in_tracepharma'] as $item)
                    <li class="flex gap-3 text-sm leading-relaxed text-tp-muted">
                        <span class="text-tp-teal-400">→</span>
                        {{ $item }}
                    </li>
                @endforeach
            </ul>
        </div>

        @if (! empty($term['related']))
            <h2 class="mt-14 text-lg font-semibold text-tp-ink">Related terms</h2>
            <div class="mt-6 flex flex-wrap gap-3">
                @foreach ($term['related'] as $relatedSlug)
                    @php $related = \App\Support\Marketing\GlossaryTerms::get($relatedSlug); @endphp
                    <a
                        href="{{ route('marketing.glossary.show', $relatedSlug) }}"
                        class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
                    >
                        {{ $related['title'] }}
                    </a>
                @endforeach
                <a
                    href="{{ route('marketing.glossary') }}"
                    class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
                >
                    All terms
                </a>
            </div>
        @endif
    </section>

    <x-marketing.cta-banner
        :title="'Using '.$term['title'].' in daily operations?'"
        description="See how TracePharma turns standards-based events into receiving, verification, and compliance workflows."
    />
@endsection

@push('head')
    @php
        $definedTermSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            'name' => $term['title'],
            'description' => $term['summary'],
            'termCode' => $term['slug'],
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name' => 'TracePharma DSCSA Glossary',
                'url' => route('marketing.glossary', absolute: true),
            ],
            'url' => route('marketing.glossary.show', $term['slug'], absolute: true),
        ];
    @endphp
    <x-marketing.json-ld :data="$definedTermSchema" />
@endpush
