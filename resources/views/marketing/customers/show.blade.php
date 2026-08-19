@extends('marketing.layout')

@section('title', $study['title'].' — TracePharma')
@section('meta_description', $study['meta_description'])

@section('content')
    <x-marketing.page-hero
        eyebrow="Customer pattern"
        :title="$study['title']"
        :description="$study['summary']"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.resources.index') }}">Resources</a> / {{ $study['organization_type'] }}
        </x-slot:breadcrumb>
        <x-slot:actions>
            <span class="text-sm text-tp-muted">{{ $study['published_at'] }}</span>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 pt-8 sm:px-6">
        <div class="tp-alert--warning p-6">
            <p class="text-sm leading-relaxed text-tp-ink">
                <strong class="font-semibold text-tp-warning">Illustrative deployment pattern.</strong>
                This describes a fictional anonymized scenario for educational purposes—not a named customer endorsement or guaranteed outcome.
            </p>
        </div>
    </section>

    <article class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <div class="max-w-3xl space-y-10">
            @foreach ($study['sections'] as $section)
                <section>
                    <h2 class="text-xl font-semibold text-tp-ink">{{ $section['heading'] }}</h2>
                    <div class="mt-4 space-y-4 leading-relaxed text-tp-muted">
                        @foreach ($section['paragraphs'] as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        @if (! empty($study['related_routes']))
            <h2 class="mt-14 text-lg font-semibold text-tp-ink">Related TracePharma pages</h2>
            <div class="mt-6 flex flex-wrap gap-3">
                @foreach ($study['related_routes'] as $related)
                    <a
                        href="{{ route($related['name'], $related['params'] ?? []) }}"
                        class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
                    >
                        {{ $related['label'] }}
                    </a>
                @endforeach
                <a
                    href="{{ route('marketing.resources.index') }}"
                    class="rounded-full border border-tp-border bg-tp-surface px-4 py-2 text-sm font-medium text-tp-muted transition hover:border-tp-teal-500/40 hover:text-tp-link"
                >
                    All resources
                </a>
            </div>
        @endif
    </article>

    <x-marketing.cta-banner
        title="See this pattern in a live demo"
        description="Tell us your organization type—we'll walk through the workflows that match this illustrative deployment."
    />
@endsection

@push('head')
    @php
        $articleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $study['title'],
            'description' => $study['summary'],
            'datePublished' => $study['published_at'],
            'author' => [
                '@type' => 'Organization',
                'name' => 'TracePharma',
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'TracePharma',
            ],
            'mainEntityOfPage' => route('marketing.customers.show', $study['slug'], absolute: true),
        ];
    @endphp
    <x-marketing.json-ld :data="$articleSchema" />
@endpush
