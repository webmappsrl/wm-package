<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('The PDF could not be generated', [], $locale) }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f6f6f6; color: #222; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('The PDF could not be generated', [], $locale) }}</h1>
    <p role="alert">{{ $message }}</p>
</main>
</body>
</html>
