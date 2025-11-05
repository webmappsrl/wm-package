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
    - ELASTICSEARCH_USERNAME=kibana_system
    - ELASTICSEARCH_PASSWORD=${ELASTICSEARCH_PASSWORD:-changeme}
    - xpack.security.enabled=true
    - xpack.security.http.ssl.enabled=false
    - SERVER_NAME=kibana
    - SERVER_HOST=0.0.0.0
    - SERVER_BASEPATH=/kibana
  ports:
    - ${DOCKER_KIBANA_PORT:-5601}:5601
```

**Nota**: 
- La porta di default è `5601` (configurabile tramite `DOCKER_KIBANA_PORT`)
- Il base path è sempre `/kibana` (fisso, non configurabile)
- La password per Elasticsearch è configurabile tramite `ELASTICSEARCH_PASSWORD` nel file `.env`
- La security è abilitata di default (richiesta per vedere gli indici in Kibana)

---

## Moduli Apache Necessari

I moduli Apache necessari sono gli stessi già utilizzati per MinIO:

```bash
sudo a2enmod proxy
sudo a2enmod proxy_http
sudo a2enmod rewrite
sudo a2enmod headers

sudo systemctl restart apache2
```

**Moduli richiesti**:
- `proxy` - Per il reverse proxy
- `proxy_http` - Per proxy HTTP
- `rewrite` - Per riscrittura URL
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
    ProxyPass /kibana/ http://localhost:5601/
    ProxyPassReverse /kibana/ http://localhost:5601/
    
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

#### 2. Proxy HTTP per Kibana

```apache
# Proxy per Kibana
ProxyPreserveHost On
ProxyPass /kibana/ http://localhost:5601/
ProxyPassReverse /kibana/ http://localhost:5601/
```

#### 3. Configurazione Header e CORS per Kibana

```apache
# Configurazioni specifiche per Kibana
<LocationMatch "^/kibana">
    # Rimuovi header che potrebbero interferire
    Header unset X-Frame-Options
    Header unset X-Content-Type-Options
    
    # Permetti iframe embedding
    Header always set X-Frame-Options "SAMEORIGIN"
    
    # CORS headers per Kibana
    Header always set Access-Control-Allow-Origin "*"
    Header always set Access-Control-Allow-Methods "*"
    Header always set Access-Control-Allow-Headers "*"
    Header always set Access-Control-Allow-Credentials "true"
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
    
    # Proxy per Kibana
    ProxyPreserveHost On
    ProxyPass /kibana/ http://localhost:5601/
    ProxyPassReverse /kibana/ http://localhost:5601/
    
    # Configurazioni specifiche per Kibana
    <LocationMatch "^/kibana">
        # Rimuovi header che potrebbero interferire
        Header unset X-Frame-Options
        Header unset X-Content-Type-Options
        
        # Permetti iframe embedding
        Header always set X-Frame-Options "SAMEORIGIN"
        
        # CORS headers per Kibana
        Header always set Access-Control-Allow-Origin "*"
        Header always set Access-Control-Allow-Methods "*"
        Header always set Access-Control-Allow-Headers "*"
        Header always set Access-Control-Allow-Credentials "true"
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
- SERVER_BASEPATH=/kibana
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
3. Verificare che il ProxyPass punti a `http://localhost:5601/` (senza `/kibana` nel target, perché Kibana gestisce il base path internamente)

---

## Riepilogo Configurazioni

### Porte Kibana

- **Kibana**: `localhost:5601` (porta host) → `5601` (porta container)

### URL Pubblici

- **Kibana**: `https://camminiditalia.dev.maphub.it/kibana/`

### Variabili d'Ambiente Kibana

