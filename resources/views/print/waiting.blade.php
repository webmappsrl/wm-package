@php
    $t = fn (string $key) => __($key, [], $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($failedMessage === null)
        {{-- Solo senza JavaScript la pagina si ricarica da sola (10 s per la scheda, 15 s per lo ZIP: il refresh ricarica la pagina, che ha il throttle di generazione). Con JS il polling (ogni 5 s, throttle `print-status`) basta e vale il timeout passato dal controller. --}}
        <noscript><meta http-equiv="refresh" content="{{ $refreshSeconds ?? 10 }}"></noscript>
    @endif
    <meta name="robots" content="noindex">
    <title>{{ $t('Preparing your PDF') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f6f6f6; color: #222; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        a.retry { display: inline-block; margin-top: 1rem; padding: .6rem 1.2rem; background: #2e7d32; color: #fff; border-radius: 4px; text-decoration: none; }
        .spinner { width: 2.5rem; height: 2.5rem; margin: 0 auto 1rem; border: 4px solid #ccc; border-top-color: #2e7d32; border-radius: 50%; animation: spin 1s linear infinite; }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<main aria-busy="{{ $failedMessage === null ? 'true' : 'false' }}" id="print-main">
    <h1 id="print-title">{{ $failedMessage === null ? $t('Preparing your PDF') : $t('The PDF could not be generated') }}</h1>
    @if ($failedMessage === null)
        <div class="spinner" id="print-spinner" aria-hidden="true"></div>
    @endif
    <div role="status" aria-live="polite" id="print-status">
        @if ($failedMessage === null)
            {{ $t('Your PDF is being prepared. This page updates automatically.') }}
        @else
            {{ $failedMessage }}
        @endif
    </div>
    <a class="retry" id="print-retry" href="{{ $retryUrl }}" @if ($failedMessage === null) hidden @endif>{{ $t('Try again') }}</a>
    <a class="retry" id="print-download" href="#" hidden>{{ $t('Download again') }}</a>
</main>
@if ($failedMessage === null)
<script>
(function () {
    var statusUrl = @json($statusUrl);
    // Un file da scaricare (lo ZIP) non sostituisce la pagina: il browser lo scarica e la scheda
    // resterebbe aperta sull'attesa. Si avvia il download, si dice che è partito e si chiude la scheda,
    // che il browser lascia chiudere solo se l'ha aperta uno script (l'action di Nova).
    var download = @json((bool) ($download ?? false));
    var startedTitle = @json($t('Your download has started'));
    var closeMessage = @json($t('You can close this page.'));
    var timeoutMessage = @json($t('The PDF is taking longer than expected. Please try again later.'));
    var failedTitle = @json($t('The PDF could not be generated'));
    var started = Date.now();
    var main = document.getElementById('print-main');
    var box = document.getElementById('print-status');
    var timer;

    function stop(title, message) {
        clearInterval(timer);
        main.setAttribute('aria-busy', 'false');
        document.getElementById('print-title').textContent = title;
        var spinner = document.getElementById('print-spinner');
        if (spinner) { spinner.hidden = true; }
        box.textContent = message;
        document.getElementById('print-retry').hidden = false;
    }

    function finish(url) {
        if (!download) { window.location.href = url; return; }
        main.setAttribute('aria-busy', 'false');
        document.getElementById('print-title').textContent = startedTitle;
        var spinner = document.getElementById('print-spinner');
        if (spinner) { spinner.hidden = true; }
        box.textContent = closeMessage;
        var again = document.getElementById('print-download');
        again.href = url;
        again.hidden = false;
        window.location.href = url;
        setTimeout(function () { window.close(); }, 2000);
    }

    function poll() {
        if (Date.now() - started > {{ (int) ($timeoutSeconds ?? 300) }} * 1000) { stop(failedTitle, timeoutMessage); return; }
        fetch(statusUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }
                if (data.status === 'done' && data.url) { clearInterval(timer); finish(data.url); }
                else if (data.status === 'failed') { stop(failedTitle, data.message || ''); }
            })
            .catch(function () {});
    }

    timer = setInterval(poll, 5000);
})();
</script>
@endif
</body>
</html>
