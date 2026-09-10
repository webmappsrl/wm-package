<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;

/**
 * La storia dei cambi di stato come HTML di sola lettura per il detail.
 *
 * Nessuna Resource dedicata alla storia e nessun HasMany: decisione esplicita
 * del dev. Stesso schema di ConfigDetailPreviewRenderer (oc:8181).
 */
class CodeHistoryRenderer
{
    public static function render(TrailRegistryCodeModel $code): string
    {
        $rows = $code->events->map(function ($event) {
            return sprintf(
                '<tr><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 12px 4px 0">%s &rarr; %s</td><td style="padding:4px 12px 4px 0">%s</td><td style="padding:4px 0">%s</td></tr>',
                e($event->created_at->format('d/m/Y H:i')),
                e($event->from_status->value ?? '—'),
                e($event->to_status->value),
                e($event->reason),
                e($event->user->name ?? '—'),
            );
        })->implode('');

        if ($rows === '') {
            return '<p>Nessun passaggio registrato.</p>';
        }

        return '<table style="width:100%;font-size:0.875rem">'
            .'<thead><tr><th align="left">Quando</th><th align="left">Passaggio</th><th align="left">Causa</th><th align="left">Chi</th></tr></thead>'
            ."<tbody>{$rows}</tbody></table>";
    }
}
