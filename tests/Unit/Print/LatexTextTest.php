<?php

use Wm\WmPackage\Services\Print\LatexText;

it('escapes every LaTeX special character', function () {
    $out = LatexText::escape('50% & #1 $x_y {a} ~ ^ \\');

    expect($out)->toBe('50\\% \\& \\#1 \\$x\\_y \\{a\\} \\textasciitilde{} \\textasciicircum{} \\textbackslash{}');
    // nessun carattere speciale rimasto fuori da un escape
    $stripped = preg_replace('/\\\\(textbackslash|textasciitilde|textasciicircum)\{\}|\\\\[%&#$_{}]/', '', $out);
    expect($stripped)->not->toMatch('/[%&#$_{}~^\\\\]/');
});

it('never turns editor text into commands', function () {
    $out = LatexText::fromHtml('<p>\\input{/etc/passwd} \\write18{ls}</p>');

    expect($out)->toContain('\\textbackslash{}input')
        ->and($out)->toContain('\\textbackslash{}write18')
        ->and($out)->not->toContain('\\input{');
});

it('converts paragraphs, breaks, emphasis and lists', function () {
    expect(LatexText::fromHtml('<p>Uno</p><p>Due<br>tre</p>'))->toBe("Uno\n\nDue\\\\{} tre");
    expect(LatexText::fromHtml('<p>a <strong>b</strong> <b>c</b> <em>d</em> <i>e</i></p>'))
        ->toBe('a \\textbf{b} \\textbf{c} \\emph{d} \\emph{e}');
    expect(LatexText::fromHtml('<ul><li>A</li><li>B<ol><li>B1</li></ol></li></ul>'))
        ->toBe("\\begin{itemize}\n\\item A\n\\item B\n\n\\begin{enumerate}\n\\item B1\n\\end{enumerate}\n\\end{itemize}");
    expect(LatexText::fromHtml('<ul></ul><ul><li> </li></ul>'))->toBe('');
});

it('converts links keeping text and escaped url', function () {
    expect(LatexText::fromHtml('<a href="https://x.it/a_b?q=1&p=50%#h">Sito</a>'))
        ->toBe('Sito (\\url{https://x.it/a_b?q=1&p=50\\%\\#h})');
    expect(LatexText::fromHtml('<a href="javascript:alert(1)">Testo</a>'))->toBe('Testo');
    expect(LatexText::fromHtml('<a href="https://x.it">https://x.it</a>'))->toBe('\\url{https://x.it}');
});

it('drops unknown tags but keeps their text', function () {
    expect(LatexText::fromHtml('<table><tr><td>uno</td><td>due</td></tr></table>'))->toBe('uno due');
    expect(LatexText::fromHtml('a<img src="x.png" alt="no">b'))->toBe('ab');
    expect(LatexText::fromHtml('<script>alert(1)</script>'))->toBe('');
    expect(LatexText::fromHtml('x<script>\\input{a}</script>y'))->toBe('xy');
    expect(LatexText::fromHtml('<span style="color:red">rosso</span>'))->toBe('rosso');
});

it('decodes entities before escaping and keeps UTF-8', function () {
    expect(LatexText::fromHtml('<p>a &amp; b &egrave; &lt;x&gt;</p>'))->toBe('a \\& b è \\textless{}x\\textgreater{}');
    expect(LatexText::fromHtml('<p>l’acqua, «sì», à</p>'))->toBe('l’acqua, «sì», à');
    expect(LatexText::fromHtml('<p>10&nbsp;km</p>'))->toBe('10~km');
});

it('does not create paragraph breaks from newlines in text', function () {
    expect(LatexText::fromHtml("<p>riga uno\n   riga due\n\nriga tre</p>"))->toBe('riga uno riga due riga tre');
    expect(LatexText::fromHtml('<div>a</div><div>b</div>'))->toBe("a\n\nb");
});

it('handles null, empty and malformed input', function () {
    expect(LatexText::fromHtml(null))->toBe('');
    expect(LatexText::fromHtml(''))->toBe('');
    expect(LatexText::fromHtml('<p>aperto <b>grassetto'))->toBe('aperto \\textbf{grassetto}');
    expect(LatexText::fromHtml('<p><br>x<br></p>'))->toBe('x');
    expect(LatexText::fromHtml('<b><p>a</p><p>b</p></b>'))->toBe("a\n\nb");
});

it('keeps content after an unbalanced closing div', function () {
    expect(LatexText::fromHtml('<p>a</p></div><p>b</p>'))->toBe("a\n\nb");
});

it('never nests url inside a formatting command', function () {
    $out = LatexText::fromHtml('<b>x <a href="https://x.it/a_b">s</a></b> <em><a href="https://y.it">s</a></em>');

    expect($out)->toContain('\\url{https://x.it/a_b}')
        ->and($out)->not->toMatch('/\\\\(textbf|emph)\\{[^}]*\\\\url/');
});
