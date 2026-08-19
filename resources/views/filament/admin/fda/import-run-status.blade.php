<div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as $card)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-white/5">
            <div class="font-medium text-gray-950 dark:text-white">{{ $card['label'] }}</div>
            <dl class="mt-2 space-y-1">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Last sync</dt>
                    <dd class="text-right text-gray-950 dark:text-white">{{ $card['last_sync'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Last result</dt>
                    <dd class="text-right text-gray-950 dark:text-white">{{ $card['last_result'] }}</dd>
                </div>
                @if ($card['rows_read'] !== null)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Last import count</dt>
                        <dd class="text-right font-medium text-gray-950 dark:text-white">{{ number_format((int) $card['rows_read']) }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    @endforeach
</div>
