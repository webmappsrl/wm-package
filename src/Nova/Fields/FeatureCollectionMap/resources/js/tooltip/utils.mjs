/**
 * Tooltip con tutte le linee sotto il mouse (oc:8776, opzione `stackedTooltip`): il testo di
 * ogni feature (`tooltip`, altrimenti `name`), senza ripetere lo stesso testo. Prima le feature
 * con `tooltipOrder`, in ordine crescente (oc:8776: traccia registrata, poi tratti ricostruiti),
 * poi le altre nell'ordine in cui le dà getFeaturesAtPixel. Serve dove la traccia copre le tappe:
 * passando il mouse si legge anche quale tappa percorre.
 *
 * @param {Array<Record<string, any>|null>} propsList
 * @returns {string}
 */
export function stackedTooltipText(propsList) {
    const list = Array.isArray(propsList) ? propsList : [];
    // Prima le linee con `tooltipOrder` (crescente), poi le altre nell'ordine ricevuto.
    const order = (p) => (typeof p?.tooltipOrder === 'number' ? p.tooltipOrder : Infinity);
    const sorted = list.map((p, i) => [p, i]).sort((a, b) => order(a[0]) - order(b[0]) || a[1] - b[1]).map(([p]) => p);
    const seen = [];
    for (const p of sorted) {
        const text = String(p?.tooltip || p?.name || '').trim();
        if (text !== '' && !seen.includes(text)) {
            seen.push(text);
        }
    }

    return seen.join('\n');
}
