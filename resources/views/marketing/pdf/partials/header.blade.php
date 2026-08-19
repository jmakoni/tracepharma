@include('marketing.pdf.partials.logo')

@if (! empty($title))
    <h1>{{ $title }}</h1>
@endif

@if (! empty($subtitle))
    <p class="subtitle">{{ $subtitle }}</p>
@endif
