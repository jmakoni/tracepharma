@extends('marketing.layout')

@section('title', 'Privacy Policy — TracePharma')
@section('meta_description', 'TracePharma Privacy Policy—how Vatengi Systems LLC collects, uses, and protects personal information on the L4 DSCSA traceability platform and marketing site.')

@section('content')
  @php
      $sections = \App\Support\Marketing\PrivacyPolicy::sections();
      $effectiveDate = \App\Support\Marketing\PrivacyPolicy::effectiveDate();
      $version = \App\Support\Marketing\PrivacyPolicy::version();
  @endphp

    <x-marketing.page-hero
        eyebrow="Legal"
        title="Privacy Policy"
        description="How we collect, use, and protect personal information when you visit TracePharma or use our DSCSA traceability platform."
    >
        <x-slot:breadcrumb>
            Privacy Policy
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.tos') }}">Terms of Service →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="mb-10 max-w-3xl text-sm leading-relaxed text-tp-muted">
            <p>
                <strong class="text-tp-ink">Effective date:</strong> {{ $effectiveDate }}
                <span class="mx-2 text-tp-border">·</span>
                <strong class="text-tp-ink">Version:</strong> {{ $version }}
            </p>
            <p class="mt-3">
                This Privacy Policy is provided by
                <strong class="text-tp-ink">{{ \App\Support\Marketing\TermsOfService::legalEntityName() }}</strong>
                for the TracePharma Service. We do not sell personal information.
            </p>
        </div>

        <div class="grid gap-12 lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] lg:gap-16">
            <nav class="lg:sticky lg:top-24 lg:self-start" aria-label="Privacy policy sections">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-tp-muted">On this page</h2>
                <ol class="mt-4 space-y-2 text-sm">
                    @foreach ($sections as $section)
                        <li>
                            <a
                                href="#{{ $section['id'] }}"
                                class="text-tp-muted transition hover:text-tp-link"
                            >
                                {{ $section['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>

            <div class="min-w-0 space-y-12">
                @foreach ($sections as $section)
                    <article id="{{ $section['id'] }}" class="scroll-mt-24 border-b border-tp-border pb-12 last:border-b-0">
                        <h2 class="text-lg font-semibold text-tp-ink">{{ $section['title'] }}</h2>

                        <div class="mt-5 space-y-4 text-sm leading-relaxed text-tp-muted">
                            @foreach ($section['paragraphs'] as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach

                            @if (! empty($section['bullets']))
                                <ul class="mt-2 list-disc space-y-2 pl-5">
                                    @foreach ($section['bullets'] as $bullet)
                                        <li>{{ $bullet }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </div>

        <p class="mt-12 text-xs leading-relaxed text-tp-muted">
            © {{ date('Y') }} {{ \App\Support\Marketing\TermsOfService::legalEntityName() }}.
            TracePharma is a service of {{ \App\Support\Marketing\TermsOfService::legalEntityName() }}.
            See also our <a href="{{ route('marketing.tos') }}" class="text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">Terms of Service</a>.
        </p>
    </section>
@endsection
