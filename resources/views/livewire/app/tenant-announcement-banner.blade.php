<div>
    @foreach ($announcements as $announcement)
        @php
            $alertClass = match ($announcement->severity) {
                \App\Enums\AnnouncementSeverity::Critical => 'alert-error',
                \App\Enums\AnnouncementSeverity::Warning => 'alert-warning',
                default => 'alert-info',
            };
            $excerpt = trim(strip_tags((string) $announcement->body));
        @endphp

        <div
            wire:key="tenant-announcement-{{ $announcement->id }}"
            class="alert {{ $alertClass }} rounded-none border-x-0 border-t-0 shadow-none mx-0 w-full justify-between gap-3 py-2 text-sm"
        >
            <div class="min-w-0">
                <span class="font-semibold">{{ $announcement->title }}</span>
                @if ($excerpt !== '')
                    <span class="opacity-80"> — {{ \Illuminate\Support\Str::limit($excerpt, 120) }}</span>
                @endif
            </div>

            <button
                type="button"
                wire:click="dismiss({{ $announcement->id }})"
                class="btn btn-ghost btn-xs shrink-0"
            >
                Dismiss
            </button>
        </div>
    @endforeach
</div>
