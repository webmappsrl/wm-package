import { describe, expect, it } from 'vitest';
import { stackedTooltipText } from './utils.mjs';

describe('stackedTooltipText', () => {
    it('una riga per feature, dall\'alto, senza doppioni', () => {
        expect(stackedTooltipText([
            { tooltip: 'Traccia registrata' },
            { tooltip: 'Traccia registrata' },
            { tooltip: 'Via degli Dei — Tappa 3' },
            { name: 'Senza tooltip' },
            { tooltip: 'Via degli Dei — Tappa 3' },
        ])).toBe('Traccia registrata\nVia degli Dei — Tappa 3\nSenza tooltip');
    });

    it('prima le feature con tooltipOrder, in ordine crescente, poi le altre come arrivano', () => {
        expect(stackedTooltipText([
            { tooltip: 'Tappa 1' },
            { tooltip: 'Tratto ricostruito', tooltipOrder: 1 },
            { tooltip: 'Tappa 2' },
            { tooltip: 'Traccia registrata', tooltipOrder: 0 },
        ])).toBe('Traccia registrata\nTratto ricostruito\nTappa 1\nTappa 2');
    });

    it('niente testo: stringa vuota', () => {
        expect(stackedTooltipText([{}, { tooltip: '  ' }, null])).toBe('');
        expect(stackedTooltipText(null)).toBe('');
    });
});
