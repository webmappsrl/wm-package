/**
 * Righe del blocco «dati tecnici» sotto la mappa (oc:8742), dalla meta `technicalData` del campo.
 * Etichette e valori arrivano già tradotti e formattati dal PHP: qui si scartano solo le voci
 * senza etichetta. Lista vuota = nessun blocco.
 *
 * @param {unknown} rows
 * @returns {{label: string, value: string}[]}
 */
export function technicalDataRows(rows) {
    if (!Array.isArray(rows)) {
        return [];
    }

    return rows
        .filter((row) => row && typeof row.label === 'string' && row.label !== '')
        .map((row) => ({ label: row.label, value: row.value == null ? '' : String(row.value) }));
}
