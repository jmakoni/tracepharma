@extends('marketing.layout')

@section('title', $post['title'].' — TracePharma')
@section('meta_description', $post['meta_description'])

@section('content')
    <x-marketing.page-hero
        eyebrow="Blog"
        :title="$post['title']"
        :description="$post['summary']"
    >
        <x-slot:breadcrumb>
            <a href="{{ route('marketing.resources.index') }}">Resources</a> / {{ $post['title'] }}
        </x-slot:breadcrumb>
        <x-slot:actions>
            <span class="text-sm text-tp-muted">Published {{ $post['published_at'] }}</span>
            <a href="{{ route('marketing.demo') }}">Request a demo →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <article class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="max-w-3xl space-y-10">
            @foreach ($post['sections'] as $section)
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

        @if (! empty($post['related_routes']))
            <h2 class="mt-14 text-lg font-semibold text-tp-ink">Related TracePharma pages</h2>
            <div class="mt-6 flex flex-wrap gap-3">
                @foreach ($post['related_routes'] as $related)
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
        :title="'Put '.$post['title'].' into practice'"
        description="See receiving, exceptions, and compliance workflows on a demo tuned to your organization type."
    />
@endsection

@push('head')
    @php
        $articleSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $post['title'],
            'description' => $post['summary'],
            'datePublished' => $post['published_at'],
            'author' => [
                '@type' => 'Organization',
                'name' => 'TracePharma',
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'TracePharma',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => url('/images/brand/logo.svg'),
                ],
            ],
            'mainEntityOfPage' => route('marketing.blog.show', $post['slug'], absolute: true),
        ];
    @endphp
    <x-marketing.json-ld :data="$articleSchema" />
@endpush
