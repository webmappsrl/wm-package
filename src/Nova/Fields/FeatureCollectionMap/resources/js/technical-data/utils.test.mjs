import { describe, expect, it } from 'vitest';
import { technicalDataRows } from './utils.mjs';

describe('technicalDataRows', () => {
    it('restituisce le righe etichetta/valore nell\'ordine ricevuto', () => {
        const rows = [
            { label: 'Distanza', value: '9,37 km' },
            { label: 'Salita', value: '313 m' },
        ];
        expect(technicalDataRows(rows)).toEqual(rows);
    });

    it('lista vuota, null o non array: nessun blocco', () => {
        expect(technicalDataRows([])).toEqual([]);
        expect(technicalDataRows(null)).toEqual([]);
        expect(technicalDataRows(undefined)).toEqual([]);
        expect(technicalDataRows('x')).toEqual([]);
    });

    it('scarta le voci senza etichetta e converte il valore in stringa', () => {
        expect(technicalDataRows([
            null,
            { value: '1 km' },
            { label: '', value: '1 km' },
            { label: 'Tempo', value: 165 },
            { label: 'Velocità media' },
        ])).toEqual([
            { label: 'Tempo', value: '165' },
            { label: 'Velocità media', value: '' },
        ]);
    });
});
