<section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
    <h2 class="text-lg font-semibold text-tp-ink">What buyers say about DSCSA operations</h2>
    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-tp-muted">
        Synthesized from public industry sources—not attributed customer reviews. Each theme links to a TracePharma page.
    </p>
    <div class="mt-8 grid gap-6 md:grid-cols-2">
        @foreach (\App\Support\Marketing\MarketingBuyerVoice::themes() as $theme)
            <div class="tp-card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-tp-teal-400">{{ $theme['label'] }}</p>
                <blockquote class="mt-4 border-l-2 border-tp-accent-500/40 pl-4 text-sm italic leading-relaxed text-tp-muted">
                    &ldquo;{{ $theme['quote'] }}&rdquo;
                </blockquote>
                <p class="mt-4 text-xs leading-relaxed text-tp-muted">{{ $theme['source_note'] }}</p>
                <a
                    href="{{ route($theme['tracepharma_answer_route'], $theme['tracepharma_answer_params'] ?? []) }}"
                    class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200"
                >
                    {{ $theme['tracepharma_answer_label'] }} →
                </a>
            </div>
        @endforeach
    </div>
</section>
