{{-- Piè di pagina di default. Riceve $labels (chiave inglese -> testo tradotto) e, se c'è, $generatedOn
     (data di generazione già escaped, PrintService::generatedOn()). --}}
\PiePagina{{!! $labels['© OpenStreetMap contributors, Webmapp'] ?? '© OpenStreetMap contributors, Webmapp' !!}@if (! empty($generatedOn))\quad {!! $generatedOn !!}@endif}
