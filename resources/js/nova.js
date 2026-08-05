Nova.booting(() => {
    // kongulov/nova-tab-translatable measures its own tab bar width once, on mount, to
    // decide which locale tabs collapse into the hamburger overflow menu. If that
    // measurement happens before Nova finishes laying out the panel/Flexible group the
    // field lives in (observed on config_home's Flexible layouts, e.g. App "Title" —
    // oc:8349), the field gets stuck showing every tab folded into the hamburger
    // regardless of how much room is actually available, because the component only
    // recalculates on window resize, never on a later layout change. Redispatching resize
    // once the page settles after a Vue re-render lets the component's own existing
    // listener recheck with the real, settled width — no vendor patch needed.
    //
    // Nova 5's `Nova.booting(app, store)` doesn't expose the Vue Router instance (that was
    // the Vue 2 era's `(Vue, router, store)` signature), so this watches the DOM directly
    // instead of hooking route-change events — version-independent, and also covers
    // non-navigation layout changes (e.g. expanding a collapsible Panel).
    let resizeTimer = null;

    const scheduleResize = () => {
        // Cheap guard (found in review: this observer/dispatch ran on EVERY class/style
        // mutation across the whole page, even pages with no translatable-tab field at
        // all — the vast majority of Nova pages). Skipping the dispatch on those pages
        // avoids waking up every OTHER resize listener on the page (Leaflet maps, charts,
        // ...) for a fix that only ever needs to run when this field is actually present.
        if (!document.querySelector('#nova-tab-translatable')) {
            return;
        }

        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => window.dispatchEvent(new Event('resize')), 300);
    };

    // attributes: true (not just childList) because Nova's own resource tabs and
    // collapsible Panels toggle visibility via a style/class attribute change (v-show),
    // not by adding/removing the field from the DOM — a field sitting in a still-hidden
    // tab correctly measures 0 width at mount and collapses everything, but needs a fresh
    // resize once that tab is actually selected and its width becomes real.
    new MutationObserver(scheduleResize).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class'],
    });
    scheduleResize();

    // "Insert embed" support (oc:8349 follow-up) is opt-in per field: FlexibleTranslatable
    // ::wireRichTextField() tags its Trix field with `extraAttributes` so the rendered
    // <trix-editor> carries this attribute — every OTHER Trix field in the package (and in
    // any other consumer app using wm-package) is left untouched. Found in review: an
    // earlier version wired the button/paste handling globally on every <trix-editor> on
    // the page, but expandEmbedMarkers()/HTMLPurifier only exist inside
    // FlexibleTranslatable — a plain Trix field elsewhere would have shown the button and
    // intercepted paste, then saved the raw `[[embed:...]]` marker literally, un-expanded.
    const EMBED_SUPPORT_ATTR = 'data-wm-embed-support';
    const supportsEmbed = (editorElement) => !!editorElement && editorElement.hasAttribute(EMBED_SUPPORT_ATTR);

    // btoa()/atob() operate on Latin-1 code units and THROW (InvalidCharacterError) on any
    // character outside U+0000-U+00FF — found in review, reproduced live with a realistic
    // embed title containing an em-dash ("Rick Astley – Never Gonna Give You Up"), common
    // in real video titles and in anything an Italian editor might type/paste. Encoding the
    // UTF-8 byte representation first (standard workaround) keeps every code point safe;
    // PHP's base64_decode() on the server needs no change, since it already decodes into
    // the raw UTF-8 bytes Laravel/the DB expect.
    const toBase64Unicode = (str) =>
        window.btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, (_, hex) => String.fromCharCode('0x' + hex)));

    const buildEmbedMarker = (type, content) => '[[embed:' + type + ':' + toBase64Unicode(content) + ']]';

    // Nova's Trix.vue renders a bare <trix-editor> with no explicit toolbar markup, so
    // Trix auto-builds one from Trix.config.toolbar.getDefaultHTML() — the documented,
    // supported extension point for adding custom buttons (Trix has no built-in "insert
    // arbitrary HTML" action). That override must happen before any <trix-editor> on the
    // page initializes, since Trix reads the function once per editor at mount time — but
    // `window.Trix` is NOT reliably set yet when this Nova.booting() callback itself runs
    // (verified live: undefined at boot, defined moments later), so the override is
    // deferred to the FIRST `trix-before-initialize` event instead. Trix dispatches that
    // event, on the editor element, from its own already-evaluated module code — by
    // definition `window.Trix` exists by then, and it still fires before THAT SAME editor
    // reads getDefaultHTML(), so the override lands in time for it too.
    //
    // getDefaultHTML() has no reference to which specific editor is initializing, so the
    // button is injected unconditionally for every toolbar and then removed straight after
    // (see the `trix-initialize` listener below) for editors that don't opt in — simpler and
    // more robust than trying to thread per-editor state through a shared static hook.
    //
    // Inserting via Trix's own "attachment" API (Trix.Attachment with contentType
    // application/vnd.trix-instant-content) was tried and reverted — verified live that
    // Trix serializes that content as an HTML-escaped string inside a
    // `data-trix-attachment` JSON attribute, not as inline markup, so the value our
    // server receives still has the iframe escaped as text (the exact bug this feature
    // exists to fix). Instead, the button inserts a plain-text marker
    // (`[[embed:url:<base64>]]` or `[[embed:html:<base64>]]`) via editor.insertString() —
    // an ordinary Editor API Trix has always supported for typing, so no attachment
    // serialization quirks apply. FlexibleTranslatable::wireRichTextField() expands that
    // marker into a real <iframe> *before* running it through the same HTMLPurifier
    // sanitization as any other pasted/typed content — this button only solves getting a
    // recognizable placeholder INTO Trix; it grants no extra trust, and the expanded
    // iframe is still fully subject to makePurifier()'s whitelist/URI checks.
    let embedToolingReady = false;

    document.addEventListener('trix-before-initialize', () => {
        if (embedToolingReady || !window.Trix) {
            return;
        }

        embedToolingReady = true;

        const originalGetDefaultHTML = Trix.config.toolbar.getDefaultHTML;

        // Trix's default markup is `<div class="trix-button-row">...groups...</div><div
        // class="trix-dialogs">...</div>`. Simply appending our group AFTER the full string
        // (a first attempt) lands it as a sibling of trix-button-row instead of inside it,
        // so it renders on its own row below Bold/Italic/etc. instead of inline with them
        // (verified live: it rendered full-width, looking like a stray text input).
        // Inserting right before the last `</div>` that precedes the dialogs container
        // puts it inside trix-button-row, alongside the other button groups.
        Trix.config.toolbar.getDefaultHTML = function () {
            const original = originalGetDefaultHTML.call(this);
            const embedGroup =
                '<span class="trix-button-group" data-trix-button-group="wm-embed-tools">' +
                '<button type="button" class="trix-button" data-wm-embed-button title="Insert embed">Embed</button>' +
                '</span>';

            const dialogsIndex = original.indexOf('<div class="trix-dialogs"');
            if (dialogsIndex === -1) {
                return original + embedGroup;
            }

            const insertAt = original.lastIndexOf('</div>', dialogsIndex);
            if (insertAt === -1) {
                return original + embedGroup;
            }

            return original.slice(0, insertAt) + embedGroup + original.slice(insertAt);
        };
    });

    // Strips the unconditionally-injected button back out for every editor that didn't opt
    // in via EMBED_SUPPORT_ATTR — fires per editor, after Trix has built and linked its
    // toolbar (unlike trix-before-initialize, which only fires once globally for the
    // getDefaultHTML() override above).
    document.addEventListener('trix-initialize', (event) => {
        const editorElement = event.target;
        if (supportsEmbed(editorElement)) {
            return;
        }

        const toolbarId = editorElement.getAttribute('toolbar');
        const toolbarElement = toolbarId && document.getElementById(toolbarId);
        const embedGroup = toolbarElement && toolbarElement.querySelector('[data-trix-button-group="wm-embed-tools"]');

        if (embedGroup) {
            embedGroup.remove();
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-wm-embed-button]');
        if (!button) {
            return;
        }

        event.preventDefault();

        const toolbarElement = button.closest('trix-toolbar');
        const editorElement =
            toolbarElement && document.querySelector('trix-editor[toolbar="' + toolbarElement.id + '"]');

        if (!editorElement || !supportsEmbed(editorElement)) {
            return;
        }

        const input = window.prompt(
            "Insert embed URL (YouTube, Vimeo, Google Maps 'embed' link, ...) or paste a full <iframe> snippet:\n\n" +
                'A placeholder like [[embed:...]] will appear in the text — the real embed shows once saved and viewed.'
        );

        if (!input) {
            return;
        }

        const trimmed = input.trim();
        const isHtmlSnippet = trimmed.startsWith('<');

        editorElement.editor.insertString(buildEmbedMarker(isHtmlSnippet ? 'html' : 'url', trimmed));
    });

    // Pasting an embed code directly (no toolbar button) — verified live that this is the
    // realistic path most editors will actually use, and that without this handler it does
    // NOT work: YouTube/Vimeo/etc.'s own "Share > Embed" box is a <textarea>, and copying
    // from a textarea/input only ever puts text/plain on the OS clipboard (never
    // text/html), so the paste reaches Trix as plain text. Trix's default plain-text paste
    // path HTML-escapes it into inert literal text (`&lt;iframe...&gt;`) — the exact bug
    // this whole feature exists to fix, just via paste instead of typing. Intercepting the
    // paste event and reading clipboardData directly (before Trix's own handler touches it)
    // lets us catch that case and redirect it through the same `[[embed:html:<base64>]]`
    // marker + expandEmbedMarkers()/HTMLPurifier pipeline the toolbar button uses — same
    // trust boundary, no new risk.
    // Capture phase, not bubble: Trix binds its own paste listener directly on the
    // <trix-editor> element. In the bubble phase that element-level listener runs BEFORE a
    // delegated document-level one reaches it, so by the time our handler called
    // preventDefault() Trix had already synchronously inserted its escaped-text fallback
    // (verified live: both the marker AND the escaped text ended up in the field).
    // Capture-phase listeners on an ancestor run before ANY bubble-phase listener on the
    // target, so this runs first — stopPropagation() below then keeps Trix's own listener
    // from ever seeing the event at all once we've decided to handle it ourselves.
    document.addEventListener(
        'paste',
        (event) => {
            const editorElement = event.target.closest && event.target.closest('trix-editor');
            if (!editorElement || !editorElement.editor || !supportsEmbed(editorElement)) {
                return;
            }

            const clipboardData = event.clipboardData || window.clipboardData;
            if (!clipboardData) {
                return;
            }

            const html = clipboardData.getData('text/html');
            const text = clipboardData.getData('text/plain');
            const markers = [];
            let remainderHtml = null;

            if (html && /<iframe\b/i.test(html)) {
                // Pasting a RENDERED embed from a real webpage (as opposed to copying its
                // embed-code textarea) puts real HTML on the clipboard — extract every
                // <iframe> from it into its own marker, keep whatever surrounding HTML
                // (captions, headings, ...) for Trix's own normal HTML-paste handling.
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                const iframes = parsed.body.querySelectorAll('iframe');

                if (iframes.length) {
                    iframes.forEach((iframe) => {
                        markers.push(buildEmbedMarker('html', iframe.outerHTML));
                        iframe.remove();
                    });
                    remainderHtml = parsed.body.innerHTML.trim();
                }
            } else if (text && /^<iframe\b[\s\S]*<\/iframe>$/i.test(text.trim())) {
                // The realistic case: an embed-code textarea's clipboard content is nothing
                // BUT the iframe snippet. Matching the ENTIRE trimmed paste (not just
                // "contains an iframe somewhere") avoids hijacking a paste that merely
                // mentions "<iframe>" inside unrelated prose/code — those still get Trix's
                // normal (escaped-text) handling, unchanged from before this feature existed.
                markers.push(buildEmbedMarker('html', text.trim()));
            }

            if (!markers.length) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (remainderHtml) {
                editorElement.editor.insertHTML(remainderHtml);
            }

            markers.forEach((marker) => editorElement.editor.insertString(marker));
        },
        true
    );
});
