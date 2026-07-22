<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {{-- Open Graph (oc:8183): consumed by WhatsApp/Facebook/Twitter link-unfurling crawlers.
         This page is a static snapshot — see ShareUgcTrackController — so these values never
         change after the share was created, even if the underlying track is edited later. --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $imageUrl }}">

    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #12181f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }

        .wm-share-card {
            max-width: 420px;
            width: 100%;
            padding: 24px;
            box-sizing: border-box;
            text-align: center;
            color: #f5f5f5;
        }

        .wm-share-card img {
            max-width: 100%;
            height: auto;
            border-radius: 16px;
            display: block;
            margin: 0 auto 16px;
        }

        .wm-share-card h1 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="wm-share-card">
        <img src="{{ $imageUrl }}" alt="{{ $title }}">
        <h1>{{ $title }}</h1>
    </div>
</body>

</html>