- `ELASTICSEARCH_HOSTS`: `http://elasticsearch:9200` (URL interno Elasticsearch)
- `ELASTICSEARCH_USERNAME`: `kibana_system` (utente per connettersi a Elasticsearch)
- `ELASTICSEARCH_PASSWORD`: `${ELASTICSEARCH_PASSWORD:-changeme}` (password da file `.env`)
- `SERVER_BASEPATH`: `/kibana` (base path per l'accesso pubblico)
- `xpack.security.enabled`: `true` (security abilitata - richiesta per vedere gli indici)
- `SERVER_HOST`: `0.0.0.0` (ascolta su tutte le interfacce)

### Credenziali di Accesso

**Per accedere a Kibana via browser:**
- URL: `https://camminiditalia.dev.maphub.it/kibana/`
- Username: `elastic`
- Password: valore di `ELASTICSEARCH_PASSWORD` nel file `.env` (default: `changeme`)

**Nota**: La password di `elastic` e `kibana_system` in Elasticsearch viene impostata automaticamente usando `ELASTICSEARCH_PASSWORD` dal file `.env`.

### Moduli Apache Abilitati

- `proxy`
- `proxy_http`
- `rewrite`
- `headers`

---

## Note Importanti

1. **Ordine delle configurazioni**: La configurazione Kibana deve essere PRIMA della configurazione SSL (come MinIO).

2. **BasePath**: Il `SERVER_BASEPATH` in Kibana deve corrispondere al path configurato in Apache (`/kibana`). Il ProxyPass deve puntare a `http://localhost:5601/` (senza `/kibana` nel target) perché Kibana gestisce il base path internamente.

3. **Porte**: Verificare che la porta mappata nel docker-compose (`DOCKER_KIBANA_PORT`) corrisponda a quella usata nella configurazione Apache (default: `5601`).

4. **Riavvio**: Dopo modifiche a Docker Compose, riavviare il container Kibana:
   ```bash
   docker compose restart kibana
   ```

5. **Log**: In caso di problemi, controllare:
   - Log Apache: `/var/log/apache2/error.log`
   - Log Apache access: `/var/log/apache2/camminiditalia.dev.access.log`
   - Log Kibana: `docker logs kibana-${APP_NAME}`

6. **Security**: La sicurezza è abilitata di default in Elasticsearch e Kibana. Per cambiare la password:
   - Modificare `ELASTICSEARCH_PASSWORD` nel file `.env`
   - Aggiornare le password in Elasticsearch:
     ```bash
     # Password per utente elastic
     docker exec elasticsearch-${APP_NAME} curl -X POST -u elastic:vecchia_password \
       "http://localhost:9200/_security/user/elastic/_password" \
       -H "Content-Type: application/json" -d '{"password":"nuova_password"}'
     
     # Password per utente kibana_system
     docker exec elasticsearch-${APP_NAME} curl -X POST -u elastic:nuova_password \
       "http://localhost:9200/_security/user/kibana_system/_password" \
       -H "Content-Type: application/json" -d '{"password":"nuova_password"}'
     ```
   - Riavviare Kibana: `docker compose restart kibana`
   - Aggiornare la cache di configurazione Laravel: `php artisan config:clear && php artisan config:cache`

---

**Data creazione**: 2025-11-05  
**Ultima modifica**: 2025-11-05

## Configurazione Password Elasticsearch

### Variabile d'Ambiente

La password per Elasticsearch è configurabile tramite la variabile `ELASTICSEARCH_PASSWORD` nel file `.env`:

```env
ELASTICSEARCH_PASSWORD=webmapp123
```

### Password Minime

**Importante**: Elasticsearch richiede password di almeno 6 caratteri.

### Aggiornamento Password

Se si cambia `ELASTICSEARCH_PASSWORD` nel `.env`, è necessario:

1. Aggiornare la password dell'utente `elastic` in Elasticsearch
2. Aggiornare la password dell'utente `kibana_system` in Elasticsearch
3. Riavviare Kibana
4. Aggiornare la cache di configurazione Laravel

Esempio completo:
```bash
# 1. Modificare .env (es. ELASTICSEARCH_PASSWORD=nuova_password)

# 2. Aggiornare password in Elasticsearch
docker exec elasticsearch-camminiditaliadev curl -X POST -u elastic:vecchia_password \
  "http://localhost:9200/_security/user/elastic/_password" \
  -H "Content-Type: application/json" -d '{"password":"nuova_password"}'

docker exec elasticsearch-camminiditaliadev curl -X POST -u elastic:nuova_password \
  "http://localhost:9200/_security/user/kibana_system/_password" \
  -H "Content-Type: application/json" -d '{"password":"nuova_password"}'

# 3. Riavviare Kibana
docker compose restart kibana

# 4. Aggiornare configurazione Laravel
docker exec php-camminiditaliadev php artisan config:clear
docker exec php-camminiditaliadev php artisan config:cache
```

### Configurazione Laravel Scout

Laravel Scout è configurato per usare `ELASTICSEARCH_USER` e `ELASTICSEARCH_PASSWORD` dal file `.env`:

```env
ELASTICSEARCH_USER=elastic
ELASTICSEARCH_PASSWORD=webmapp123
```

La configurazione è in `config/elasticsearch.php` e viene utilizzata automaticamente da Laravel Scout.

