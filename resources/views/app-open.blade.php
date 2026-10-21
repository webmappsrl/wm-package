<!DOCTYPE html>
<html lang="{{ $locale }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $appName }}</title>

    {{-- Pagina del link unico dell'App (oc:8803): vedi AppOpenController. --}}
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f9fafb;
            color: #111827;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        main {
            box-sizing: border-box;
            width: 100%;
            max-width: 420px;
            padding: 32px 16px;
            text-align: center;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        p {
            margin: 0 0 24px;
            color: #4b5563;
            font-size: 16px;
            line-height: 1.5;
        }

        .buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .button {
            display: block;
            padding: 14px 20px;
            border-radius: 10px;
            background: #19b7a1;
            color: #ffffff;
            font-size: 17px;
            font-weight: 600;
            text-decoration: none;
        }

        .button.secondary {
            background: #111827;
        }
    </style>
</head>

<body>
    @php($service = \Wm\WmPackage\Services\AppOpenService::class)
    <main>
        <h1>{{ $appName }}</h1>

        @if ($mode === $service::MODE_UNAVAILABLE)
            <p>{{ __('The app is not currently available on the stores, please try again later.') }}</p>
        @elseif ($mode === $service::MODE_WEBAPP)
            {{-- Ripiego visibile solo senza JavaScript: lo script qui sotto reindirizza subito. --}}
            <div class="buttons">
                <a class="button" href="{{ $webappUrl }}">{{ __('Open the web app') }}</a>
            </div>
        @else
            <p>{{ __('Download the app') }}</p>

            @if ($mode === $service::MODE_CHOICE && $platform === $service::PLATFORM_IOS && ! $iosLink)
                <p>{{ __('Not yet available for :platform', ['platform' => 'iOS']) }}</p>
            @endif
            @if ($mode === $service::MODE_CHOICE && $platform === $service::PLATFORM_ANDROID && ! $androidLink)
                <p>{{ __('Not yet available for :platform', ['platform' => 'Android']) }}</p>
            @endif

            <div class="buttons">
                @if ($mode === $service::MODE_ANDROID && $intentUrl)
                    <a class="button" href="{{ $intentUrl }}">{{ __('Open the app') }}</a>
                @endif
                @if ($mode === $service::MODE_CHOICE && $iosLink)
                    <a class="button secondary" href="{{ $iosLink }}">App Store</a>
                @endif
                @if ($androidLink)
                    <a class="button secondary" href="{{ $androidLink }}">Google Play</a>
                @endif
            </div>
        @endif
    </main>

    @if ($mode === $service::MODE_ANDROID && $intentUrl)
        <script>
            (function () {
                var intentUrl = @json($intentUrl);
                var storeUrl = @json($androidLink);

                window.location.href = intentUrl;
                // Se dopo il ritardo la pagina è ancora in primo piano, l'app non si è aperta.
                window.setTimeout(function () {
                    if (document.visibilityState === 'visible') {
                        window.location.replace(storeUrl);
                    }
                }, @json($androidFallbackDelayMs));
            })();
        </script>
    @endif

    @if ($mode === $service::MODE_WEBAPP)
        <script>
            (function () {
                @if ($iosLink)
                    // Safari su iPadOS si presenta come Mac: lo distingue il touch.
                    if (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1) {
                        window.location.replace(@json($iosLink));
                        return;
                    }
                @endif

                window.location.replace(@json($webappUrl));
            })();
        </script>
    @endif
</body>

</html>
