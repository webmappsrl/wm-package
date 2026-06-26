> Ticket: oc:8139

# Associazione automatica EcPoi al layer della traccia — wm-package

## Cosa cambia

`EcPoiEcTrackObserver` intercetta aggiunta/rimozione di un `EcPoi` da una `EcTrack` e sincronizza la relazione `ecPois` del layer. La relazione `manualEcPois` viene rinominata `ecPois` con alias per backwards compatibility.

## Moduli toccati

- `src/Observers/EcPoiEcTrackObserver.php`
- `src/Models/Layer.php`
- `src/Models/EcPoi.php`
- `src/Nova/Layer.php`
