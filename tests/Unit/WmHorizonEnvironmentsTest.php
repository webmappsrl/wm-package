<?php

declare(strict_types=1);

// oc:8773: Horizon avvia solo i supervisor elencati per l'ambiente in cui gira. Un supervisor
// definito in defaults ma assente da un ambiente lì non parte, e la sua coda resta ferma: è
// successo in produzione con supervisor-ugc-track-matching.
it('ogni supervisor dei default è elencato in ogni ambiente', function () {
    $config = require __DIR__.'/../../config/wm-horizon.php';

    foreach ($config['environments'] as $env => $supervisors) {
        foreach (array_keys($config['defaults']) as $supervisor) {
            expect(array_keys($supervisors))->toContain($supervisor);
        }
    }
});
