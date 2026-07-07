> Ticket: oc:8218

# Notes — comandi migration stub wm-package

## Comandi

- `publish-missing-migrations` (+ `--dry-run` = gate CI consumer)
- `publish-migration <stub>` — singolo stub, ignora falsi positivi da suffisso

## Review

- `publish-migration` rifiuta se schema già applicato o file identico non migrato
- `create_users_table`: suffisso Laravel maschera stub wm-package — `--dry-run` lo rileva

Pipeline e casi d'uso end-to-end: `maphub/docs/features/8218-cicd-migration-wm-package-permission-cache/overview.md`.
