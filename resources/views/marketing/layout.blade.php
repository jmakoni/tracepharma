<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth" style="color-scheme: light;">
<head>
    @php
        $pageTitle = trim($__env->yieldContent('title', 'TracePharma — L4 DSCSA traceability for the US supply chain'));
        $pageDescription = trim($__env->yieldContent('meta_description', 'TracePharma — L4 DSCSA traceability for manufacturers, wholesalers, 3PLs, and dispensers. EPCIS hub, partner connectivity, and compliance workflows.'));
        $canonicalUrl = trim($__env->yieldContent('canonical', url()->current()));
        $ogImage = trim($__env->yieldContent('og_image', url('/images/brand/logo.svg')));
        $siteName = 'TracePharma';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <title>{{ $pageTitle }}</title>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Poppins:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">
    <link rel="icon" href="/images/brand/logo-mark.svg" type="image/svg+xml">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>

  @php
      $organizationSchema = [
          '@context' => 'https://schema.org',
          '@graph' => [
              [
                  '@type' => 'Organization',
                  '@id' => url('/#organization'),
                  'name' => $siteName,
                  'url' => route('marketing.home', absolute: true),
                  'logo' => url('/images/brand/logo.svg'),
                  'description' => 'L4 DSCSA traceability SaaS for US supply-chain trading partners.',
              ],
              [
                  '@type' => 'WebSite',
                  '@id' => url('/#website'),
                  'url' => route('marketing.home', absolute: true),
                  'name' => $siteName,
                  'publisher' => ['@id' => url('/#organization')],
              ],
          ],
      ];
  @endphp
    <x-marketing.json-ld :data="$organizationSchema" />
    @stack('head')
</head>
<body class="tp-marketing-light bg-tp-canvas text-tp-ink min-h-screen font-sans antialiased" style="background-color: #f5f7f8;">
    <x-marketing.nav />

    <main>
        @yield('content')
    </main>

    <x-marketing.footer />
</body>
</html>
