{{--
  Template LaTeX di default della mappa d'insieme (oc:8982): A4 orizzontale, una sola pagina:
  titolo, mappa a sinistra e legenda in colonna a destra.
  Gli shard lo sovrascrivono con una view omonima; le variabili sono un contratto pubblico.

  Variabili (le stringhe "escaped" sono già LaTeX-sicure, via LatexText: si stampano con {!! !!}):
  - $locale        lingua (it, en, ...)
  - $mapFile       nome del file mappa nella cartella di lavoro
  - $mapWidthMm, $mapHeightMm  area della mappa (200x170 mm)
  - $legend        array<int,{ref,name}> un elemento per sentiero, già escaped (ref può essere vuoto)
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
  - $labels        array chiave inglese -> testo tradotto (già escaped): Overview map, Legend,
                   riga di credito OSM

  Niente \newpage/\clearpage: la legenda sta nella colonna (con 50 sentieri entra in \scriptsize). Attenzione: in LaTeX non scrivere "{{" letterale (Blade).
--}}
\documentclass[10pt,a4paper]{article}
@foreach ($styleFiles as $styleName)
\usepackage{{!! $styleName !!}}
@endforeach
\geometry{landscape,left=10mm,right=10mm,top=6mm,bottom=14mm,footskip=6mm}
\definecolor{wmprimary}{HTML}{{!! strtoupper($primaryColor) !!}}
@include('wm-package::print.partials.theme')
\WmDefineRoles
@include('wm-package::print.partials.footer', ['labels' => $labels])
\begin{document}
{\color{wmtitle}\WmHeaderFont\bfseries\large {!! $labels['Overview map'] !!}\par}
\vspace{2mm}
\noindent\begin{minipage}[t]{{!! (int) $mapWidthMm !!}mm}
\vspace{0pt}\includegraphics[width={!! (int) $mapWidthMm !!}mm,height={!! (int) $mapHeightMm !!}mm,keepaspectratio]{{!! $mapFile !!}}
\end{minipage}\hfill
\begin{minipage}[t]{72mm}
\vspace{0pt}\raggedright\scriptsize
{\color{wmtitle}\bfseries {!! $labels['Legend'] !!}\par}
\setlength{\parskip}{0.2em}
@foreach ($legend as $item)
@if (trim((string) $item['ref']) !== '')\textbf{{!! $item['ref'] !!}} --- @endif{!! $item['name'] !!}\par
@endforeach
\end{minipage}
\end{document}
