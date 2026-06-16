# Guida alla Configurazione Mailpit con Apache Reverse Proxy

Questo documento descrive come esporre Mailpit tramite HTTPS su un path dedicato del dominio di uno shard (es. `https://<shard>.dev.maphub.it/mailpit/`).

## maphub vs shard

- **Piattaforma maphub**: host pubblico tipico `www.maphub.it`.
- **Shard** (es. un progetto su infrastruttura maphub): FQDN dedicato, es. `<progetto>.dev.maphub.it`.

## URL finale

```
https://<shard-fqdn>/mailpit/
```

## 1. Docker Compose

Nel servizio `mailpit` di `develop.compose.yml`:

```yaml
mailpit:
  container_name: "mailpit-${APP_NAME}"
  image: "axllent/mailpit:latest"
  environment:
    MP_WEBROOT: mailpit
  ports:
    - "127.0.0.1:${FORWARD_MAILPIT_PORT:-1025}:1025"
    - "127.0.0.1:${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025"
```

Note:

- `MP_WEBROOT: mailpit` fa servire l'interfaccia su `http://localhost:8025/mailpit/` (obbligatorio dietro subpath).
- Le porte sono legate a `127.0.0.1`: accesso web solo tramite Apache, non direttamente da Internet.
- Laravel continua a usare `MAIL_HOST=mailpit` e `MAIL_PORT=1025` (rete Docker interna).

Riavvio container:

```bash
docker compose -f local.compose.yml up -d mailpit
```

## 2. Moduli Apache

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers
sudo systemctl restart apache2
```

## 3. Configurazione automatica

```bash
export MAPHUB_PUBLIC_FQDN=<shard-fqdn>              # es. myproject.dev.maphub.it
export MAPHUB_APACHE_CONF_BASENAME=<conf-basename>  # es. myproject.maphub.it
sudo bash wm-package/scripts/configura_apache_mailpit.sh
```

## 4. Configurazione manuale Apache HTTPS

Aggiungere nel vhost SSL (prima di `</VirtualHost>`):

```apache
# Redirect /mailpit a /mailpit/
RewriteRule ^/mailpit$ /mailpit/ [R=301,L]

# WebSocket (prima del proxy HTTP)
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/mailpit/(.*) ws://localhost:8025/mailpit/$1 [P,L]

# Proxy HTTP
ProxyPass /mailpit/ http://localhost:8025/mailpit/
ProxyPassReverse /mailpit/ http://localhost:8025/mailpit/

<LocationMatch "^/mailpit">
    Header unset X-Frame-Options
    Header always set X-Frame-Options "SAMEORIGIN"
</LocationMatch>
```

`ProxyPreserveHost On` è già presente nel vhost dello shard.

## 5. Verifica

```bash
curl -I https://<shard-fqdn>/mailpit/
apache2ctl configtest
```

Invia una mail di test dall'app e controlla che compaia nella UI.

## 6. Autenticazione (opzionale, consigliata su ambienti esposti)

Mailpit supporta basic auth tramite file password:

```bash
# Genera file htpasswd
docker run --rm httpd:2.4-alpine htpasswd -nbB admin 'password-sicura' > /path/to/mailpit-auth
chmod 600 /path/to/mailpit-auth
```

In `develop.compose.yml`:

```yaml
environment:
  MP_WEBROOT: mailpit
  MP_UI_AUTH_FILE: /data/mailpit-auth
volumes:
  - ./docker/volumes/mailpit:/data
```

Documentazione: [Mailpit UI auth](https://mailpit.axllent.org/docs/configuration/runtime-options/)

## Troubleshooting

- **404 o asset mancanti**: verificare che `MP_WEBROOT` corrisponda al path Apache (`mailpit` → `/mailpit/`).
- **UI che non si aggiorna in tempo reale**: controllare le regole WebSocket in Apache ([docs proxy Mailpit](https://mailpit.axllent.org/docs/configuration/proxy/)).
- **Errore Origin/CORS**: `ProxyPreserveHost On` deve essere attivo nel vhost.
