{{--
  Tema dell'App per la scheda di default (oc:8982, R46-R47). Si include dopo \definecolor{wmprimary}
  e PRIMA di \WmDefineRoles: i ruoli definiti qui vincono sul ripiego.
  Variabili (tutte facoltative; già validate da PrintService, quindi sicure per il .tex):
  - $secondaryColor, $tertiaryColor  esadecimali senza # o null: wmaccent e wmrule (vuoto: titolo / oro)
  - $fontContent, $fontHeader        nomi di font o null: testo e titoli, solo se il font è installato
--}}
@if (! empty($secondaryColor))
\definecolor{wmaccent}{HTML}{{!! strtoupper($secondaryColor) !!}}
@endif
@if (! empty($tertiaryColor))
\definecolor{wmrule}{HTML}{{!! strtoupper($tertiaryColor) !!}}
@endif
@if (! empty($fontContent))
\IfFontExistsTF{{!! $fontContent !!}}{\setmainfont{{!! $fontContent !!}}\setsansfont{{!! $fontContent !!}}}{}
@endif
@if (! empty($fontHeader))
\IfFontExistsTF{{!! $fontHeader !!}}{\newfontfamily\WmHeaderFontFamily{{!! $fontHeader !!}}\renewcommand{\WmHeaderFont}{\WmHeaderFontFamily}}{}
@endif
