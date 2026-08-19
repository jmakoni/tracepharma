@props([
    'modules',
])

<div {{ $attributes->class(['grid gap-4 sm:grid-cols-2 lg:grid-cols-3']) }}>
    @foreach ($modules as $module)
        <div class="tp-card p-6">
            @if (! empty($module['icon']))
                <span class="font-mono text-xs text-tp-teal-400">{{ $module['icon'] }}</span>
            @endif
            <h3 class="mt-2 text-base font-semibold text-tp-ink">{{ $module['title'] }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-tp-muted">{{ $module['description'] }}</p>
            @if (! empty($module['href']))
                <a href="{{ $module['href'] }}" class="mt-4 inline-flex text-sm font-semibold text-tp-link hover:text-tp-primary-600 dark:hover:text-tp-primary-200">
                    {{ $module['link_label'] ?? 'Learn more →' }}
                </a>
            @endif
        </div>
    @endforeach
</div>