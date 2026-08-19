@extends('marketing.layout')

@section('title', 'Legal & Version — TracePharma')
@section('meta_description', 'TracePharma legal document versions, copyright, application release version, and links to the Terms of Service and Privacy Policy.')

@section('content')
    <x-marketing.page-hero
        eyebrow="Legal"
        title="Legal & Version"
        description="Current Terms of Service and Privacy Policy versions, application release information, and copyright for TracePharma by Vatengi Systems LLC."
    >
        <x-slot:breadcrumb>
            Legal & Version
        </x-slot:breadcrumb>
        <x-slot:actions>
            <a href="{{ route('marketing.tos') }}">Terms of Service →</a>
            <a href="{{ route('marketing.privacy') }}">Privacy Policy →</a>
        </x-slot:actions>
    </x-marketing.page-hero>

    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <x-legal.legal-summary />
    </section>
@endsection
