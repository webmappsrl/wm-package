import { describe, expect, it } from 'vitest';
import { createViewMemory } from './view-memory.mjs';

describe('memoria della vista fra un montaggio e l altro (oc:8662)', () => {
    const view = { center: [1, 2], zoom: 14 };

    it('restituisce la vista salvata allo smontaggio appena prima', () => {
        const memory = createViewMemory(5000);
        memory.remember('trail-applications/2', view, 1000);
        expect(memory.recall('trail-applications/2', 3000)).toEqual(view);
    });

    it('non la restituisce se e passato troppo tempo: e una nuova apertura della scheda', () => {
        const memory = createViewMemory(5000);
        memory.remember('trail-applications/2', view, 1000);
        expect(memory.recall('trail-applications/2', 7000)).toBeNull();
    });

    it('non confonde due schede diverse', () => {
        const memory = createViewMemory(5000);
        memory.remember('trail-applications/2', view, 1000);
        expect(memory.recall('trail-applications/3', 1500)).toBeNull();
    });

    it('si usa una volta sola', () => {
        const memory = createViewMemory(5000);
        memory.remember('trail-applications/2', view, 1000);
        memory.recall('trail-applications/2', 1500);
        expect(memory.recall('trail-applications/2', 1600)).toBeNull();
    });

    it('ignora una vista incompleta', () => {
        const memory = createViewMemory(5000);
        memory.remember('trail-applications/2', { center: null, zoom: 14 }, 1000);
        expect(memory.recall('trail-applications/2', 1500)).toBeNull();
    });
});
