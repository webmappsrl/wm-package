<?php

namespace Wm\WmPackage\Services\Print;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Conversione di testo e HTML degli editor in LaTeX sicuro.
 *
 * Il testo degli editor non diventa mai comandi LaTeX: l'escape si applica a tutti i nodi di testo
 * e i comandi LaTeX sono emessi solo da questa classe, a partire da un elenco chiuso di tag.
 */
class LatexText
{
    /** Segnaposto di `<br>`, risolto a fine conversione (evita `\\` a inizio/fine paragrafo). */
    private const BR = "\u{E000}";

    private const SKIPPED_TAGS = ['script', 'style', 'head', 'title', 'template', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'blockquote', 'section', 'article', 'header', 'footer', 'figure', 'figcaption', 'table', 'tr', 'pre'];

    /**
     * Escape di una stringa di testo semplice (nessun HTML).
     */
    public static function escape(string $text): string
    {
        $text = self::stripControlChars($text);

        return strtr($text, [
            '\\' => '\\textbackslash{}',
            '{' => '\\{',
            '}' => '\\}',
            '$' => '\\$',
            '&' => '\\&',
            '#' => '\\#',
            '%' => '\\%',
            '_' => '\\_',
            '~' => '\\textasciitilde{}',
            '^' => '\\textasciicircum{}',
            '<' => '\\textless{}',
            '>' => '\\textgreater{}',
            '|' => '\\textbar{}',
            '[' => '{[}',
            ']' => '{]}',
        ]);
    }

    /**
     * Converte HTML in LaTeX: i paragrafi sono separati da riga vuota, gli elenchi diventano
     * `itemize`/`enumerate`, i tag sconosciuti perdono il tag ma tengono il testo.
     */
    public static function fromHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('body')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        return self::finalize(self::renderChildren($root));
    }

    private static function renderChildren(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::renderNode($child);
        }

        return $out;
    }

    private static function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return self::renderText($node->data);
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SKIPPED_TAGS, true)) {
            return '';
        }

        switch ($tag) {
            case 'br':
                return self::BR;
            case 'strong':
            case 'b':
                return self::wrapInline('\\textbf', self::renderChildren($node));
            case 'em':
            case 'i':
                return self::wrapInline('\\emph', self::renderChildren($node));
            case 'ul':
            case 'ol':
                return self::renderList($node, $tag === 'ol' ? 'enumerate' : 'itemize');
            case 'li':
                // li fuori da una lista: trattato come paragrafo
                return "\n\n".self::renderChildren($node)."\n\n";
            case 'a':
                return self::renderLink($node);
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return "\n\n".self::wrapInline('\\textbf', self::renderChildren($node))."\n\n";
            case 'td':
            case 'th':
                return ' '.self::renderChildren($node).' ';
            case 'hr':
                return "\n\n";
        }

        if (in_array($tag, self::BLOCK_TAGS, true)) {
            return "\n\n".self::renderChildren($node)."\n\n";
        }

        // tag sconosciuto (img, span, font, ...): si tiene solo il testo contenuto
        return self::renderChildren($node);
    }

    private static function renderText(string $text): string
    {
        $text = self::stripControlChars($text);
        // spazio non separabile -> tilde LaTeX; il resto degli spazi (anche a capo) collassa
        $text = preg_replace('/[ \t\r\n\f]+/u', ' ', $text) ?? '';

        return str_replace("\u{00A0}", '~', self::escape($text));
    }

    private static function renderList(DOMElement $list, string $env): string
    {
        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $content = self::finalize(self::renderChildren($child));
                if ($content !== '') {
                    $items[] = '\\item '.$content;
                }
            }
        }

        if ($items === []) {
            return '';
        }

        return "\n\n\\begin{".$env."}\n".implode("\n", $items)."\n\\end{".$env."}\n\n";
    }

    private static function renderLink(DOMElement $a): string
    {
        $inner = self::renderChildren($a);
        $url = self::safeUrl(trim($a->getAttribute('href')));
        if ($url === null) {
            return $inner;
        }

        $plain = trim($a->textContent);
        $same = rtrim(preg_replace('#^(https?://|mailto:)#i', '', $plain) ?? '', '/')
            === rtrim(preg_replace('#^(https?://|mailto:)#i', '', trim($a->getAttribute('href'))) ?? '', '/');
        if (trim($inner) === '' || $same) {
            return '\\url{'.$url.'}';
        }

        return $inner.' (\\url{'.$url.'})';
    }

    /**
     * Restituisce l'URL pronto per `\url{}` oppure null se lo schema non è http(s)/mailto.
     */
    private static function safeUrl(string $href): ?string
    {
        $href = self::stripControlChars($href);
        if (! preg_match('#^(https?://|mailto:)#i', $href)) {
            return null;
        }

        // niente spazi né caratteri che LaTeX interpreterebbe: li si codifica come in un URL
        $href = preg_replace_callback('/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/u', fn ($m) => rawurlencode($m[0]), $href) ?? '';

        return strtr($href, ['%' => '\\%', '#' => '\\#', '[' => '%5B', ']' => '%5D']);
    }

    /**
     * Applica un comando di formattazione inline. Se il contenuto contiene blocchi (paragrafi,
     * elenchi) il comando non li può racchiudere: si tiene il contenuto senza formattazione.
     */
    private static function wrapInline(string $command, string $inner): string
    {
        if (trim(str_replace(self::BR, '', $inner)) === '') {
            return $inner;
        }
        // `\url{}` dentro l'argomento di un comando è fragile (catcode già fissati)
        if (str_contains($inner, "\n") || str_contains($inner, self::BR) || str_contains($inner, '\\url{')) {
            return $inner;
        }

        // spazi ai bordi fuori dal gruppo, per non avere `\textbf{ x }`
        $lead = preg_match('/^ +/', $inner, $m) ? $m[0] : '';
        $trail = preg_match('/ +$/', $inner, $m) ? $m[0] : '';

        return $lead.$command.'{'.trim($inner).'}'.$trail;
    }

    private static function finalize(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? '';
        // `\\` non può stare a inizio o fine paragrafo
        $br = preg_quote(self::BR, '/');
        $text = preg_replace('/(^|\n)'.$br.'+/u', '$1', $text) ?? '';
        $text = preg_replace('/'.$br.'+(?=\n|$)/u', '', $text) ?? '';
        $text = preg_replace('/(?:'.$br.' ?){2,}/u', self::BR, $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';
        $text = str_replace(self::BR, '\\\\{} ', $text);
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? '';

        return trim($text);
    }

    private static function stripControlChars(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{E000}-\x{F8FF}]|\x{2028}|\x{2029}/u', '', $text) ?? '';
    }
}
