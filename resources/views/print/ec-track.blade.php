{{--
  Template LaTeX di default della scheda del sentiero (oc:8982).
  Gli shard lo sovrascrivono con una view omonima; le variabili sono un contratto pubblico.

  Variabili (le stringhe "escaped" sono già LaTeX-sicure, via LatexText: si stampano con {!! !!}):
  - $model            il modello (EcTrack/EcPoi), per gli override: la view di default non lo usa
  - $locale           lingua della scheda (it, en, ...)
  - $name             titolo, escaped
  - $descriptionLatex descrizione già convertita in LaTeX (stringa vuota se assente)
  - $stats            array<int,{label,value}>, già escaped
  - $mapFile          nome del file mappa nella cartella di lavoro, oppure null
  - $logoFile         nome del logo dell'App nella cartella di lavoro (PNG: «Icon small», ripiego
                       «Icon»), oppure null se l'App non ne ha uno utilizzabile
  - $photos           array<int,string> nomi dei file foto nella cartella di lavoro
  - $primaryColor     colore primario, esadecimale senza #
  - $styleFiles       list<string> nomi dei pacchetti di stile (senza .sty), da includere con
                      \usepackage nell'ordine dato: oggi solo `wm-print` del package (macro dei componenti
                      e default). Resta un elenco per gli override che lo scorrono con @foreach.
  - $primaryColor     colore primario dell'App, esadecimale senza #: wmprimary, da cui wmtitle
  - $secondaryColor, $tertiaryColor  esadecimali senza # oppure null: wmaccent e wmrule (null: come il
                      titolo / l'oro di wm-print). $fontContent, $fontHeader: nomi di font validati o null
                      (testo e titoli, solo con \IfFontExistsTF). Li applica il partial print.partials.theme.
                      Colori per ruolo: wmtitle (titoli), wmaccent (etichette, numeri in evidenza), wmrule
                      (filetti), wmtext (testo), wmmuted (didascalie, crediti), wmline (linee). \WmDefineRoles,
                      chiamata dopo wmprimary, dà il ripiego ai ruoli ancora indefiniti: wmtitle e wmaccent =
                      wmprimary, wmrule = wmgold, wmtext = nero, wmmuted = wmgray, wmline = nero. Uno shard
                      che vuole altri colori o font li definisce nel preambolo della propria view (prima di
                      \WmDefineRoles) o ridefinisce i ruoli. Il chiamante allega i file.
  - $mapWidthMm, $mapHeightMm  dimensioni (mm) della mappa chieste al servizio mappe
                      (print.map.width_mm/height_mm, default 174 x 98)
  - $generatedOn      data di generazione per il piè di pagina, già escaped
                      («Scheda generata il 24 novembre 2026», PrintService::generatedOn())
  - $labels           array chiave inglese -> testo tradotto (già escaped), tra cui Length,
                       Ascent, Descent, Duration, Difficulty, Description, Map, Photos,
                       Points of interest e la riga di credito OSM
  - $relatedPois   array<int,{name,typeLabel}>  POI correlati, già escaped.

  Attenzione: in LaTeX non scrivere "{{" letterale (Blade): usa uno spazio o @{{.
--}}
\documentclass[10pt,a4paper]{article}
@foreach ($styleFiles as $styleName)
\usepackage{{!! $styleName !!}}
@endforeach
\definecolor{wmprimary}{HTML}{{!! strtoupper($primaryColor) !!}}
@include('wm-package::print.partials.theme')
\WmDefineRoles
@include('wm-package::print.partials.footer', ['labels' => $labels])
\begin{document}
@if (! empty($logoFile))
\noindent\includegraphics[height=12mm,keepaspectratio]{{!! $logoFile !!}}\par\vspace{3mm}
@endif
\TitoloScheda{{!! $name !!}}
\FilettoOro
@if (! empty($stats))
\begin{Tabella}
@foreach ($stats as $stat)
\RigaTabella{{!! $stat['label'] !!}}{{!! $stat['value'] !!}}
@endforeach
\end{Tabella}
@endif
@if ($mapFile)

\TitoloSezione{{!! $labels['Map'] !!}}
\begin{center}
\includegraphics[width=\linewidth,height={!! (float) ($mapHeightMm ?? 98) !!}mm,keepaspectratio]{{!! $mapFile !!}}
\end{center}
@endif
@if (trim((string) $descriptionLatex) !== '')

\TitoloSezione{{!! $labels['Description'] !!}}
{!! $descriptionLatex !!}
@endif

@if (! empty($relatedPois))
\TitoloSezione{{!! $labels['Points of interest'] !!}}
\begin{itemize}
@foreach ($relatedPois as $related)
  \item {!! $related['name'] !!}@if (! empty($related['typeLabel'])) ({!! $related['typeLabel'] !!})@endif

@endforeach
\end{itemize}
@endif
@if (! empty($photos))

\TitoloSezione{{!! $labels['Photos'] !!}}
@foreach ($photos as $photo)
\includegraphics[width=0.48\linewidth,height=6cm,keepaspectratio]{{!! $photo !!}}\hfill
@endforeach
@endif
\end{document}
