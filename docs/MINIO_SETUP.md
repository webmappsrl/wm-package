# Guida alla Configurazione MinIO con Apache Reverse Proxy

Questo documento descrive tutte le modifiche necessarie per configurare MinIO con la console accessibile tramite Apache reverse proxy.

## maphub vs shard

- **Piattaforma maphub** (contenitore delle app base): host pubblico **`www.maphub.it`**. Negli esempi compaiono i file vhost tipici `maphub.it.conf` / `maphub.it-le-ssl.conf`.
- **Shard** (es. Cammini d’Italia): macchina dedicata con FQDN propri, es. **`maphub.it`**. Adatta `ServerName`, URL e nomi file Apache al dominio dello shard.

## Indice

1. [Modifiche Docker Compose](#modifiche-docker-compose)
2. [Moduli Apache Necessari](#moduli-apache-necessari)
3. [Configurazione Apache HTTP (Porta 80)](#configurazione-apache-http-porta-80)
4. [Configurazione Apache HTTPS (Porta 443)](#configurazione-apache-https-porta-443)
5. [Verifica e Testing](#verifica-e-testing)
6. [Troubleshooting](#troubleshooting)

---

## Modifiche Docker Compose

### File: `develop.compose.yml`

Aggiungere la variabile d'ambiente `CONSOLE_BASE_HREF` al servizio MinIO:

```yaml
minio:
  image: "minio/minio:latest"
  container_name: "minio-${APP_NAME}"
  restart: always
  environment:
    MINIO_ROOT_USER: ${MINIO_ROOT_USER:-laravel}
    MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD:-laravelminio}
    CONSOLE_BASE_HREF: "/minio"  # ← AGGIUNTO
  command: 'minio server /data/minio --console-address ":8900"'
  platform: linux/amd64
  ports:
    - "${FORWARD_MINIO_PORT:-9000}:9000"
    - "${FORWARD_MINIO_CONSOLE_PORT:-8900}:8900"
  volumes:
    - "./docker/volumes/minio/data:/data/minio"
  healthcheck:
    test:
      - CMD
      - mc
      - ready
      - local
    retries: 3
    timeout: 5s
```

**Nota**: Assicurarsi che le porte siano mappate correttamente:
- Porta 9000 (API) → Host: `9002` (configurazione attuale)
- Porta 8900 (Console) → Host: `9003` (configurazione attuale)

---

## Moduli Apache Necessari

Abilitare i seguenti moduli Apache:

```bash
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod proxy_wstunnel
sudo a2enmod rewrite
sudo a2enmod substitute
sudo a2enmod headers

sudo systemctl restart apache2
```

**Moduli richiesti**:
- `proxy` - Per il reverse proxy
- `proxy_http` - Per proxy HTTP
- `proxy_wstunnel` - Per supporto WebSocket
- `rewrite` - Per riscrittura URL e gestione WebSocket
- `substitute` - Per modificare i path relativi nell'HTML
- `headers` - Per gestire header HTTP e CORS

---

## Configurazione Apache HTTP (Porta 80)

### File: `/etc/apache2/sites-available/maphub.it.conf`

Aggiungere il proxy per il bucket MinIO `/wmfe/`:

```apache
<VirtualHost *:80>
    ServerName www.maphub.it
    
    # ... altre configurazioni ...
    
    # Proxy per MinIO bucket wmfe con CORS headers
    ProxyPreserveHost On
    ProxyPass /wmfe/ http://localhost:9002/wmfe/
    ProxyPassReverse /wmfe/ http://localhost:9002/wmfe/
    
    # Aggiungi CORS headers per le richieste proxyate a MinIO
    <LocationMatch "^/wmfe/">
        Header always set Access-Control-Allow-Origin "*"
        Header always set Access-Control-Allow-Methods "*"
        Header always set Access-Control-Allow-Headers "*"
        Header always set Access-Control-Allow-Credentials "true"
        Header always set Access-Control-Expose-Headers "*"
    </LocationMatch>
    
    # Redirect HTTP a HTTPS
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =www.maphub.it
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

---

## Configurazione Apache HTTPS (Porta 443)

### File: `/etc/apache2/sites-available/maphub.it-le-ssl.conf`

#### 1. Proxy per Bucket MinIO `/wmfe/`

```apache
# Proxy per MinIO bucket wmfe con CORS headers
ProxyPreserveHost On
ProxyPass /wmfe/ http://localhost:9002/wmfe/
ProxyPassReverse /wmfe/ http://localhost:9002/wmfe/

# Aggiungi CORS headers per le richieste proxyate a MinIO
<LocationMatch "^/wmfe/">
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
    Header always set Access-Control-Expose-Headers "*"
</LocationMatch>
```

#### 2. Redirect `/minio` a `/minio/`

```apache
# Redirect /minio a /minio/ per gestire entrambi i casi
RewriteEngine On
RewriteRule ^/minio$ /minio/ [R=301,L]
```

#### 3. Supporto WebSocket per MinIO Console

**IMPORTANTE**: Queste regole devono essere PRIMA del proxy HTTP normale.

```apache
# WebSocket support per MinIO Console (DEVE essere prima del proxy normale)
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/minio/(.*) ws://localhost:9003/$1 [P,L]

# Supporto anche per richieste API WebSocket
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/minio/api/(.*) ws://localhost:9003/api/$1 [P,L]
```

#### 4. Proxy HTTP per MinIO Console

```apache
# Proxy HTTP normale per MinIO Console
ProxyPass /minio/ http://localhost:9003/
ProxyPassReverse /minio/ http://localhost:9003/
```

#### 5. Configurazione Header e CORS per MinIO Console

```apache
# Configurazioni specifiche per MinIO Console
<LocationMatch "^/minio">
    # Rimuovi header che potrebbero interferire
    Header unset X-Frame-Options
    Header unset X-Content-Type-Options
    
    # Permetti iframe embedding
    Header always set X-Frame-Options "SAMEORIGIN"
    
    # CORS headers per la console
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
</LocationMatch>
```

#### 6. Correzione Path Relativi nell'HTML

**IMPORTANTE**: Questa sezione applica le sostituzioni SOLO all'HTML principale, NON ai file JavaScript/CSS statici per evitare errori di chunked encoding.

```apache
# Riscrivi solo l'HTML principale (index) per correggere i path relativi
<LocationMatch "^/minio/?$">
    AddOutputFilterByType SUBSTITUTE text/html
    # Corregge base href
    Substitute "s|<base href=\"/\"/>|<base href=\"/minio/\"/>|i"
    # Corregge path relativi che iniziano con ./
    Substitute "s|href=\"\\./|href=\"/minio/|i"
    Substitute "s|src=\"\\./|src=\"/minio/|i"
</LocationMatch>
```

### Configurazione Completa HTTPS

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName www.maphub.it
    
    # ... altre configurazioni ...
    
    # Proxy per MinIO bucket wmfe con CORS headers
    ProxyPreserveHost On
    ProxyPass /wmfe/ http://localhost:9002/wmfe/
    ProxyPassReverse /wmfe/ http://localhost:9002/wmfe/
    
    # Aggiungi CORS headers per le richieste proxyate a MinIO
    <LocationMatch "^/wmfe/">
        Header always set Access-Control-Allow-Origin "*"
        Header always set Access-Control-Allow-Methods "*"
        Header always set Access-Control-Allow-Headers "*"
        Header always set Access-Control-Allow-Credentials "true"
        Header always set Access-Control-Expose-Headers "*"
    </LocationMatch>
    
    # Redirect /minio a /minio/ per gestire entrambi i casi
    RewriteEngine On
    RewriteRule ^/minio$ /minio/ [R=301,L]
    
    # Proxy per MinIO Console con supporto WebSocket
    ProxyPreserveHost On
    
    # WebSocket support per MinIO Console (DEVE essere prima del proxy normale)
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteCond %{HTTP:Connection} =upgrade [NC]
    RewriteRule ^/minio/(.*) ws://localhost:9003/$1 [P,L]
    
    # Supporto anche per richieste API WebSocket
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteCond %{HTTP:Connection} =upgrade [NC]
    RewriteRule ^/minio/api/(.*) ws://localhost:9003/api/$1 [P,L]
    
    # Proxy HTTP normale per MinIO Console
    ProxyPass /minio/ http://localhost:9003/
    ProxyPassReverse /minio/ http://localhost:9003/
    
    # Configurazioni specifiche per MinIO Console
    <LocationMatch "^/minio">
        # Rimuovi header che potrebbero interferire
        Header unset X-Frame-Options
        Header unset X-Content-Type-Options
        
        # Permetti iframe embedding
        Header always set X-Frame-Options "SAMEORIGIN"
        
        # CORS headers per la console
        Header always set Access-Control-Allow-Origin "*"
        Header always set Access-Control-Allow-Methods "*"
        Header always set Access-Control-Allow-Headers "*"
        Header always set Access-Control-Allow-Credentials "true"
    </LocationMatch>
    
    # Riscrivi solo l'HTML principale (index) per correggere i path relativi
    <LocationMatch "^/minio/?$">
        AddOutputFilterByType SUBSTITUTE text/html
        # Corregge base href
        Substitute "s|<base href=\"/\"/>|<base href=\"/minio/\"/>|i"
        # Corregge path relativi che iniziano con ./
        Substitute "s|href=\"\\./|href=\"/minio/|i"
        Substitute "s|src=\"\\./|src=\"/minio/|i"
    </LocationMatch>
    
    # ... configurazione SSL ...
</VirtualHost>
</IfModule>
```

---

## Verifica e Testing

### 1. Verificare la sintassi Apache

```bash
sudo apache2ctl configtest
```

### 2. Riavviare Apache

```bash
sudo systemctl reload apache2
# oppure
sudo systemctl restart apache2
```

### 3. Verificare che MinIO sia in esecuzione

```bash
docker ps | grep minio
```

### 4. Testare l'accesso diretto a MinIO (dall'host)

```bash
# Test API MinIO
curl -I http://localhost:9002/

# Test Console MinIO
curl -I http://localhost:9003/
```

### 5. Testare l'accesso tramite proxy

```bash
# Test bucket wmfe
curl -I https://www.maphub.it/wmfe/

# Test console
curl -I https://www.maphub.it/minio/
```

### 6. Verificare nel browser

Aprire nel browser:
- **Console MinIO**: `https://www.maphub.it/minio/`
- **Bucket wmfe**: `https://www.maphub.it/wmfe/`

Controllare la console del browser (F12) per eventuali errori.

---

## Troubleshooting

### Problema: File statici non caricati (404)

**Sintomi**: Errori 404 per file CSS/JS come `/static/js/main.xxx.js`

**Causa**: I path relativi nell'HTML non vengono corretti.

**Soluzione**: Verificare che le sostituzioni nell'HTML siano attive:
```apache
<LocationMatch "^/minio/?$">
    AddOutputFilterByType SUBSTITUTE text/html
    Substitute "s|href=\"\\./|href=\"/minio/|i"
    Substitute "s|src=\"\\./|src=\"/minio/|i"
</LocationMatch>
```

### Problema: ERR_INCOMPLETE_CHUNKED_ENCODING

**Sintomi**: File JavaScript non caricati correttamente, errore di chunked encoding.

**Causa**: Le sostituzioni vengono applicate anche ai file JavaScript binari.

**Soluzione**: Assicurarsi che le sostituzioni siano applicate SOLO all'HTML:
```apache
# CORRETTO: solo text/html
AddOutputFilterByType SUBSTITUTE text/html

# SBAGLIATO: non includere application/javascript o text/javascript
# AddOutputFilterByType SUBSTITUTE application/javascript
```

### Problema: WebSocket non funziona

**Sintomi**: Console MinIO si blocca o mostra errori di connessione WebSocket.

**Causa**: Le regole WebSocket non sono prima del proxy HTTP normale.

**Soluzione**: Assicurarsi che le regole `RewriteRule` per WebSocket siano PRIMA di `ProxyPass`:
```apache
# CORRETTO: WebSocket PRIMA
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/minio/(.*) ws://localhost:9003/$1 [P,L]

# Poi il proxy HTTP normale
ProxyPass /minio/ http://localhost:9003/
```

### Problema: Errore 500 Internal Server Error

**Sintomi**: Errore 500 quando si accede a `/minio/`

**Causa**: Moduli Apache non abilitati o configurazione errata.

**Soluzione**: 
1. Verificare i moduli: `apache2ctl -M | grep proxy`
2. Verificare la sintassi: `apache2ctl configtest`
3. Controllare i log: `tail -f /var/log/apache2/error.log`

### Problema: Console MinIO in loop di caricamento

**Sintomi**: La console mostra solo il loading spinner.

**Causa**: Le richieste API o WebSocket non funzionano.

**Soluzione**:
1. Verificare che le porte siano corrette (9003 per console)
2. Verificare che i WebSocket siano configurati correttamente
3. Controllare la console del browser per errori specifici

### Problema: CORS errors

**Sintomi**: Errori CORS nella console del browser.

**Causa**: Header CORS non configurati correttamente.

**Soluzione**: Verificare che i header CORS siano presenti:
```apache
<LocationMatch "^/minio">
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
</LocationMatch>
```

---

## Riepilogo Configurazioni

### Porte MinIO

- **API MinIO**: `localhost:9002` (porta host) → `9000` (porta container)
- **Console MinIO**: `localhost:9003` (porta host) → `8900` (porta container)

### URL Pubblici

- **Console MinIO**: `https://www.maphub.it/minio/`
- **Bucket wmfe**: `https://www.maphub.it/wmfe/`

### Variabili d'Ambiente MinIO

- `MINIO_ROOT_USER`: Credenziali utente root
- `MINIO_ROOT_PASSWORD`: Password root
- `CONSOLE_BASE_HREF`: `/minio` (base path per la console)

### Moduli Apache Abilitati

- `proxy`
- `proxy_http`
- `proxy_wstunnel`
- `rewrite`
- `substitute`
- `headers`

---

## Note Importanti

1. **Ordine delle regole**: Le regole WebSocket devono essere PRIMA del proxy HTTP normale.

2. **Sostituzioni**: Applicare le sostituzioni SOLO all'HTML, mai ai file JavaScript/CSS binari per evitare corruzione.

3. **Porte**: Verificare che le porte mappate nel docker-compose corrispondano a quelle usate nella configurazione Apache.

4. **Riavvio**: Dopo modifiche a Docker Compose, riavviare il container MinIO:
   ```bash
   docker compose -f develop.compose.yml restart minio
   ```

5. **Log**: In caso di problemi, controllare:
   - Log Apache: `/var/log/apache2/error.log`
   - Log Apache access: `/var/log/apache2/www.maphub.it-access.log` (nome dipende da `CustomLog` nel vhost)
   - Log MinIO: `docker logs minio-${APP_NAME}`

---

## Configurazione Laravel per MinIO

Per utilizzare MinIO come storage S3-compatible in Laravel, assicurarsi che nel file `.env` siano configurate:

```env
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_ENDPOINT=http://localhost:9002
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_BUCKET=wmfe
```

E nel file `config/filesystems.php` o `wm-package/config/wm-filesystems.php`:

```php
'wmfe' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => 'wmfe',
    'url' => env('AWS_WMFE_URL', env('AWS_URL')),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', true), // IMPORTANTE per MinIO
],
```

---

**Data creazione**: 2025-01-11  
**Ultima modifica**: 2025-01-11



