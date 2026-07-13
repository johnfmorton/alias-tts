{{--
    Shared social/SEO head block for the public pages (landing, about, verify).
    Each page @includes this with its own title/description/image. Emits the
    canonical <meta name="description"> plus Open Graph and Twitter cards, so a
    pasted link previews correctly in Slack, iMessage, LinkedIn, Discord, and X.

    og:image MUST be absolute — secure_asset() builds it from APP_URL, so keep
    prod APP_URL correct (https://alias.morton.dev), or previews break.

    @param string      $metaTitle        card + browser-share title
    @param string      $metaDescription  card description (aim ≤ ~160 chars)
    @param string|null $metaImage        asset-relative path to the 1200×630 image
    @param string|null $metaUrl          canonical URL (defaults to current)
--}}
@php
    $metaTitle       = $metaTitle       ?? 'Alias TTS';
    $metaDescription = $metaDescription ?? 'Self-hosted text-to-speech with voice cloning, compatible with the ElevenLabs and OpenAI APIs.';
    $metaImage       = secure_asset($metaImage ?? 'images/social/alias-tts-og.png');
    $metaUrl         = $metaUrl ?? url()->current();
@endphp
<meta name="description" content="{{ $metaDescription }}">
<link rel="canonical" href="{{ $metaUrl }}">

{{-- Open Graph — Facebook, LinkedIn, Slack, iMessage, Discord --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="Alias TTS">
<meta property="og:title" content="{{ $metaTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:url" content="{{ $metaUrl }}">
<meta property="og:image" content="{{ $metaImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $metaTitle }}">

{{-- X / Twitter --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $metaTitle }}">
<meta name="twitter:description" content="{{ $metaDescription }}">
<meta name="twitter:image" content="{{ $metaImage }}">
{{-- <meta name="twitter:site" content="@your_handle"> uncomment once you have a handle --}}
