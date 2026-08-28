<div class="flex flex-wrap gap-2">
    @foreach ($badges as $badge)
        <div
            @class([
                'rounded-box border px-3 py-2 text-sm max-w-xs',
                'border-success/40 bg-success/10' => ($badge['status'] ?? '') === 'ok',
                'border-warning/40 bg-warning/10' => ($badge['status'] ?? '') === 'warn',
                'border-error/40 bg-error/10' => ($badge['status'] ?? '') === 'block',
            ])
            title="{{ $badge['detail'] ?? '' }}"
        >
            <div class="font-semibold">{{ $badge['label'] ?? '' }}</div>
            <div class="text-xs opacity-80 line-clamp-2">{{ $badge['detail'] ?? '' }}</div>
        </div>
    @endforeach
</div>
