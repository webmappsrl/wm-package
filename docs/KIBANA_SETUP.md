# Guida alla Configurazione Kibana con Apache Reverse Proxy

Questo documento descrive tutte le modifiche necessarie per configurare Kibana con accesso tramite Apache reverse proxy in produzione.

## Indice

1. [Modifiche Docker Compose](#modifiche-docker-compose)
2. [Moduli Apache Necessari](#moduli-apache-necessari)
3. [Configurazione Apache HTTP (Porta 80)](#configurazione-apache-http-porta-80)
4. [Configurazione Apache HTTPS (Porta 443)](#configurazione-apache-https-porta-443)
5. [Verifica e Testing](#verifica-e-testing)
6. [Troubleshooting](#troubleshooting)

---

## Modifiche Docker Compose

### File: `compose.yml`

La configurazione Kibana è già presente con la variabile `SERVER_BASEPATH`:

```yaml
kibana:
  image: docker.elastic.co/kibana/kibana:8.17.1
  container_name: "kibana-${APP_NAME}"
  restart: always
  depends_on:
    - elasticsearch
  environment:
    - ELASTICSEARCH_HOSTS=http://elasticsearch:9200
    - xpack.security.enabled=false
    - xpack.security.http.ssl.enabled=false
    - SERVER_NAME=kibana
    - SERVER_HOST=0.0.0.0
    - SERVER_BASEPATH=${KIBANA_BASEPATH:-/kibana}
  ports:
    - ${DOCKER_KIBANA_PORT:-5601}:5601
```

**Nota**: 
- La porta di default è `5601` (configurabile tramite `DOCKER_KIBANA_PORT`)
- Il base path di default è `/kibana` (configurabile tramite `KIBANA_BASEPATH` nel file `.env`)

---

## Moduli Apache Necessari

I moduli Apache necessari sono gli stessi già utilizzati per MinIO:

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
- `proxy_wstunnel` - Per supporto WebSocket (usato da Kibana per alcune funzionalità)
- `rewrite` - Per riscrittura URL
- `substitute` - Per modificare i path relativi nell'HTML
- `headers` - Per gestire header HTTP e CORS

---

## Configurazione Apache HTTP (Porta 80)

### File: `/etc/apache2/sites-available/camminiditalia.maphub.it.conf`

Aggiungere il proxy per Kibana:

```apache
<VirtualHost *:80>
    ServerName camminiditalia.dev.maphub.it
    
    # ... altre configurazioni ...
    
    # Proxy per Kibana
    ProxyPreserveHost On
    ProxyPass /kibana/ http://localhost:5601/kibana/
    ProxyPassReverse /kibana/ http://localhost:5601/kibana/
    
    # Redirect /kibana a /kibana/ per gestire entrambi i casi
    RewriteEngine On
    RewriteRule ^/kibana$ /kibana/ [R=301,L]
    
    # Redirect HTTP a HTTPS
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =camminiditalia.dev.maphub.it
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

---

## Configurazione Apache HTTPS (Porta 443)

### File: `/etc/apache2/sites-available/camminiditalia.maphub.it-le-ssl.conf`

#### 1. Redirect `/kibana` a `/kibana/`

```apache
# Redirect /kibana a /kibana/ per gestire entrambi i casi
RewriteEngine On
RewriteRule ^/kibana$ /kibana/ [R=301,L]
```

#### 2. Supporto WebSocket per Kibana

**IMPORTANTE**: Queste regole devono essere PRIMA del proxy HTTP normale.

```apache
# WebSocket support per Kibana (DEVE essere prima del proxy normale)
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/kibana/(.*) ws://localhost:5601/kibana/$1 [P,L]
```

#### 3. Proxy HTTP per Kibana

```apache
# Proxy HTTP normale per Kibana
ProxyPreserveHost On
ProxyPass /kibana/ http://localhost:5601/kibana/
ProxyPassReverse /kibana/ http://localhost:5601/kibana/
```

#### 4. Configurazione Header e CORS per Kibana

```apache
# Configurazioni specifiche per Kibana
<LocationMatch "^/kibana">
    # Header CORS
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
    
    # Permetti iframe embedding se necessario
    Header always set X-Frame-Options "SAMEORIGIN"
</LocationMatch>
```

### Configurazione Completa HTTPS

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName camminiditalia.dev.maphub.it
    
    # ... altre configurazioni ...
    
    # Redirect /kibana a /kibana/ per gestire entrambi i casi
    RewriteEngine On
    RewriteRule ^/kibana$ /kibana/ [R=301,L]
    
    # Proxy per Kibana con supporto WebSocket
    ProxyPreserveHost On
    
    # WebSocket support per Kibana (DEVE essere prima del proxy normale)
    RewriteCond %{HTTP:Upgrade} =websocket [NC]
    RewriteCond %{HTTP:Connection} =upgrade [NC]
    RewriteRule ^/kibana/(.*) ws://localhost:5601/kibana/$1 [P,L]
    
    # Proxy HTTP normale per Kibana
    ProxyPass /kibana/ http://localhost:5601/kibana/
    ProxyPassReverse /kibana/ http://localhost:5601/kibana/
    
    # Configurazioni specifiche per Kibana
    <LocationMatch "^/kibana">
        # Header CORS
        Header always set Access-Control-Allow-Origin "*"
        Header always set Access-Control-Allow-Methods "*"
        Header always set Access-Control-Allow-Headers "*"
        Header always set Access-Control-Allow-Credentials "true"
        
        # Permetti iframe embedding se necessario
        Header always set X-Frame-Options "SAMEORIGIN"
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

### 3. Verificare che Kibana sia in esecuzione

```bash
docker ps | grep kibana
```

### 4. Testare l'accesso diretto a Kibana (dall'host)

```bash
# Test Kibana
curl -I http://localhost:5601/kibana/
```

### 5. Testare l'accesso tramite proxy

```bash
# Test Kibana tramite proxy
curl -I https://camminiditalia.dev.maphub.it/kibana/
```

### 6. Verificare nel browser

Aprire nel browser:
- **Kibana**: `https://camminiditalia.dev.maphub.it/kibana/`

Controllare la console del browser (F12) per eventuali errori.

---

## Troubleshooting

### Problema: File statici non caricati (404)

**Sintomi**: Errori 404 per file CSS/JS come `/bundles/...`

**Causa**: Il basePath non è configurato correttamente.

**Soluzione**: Verificare che la variabile `SERVER_BASEPATH` sia impostata correttamente nel docker-compose:
```yaml
- SERVER_BASEPATH=${KIBANA_BASEPATH:-/kibana}
```

### Problema: WebSocket non funziona

**Sintomi**: Alcune funzionalità di Kibana si bloccano o mostrano errori.

**Causa**: Le regole WebSocket non sono configurate correttamente.

**Soluzione**: Assicurarsi che le regole WebSocket siano PRIMA di `ProxyPass`:
```apache
# CORRETTO: WebSocket PRIMA
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} =upgrade [NC]
RewriteRule ^/kibana/(.*) ws://localhost:5601/kibana/$1 [P,L]

# Poi il proxy HTTP normale
ProxyPass /kibana/ http://localhost:5601/kibana/
```

### Problema: Errore 500 Internal Server Error

**Sintomi**: Errore 500 quando si accede a `/kibana/`

**Causa**: Moduli Apache non abilitati o configurazione errata.

**Soluzione**: 
1. Verificare i moduli: `apache2ctl -M | grep proxy`
2. Verificare la sintassi: `apache2ctl configtest`
3. Controllare i log: `tail -f /var/log/apache2/error.log`

### Problema: Kibana non si connette a Elasticsearch

**Sintomi**: Kibana mostra errori di connessione a Elasticsearch.

**Causa**: Elasticsearch non è raggiungibile da Kibana.

**Soluzione**: 
1. Verificare che Elasticsearch sia in esecuzione: `docker ps | grep elasticsearch`
2. Verificare che i container siano nella stessa rete Docker
3. Controllare i log: `docker logs kibana-${APP_NAME}`

### Problema: CORS errors

**Sintomi**: Errori CORS nella console del browser.

**Causa**: Header CORS non configurati correttamente.

**Soluzione**: Verificare che i header CORS siano presenti:
```apache
<LocationMatch "^/kibana">
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
</LocationMatch>
```

### Problema: Redirect loop

**Sintomi**: Browser in loop di redirect.

**Causa**: Configurazione del basePath non corrisponde tra Kibana e Apache.

**Soluzione**: 
1. Verificare che `SERVER_BASEPATH` in Kibana corrisponda al path in Apache (`/kibana`)
2. Verificare che il path in Apache finisca con `/` sia in `ProxyPass` che in `RewriteRule`

---

## Riepilogo Configurazioni

### Porte Kibana

- **Kibana**: `localhost:5601` (porta host) → `5601` (porta container)

### URL Pubblici

- **Kibana**: `https://camminiditalia.dev.maphub.it/kibana/`

### Variabili d'Ambiente Kibana

- `ELASTICSEARCH_HOSTS`: `http://elasticsearch:9200` (URL interno Elasticsearch)
- `SERVER_BASEPATH`: `/kibana` (base path per l'accesso pubblico)
- `xpack.security.enabled`: `false` (security disabilitata per sviluppo)
- `SERVER_HOST`: `0.0.0.0` (ascolta su tutte le interfacce)

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

2. **BasePath**: Il `SERVER_BASEPATH` in Kibana deve corrispondere al path configurato in Apache.

3. **Porte**: Verificare che la porta mappata nel docker-compose (`DOCKER_KIBANA_PORT`) corrisponda a quella usata nella configurazione Apache (default: `5601`).

4. **Riavvio**: Dopo modifiche a Docker Compose, riavviare il container Kibana:
   ```bash
   docker compose restart kibana
   ```

5. **Log**: In caso di problemi, controllare:
   - Log Apache: `/var/log/apache2/error.log`
   - Log Apache access: `/var/log/apache2/camminiditalia.dev.access.log`
   - Log Kibana: `docker logs kibana-${APP_NAME}`

6. **Security**: In produzione, considerare di abilitare la sicurezza di Elasticsearch e Kibana:
   ```yaml
   - xpack.security.enabled=true
   - xpack.security.http.ssl.enabled=true
   ```

---

**Data creazione**: 2025-11-05  
**Ultima modifica**: 2025-11-05

