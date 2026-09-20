@php
    $heading = $this->getHeading();
    $subheading = $this->getSubHeading();
@endphp

<div class="tp-login-page">
    <div class="tp-login-phone-chrome">
        <img
            src="{{ asset('images/brand/logo.svg') }}"
            alt="TracePharma"
            class="h-10 w-auto dark:hidden"
        />
        <img
            src="{{ asset('images/brand/logo-dark.svg') }}"
            alt="TracePharma"
            class="hidden h-10 w-auto dark:block"
        />
    </div>

    @if (filled($heading))
        <h1 class="tp-login-phone-heading">
            {{ $heading }}
        </h1>
    @endif

    @if (filled($heading) || filled($subheading))
        <header class="fi-simple-header tp-login-desktop-header">
            <img
                src="{{ asset('images/brand/logo.svg') }}"
                alt="TracePharma"
                class="fi-logo fi-logo-light"
                style="height: 2.25rem;"
            />
            <img
                src="{{ asset('images/brand/logo-dark.svg') }}"
                alt="TracePharma"
                class="fi-logo fi-logo-dark"
                style="height: 2.25rem;"
            />

            @if (filled($heading))
                <h1 class="fi-simple-header-heading">
                    {{ $heading }}
                </h1>
            @endif

            @if (filled($subheading))
                <p class="fi-simple-header-subheading">
                    {{ $subheading }}
                </p>
            @endif
        </header>
    @endif

    <x-filament-panels::page.simple
        :heading="''"
        :subheading="''"
        class="tp-login-simple"
    >
        {{ $this->content }}
    </x-filament-panels::page.simple>

    <div class="tp-login-phone-footer">
        @include('filament.app.partials.floor-guest-login-footer')
    </div>
</div>
