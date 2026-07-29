{{--
    GA4 snippet for the PUBLIC pages only — landing, both about pages, verify,
    login, and the error shell. Renders nothing unless TTS_ANALYTICS_GA_ID is
    set (dev/DDEV just leaves it unset). Deliberately NOT included on:

      - auth/set-password.blade.php — invite links are SIGNED URLs; GA records
        the page URL, and a signed URL must never reach Google
      - auth/two-factor-challenge.blade.php — no analytics value
      - components/layout.blade.php — the logged-in app is never sent to GA;
        in-app usage is tracked first-party (app_events → /admin/insights)

    page_location strips the query string from everything GA records: /verify
    carries ?sha= and no public page needs its query surfaced to Google.
--}}
@if (config('tts.analytics.ga_id'))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('tts.analytics.ga_id') }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', @json(config('tts.analytics.ga_id')), {
            page_location: location.origin + location.pathname,
            anonymize_ip: true
        });
    </script>
@endif
