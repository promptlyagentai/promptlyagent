# Custom Domain Configuration for Laravel Sail

## Overview

By default, PromptlyAgent runs on `http://localhost` when using Laravel Sail. This works fine for local development, but if you need to access the application from:

- **Remote hosts** (other machines on your network)
- **Custom domains** (e.g., `app.example.com`, `dev.mycompany.com`)
- **Behind a reverse proxy** (Caddy, Nginx, Traefik)
- **With HTTPS** (SSL/TLS termination)

...then you need to configure custom domain support.

### Why This Is Needed

Laravel and Vite generate absolute URLs for assets, WebSocket connections, and API endpoints. When these are hardcoded to `localhost`, remote browsers receive URLs pointing to their own `localhost` instead of your actual server.

**Symptoms of misconfiguration:**
- Assets fail to load (CSS, JavaScript)
- WebSocket connections fail (real-time features don't work)
- Mixed content warnings (HTTP assets on HTTPS page)
- Vite HMR (hot reload) doesn't work remotely

### What Gets Fixed

This guide configures:

1. **APP_URL** - Laravel generates all URLs with your custom domain
2. **REVERB_HOST/PORT/SCHEME** - WebSocket server connection for real-time features
3. **VITE_REVERB_*** - Frontend JavaScript knows where to connect
4. **VITE_HMR_HOST** - Hot module replacement works from remote hosts
5. **Reverse proxy** - HTTPS termination and proper header forwarding

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│  Browser (Remote Host)                                          │
│  https://app.example.com                                        │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 │ HTTPS (443)
                 │
┌────────────────▼────────────────────────────────────────────────┐
│  Reverse Proxy (Caddy/Nginx/Traefik)                            │
│  - SSL/TLS Termination                                          │
│  - Forwards X-Forwarded-* headers                               │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 │ HTTP (80)
                 │
┌────────────────▼────────────────────────────────────────────────┐
│  Sail Container (laravel.test)                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │  Nginx (docker/nginx/laravel.conf)                       │   │
│  │  ┌──────────────┬──────────────────┬──────────────────┐  │   │
│  │  │ /ws/*        │ /@vite/, /vite/  │ Static + PHP     │  │   │
│  │  │ → Reverb     │ /resources/,     │ → PHP-FPM        │  │   │
│  │  │              │ /node_modules/   │                  │  │   │
│  │  │              │ → Vite Dev       │                  │  │   │
│  │  └──────────────┴──────────────────┴──────────────────┘  │   │
│  └──────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌────────────────┐  ┌────────────────┐  ┌──────────────────┐   │
│  │ PHP-FPM        │  │ Reverb (8080)  │  │ Vite Dev (5173)  │   │
│  │ Laravel App    │  │ WebSocket      │  │ Hot Reload       │   │
│  └────────────────┘  └────────────────┘  └──────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

**Key Point:** Your reverse proxy only needs to forward **port 80**. Sail's internal nginx handles routing to WebSocket, Vite HMR, and PHP-FPM.

---

## Request Flow Diagrams

Understanding how requests flow through the system helps debug configuration issues.

### Production Mode (Built Assets)

When using built assets (`npm run build`), requests follow this path:

```
Browser Request: https://app.example.com/
         │
         ▼
[Reverse Proxy] Port 443 (HTTPS)
         │ SSL Termination
         │ Adds X-Forwarded-* headers
         ▼
[Sail Nginx] Port 80 (HTTP)
         │
         ├─→ Static Assets (/build/*)
         │   └─→ Served directly from public/build/
         │
         ├─→ WebSocket (/ws/*)
         │   └─→ Proxied to Reverb container (reverb:8080)
         │
         └─→ PHP Routes (/)
             └─→ Passed to PHP-FPM → Laravel → Blade/Livewire
```

**Key Configuration for Production:**
- `APP_URL` - Laravel generates asset URLs
- `REVERB_HOST` / `REVERB_SCHEME` / `REVERB_PORT` - Backend WebSocket config
- `VITE_REVERB_*` - Frontend JavaScript (embedded at build time)

### Development Mode (Vite Dev Server)

When running `npm run dev`, Vite serves assets with hot reload:

```
Browser Request: https://app.example.com/
         │
         ▼
[Reverse Proxy] Port 443 (HTTPS)
         │ SSL Termination
         │ Adds X-Forwarded-* headers
         ▼
[Sail Nginx] Port 80 (HTTP)
         │
         ├─→ Vite Assets (/@vite/*, /resources/*)
         │   └─→ Proxied to Vite Dev (localhost:5173)
         │       │ HMR WebSocket: wss://app.example.com/@vite/client
         │       └─→ Assets: https://app.example.com/resources/css/app.css
         │
         ├─→ WebSocket (/ws/*)
         │   └─→ Proxied to Reverb container (reverb:8080)
         │
         └─→ PHP Routes (/)
             └─→ Passed to PHP-FPM → Laravel → Blade/Livewire
```

**Key Configuration for Development:**
- All production config PLUS:
- `VITE_HMR_HOST` - Domain for HMR WebSocket (passed via docker-compose.yml)
- `VITE_DEV_SERVER_URL` - Full URL for Vite assets (passed via docker-compose.yml)

---

## Configuration Deep Dive

### Environment Variables Explained

#### Base Configuration (.env)

```bash
# Master variables - set these first
APP_HOST=app.example.com
APP_PROTOCOL=https
```

These are the source of truth. All other URL-related variables derive from these.

#### Laravel Application URLs (.env)

```bash
# Used by: Laravel's url() helper, asset() helper, route() helper
APP_URL=${APP_PROTOCOL}://${APP_HOST}
```

**What it does:**
- Generates all URLs in Blade templates
- Used by `route()`, `url()`, `asset()` helpers
- Affects: links, form actions, redirects

**Test with:**
```bash
./vendor/bin/sail artisan tinker
>>> config('app.url')
```

#### WebSocket Backend Configuration (.env)

```bash
# Used by: Reverb server, Laravel broadcasting
REVERB_HOST=${APP_HOST}
REVERB_PORT=443                # 443 for HTTPS, 80 for HTTP
REVERB_SCHEME=${APP_PROTOCOL}  # https or http
```

**What it does:**
- Configures Reverb server's advertised hostname
- Used in `config/reverb.php` for app registration
- Backend Laravel code uses these when broadcasting events

**Test with:**
```bash
./vendor/bin/sail artisan tinker
>>> config('reverb.apps.apps')[0]['options']
```

#### WebSocket Frontend Configuration (.env)

```bash
# Used by: Laravel Echo client (browser JavaScript)
VITE_REVERB_HOST=${APP_HOST}
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=${APP_PROTOCOL}
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_APP_ID="${REVERB_APP_ID}"
```

**What it does:**
- Embedded in JavaScript at build time (`npm run build`)
- Browser's Laravel Echo client connects to this WebSocket URL
- Format: `wss://app.example.com/ws/`

**Test with:**
```bash
# After npm run build:
grep -o 'dev\.0tt\.me' public/build/assets/app-*.js
```

#### Vite Development Server (docker-compose.yml)

```yaml
# Passed to container automatically
VITE_HMR_HOST: '${REVERB_HOST}'
VITE_DEV_SERVER_URL: '${REVERB_SCHEME}://${REVERB_HOST}'
```

**What it does:**
- Only active during `npm run dev`
- `VITE_HMR_HOST` - WebSocket for hot module replacement
- `VITE_DEV_SERVER_URL` - Origin for serving dev assets

**Test with:**
```bash
./vendor/bin/sail exec laravel.test env | grep VITE_
```

### Configuration Flow

```
.env file
    │
    ├─→ APP_HOST, APP_PROTOCOL (user sets)
    │
    ├─→ APP_URL (derived)
    │   └─→ Laravel url() helper → HTML <a href>
    │
    ├─→ REVERB_* (derived)
    │   ├─→ Backend: config/reverb.php
    │   └─→ Frontend: VITE_REVERB_* → JavaScript (build time)
    │
    └─→ docker-compose.yml reads REVERB_*
        └─→ Passes VITE_HMR_HOST, VITE_DEV_SERVER_URL to container
            └─→ vite.config.js uses these → Vite dev server
```

### Why Both REVERB_* and VITE_REVERB_*?

**REVERB_* (Backend)**
- Used by PHP/Laravel code
- Configures Reverb server
- Runtime configuration

**VITE_REVERB_* (Frontend)**
- Used by JavaScript/browser code
- Embedded at build time (`npm run build`)
- Must rebuild assets after changing

**Key Insight:** Changing `REVERB_HOST` requires:
1. Restart Laravel/Reverb (for backend)
2. Rebuild assets: `npm run build` (for frontend)

---

## Quick Start (HTTPS via Reverse Proxy)

This is the most common setup: accessing Sail from a custom domain with HTTPS.

### Prerequisites

1. **Domain name** pointing to your server (e.g., `app.example.com`)
2. **Reverse proxy** installed (Caddy, Nginx, or Traefik)
3. **SSL certificate** (Let's Encrypt, Cloudflare, or custom)
4. **Sail running** on the same host (or network-accessible)

### Step 1: Configure Environment Variables

Edit your `.env` file using the **simplified pattern**:

```bash
# Base configuration
APP_HOST=app.example.com
APP_PROTOCOL=https

# Derived values
APP_URL=https://app.example.com

# WebSocket (backend)
REVERB_HOST=app.example.com
REVERB_PORT=443
REVERB_SCHEME=https

# Frontend (must match above)
VITE_REVERB_HOST=app.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_APP_ID="${REVERB_APP_ID}"

# Optional: Enable remote hot reload (if using npm run dev)
VITE_HMR_HOST=app.example.com
```

**Important:** Keep values consistent across `APP_URL`, `REVERB_*`, and `VITE_REVERB_*`.

### Step 2: Configure Reverse Proxy

Choose your reverse proxy and configure it to forward **all traffic** to Sail's port 80. Sail's internal nginx will handle routing.

**Replace `<sail-host>` with:**
- `localhost` - If reverse proxy runs on same host as Sail
- `host.docker.internal` - If reverse proxy runs in Docker on same host
- Actual IP/hostname - If on different machine (e.g., `192.168.1.100`)

See [Reverse Proxy Examples](#reverse-proxy-examples) below for complete configurations.

### Step 3: Rebuild Assets & Restart

```bash
# Clear Laravel config cache
./vendor/bin/sail artisan config:clear

# Rebuild frontend assets (embeds VITE_* variables at build time)
./vendor/bin/sail npm run build

# Restart Sail services
./vendor/bin/sail restart
```

### Step 4: Verify Configuration

```bash
# Check asset URLs point to your domain
curl https://app.example.com | grep 'href='
# Should show: https://app.example.com/build/assets/...

# Test WebSocket upgrade
curl -i -N \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Host: app.example.com" \
  https://app.example.com/ws/
# Should return: HTTP/1.1 101 Switching Protocols
```

**Browser Console Check:**
1. Open browser to `https://app.example.com`
2. Open Developer Tools → Network tab
3. Filter by "WS" (WebSocket)
4. Should see successful `wss://app.example.com/ws/` connection

---

## Reverse Proxy Examples

### Important Architecture Note

Sail's internal nginx (`docker/nginx/laravel.conf`) already handles:
- ✅ Static assets from `/var/www/html/public`
- ✅ PHP-FPM routing
- ✅ WebSocket proxying to Reverb container at `/ws/*`
- ✅ Vite HMR proxying to dev server at `/@vite/*` and `/vite/*`
- ✅ Proper timeouts and buffering for streaming

**Your external reverse proxy only needs to:**
- Forward **port 80** → All traffic to Sail
- Set proper `X-Forwarded-*` headers
- Handle SSL/TLS termination

That's it! No need to configure WebSocket or Vite separately.

---

### Caddy (Simplest - Automatic HTTPS)

**Recommended for:** Quick setup, automatic SSL certificates, minimal configuration.

**Caddyfile:**
```caddy
app.example.com {
    # All traffic to Sail nginx (handles everything)
    reverse_proxy <sail-host>:80
}
```

**Start Caddy:**
```bash
caddy run --config Caddyfile
```

**Automatic Features:**
- HTTPS with Let's Encrypt (automatic certificate management)
- HTTP to HTTPS redirect
- Proper header forwarding
- WebSocket upgrade support

---

### Nginx (Most Common)

**Recommended for:** Production deployments, fine-grained control, existing nginx infrastructure.

**Configuration:** `/etc/nginx/sites-available/app.example.com`

```nginx
# WebSocket connection upgrade mapping
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name app.example.com;

    # SSL configuration (Let's Encrypt, Cloudflare, or custom)
    ssl_certificate /etc/ssl/certs/app.example.com.pem;
    ssl_certificate_key /etc/ssl/private/app.example.com.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # All traffic to Sail nginx
    # Sail's internal nginx handles /ws/, /@vite/*, static assets, PHP routing
    location / {
        proxy_pass http://<sail-host>:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;

        # WebSocket support (for /ws/* and /@vite/* endpoints)
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;

        # Disable buffering for streaming responses
        proxy_buffering off;
    }
}

# HTTP to HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name app.example.com;
    return 301 https://$server_name$request_uri;
}
```

**Enable configuration:**
```bash
# Enable site
sudo ln -s /etc/nginx/sites-available/app.example.com /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Reload nginx
sudo systemctl reload nginx
```

**Let's Encrypt SSL (Certbot):**
```bash
sudo certbot --nginx -d app.example.com
```

---

### Traefik (Docker Labels)

**Recommended for:** Docker Swarm, Kubernetes, multiple services, service discovery.

**Method 1: Docker Compose Override (Recommended)**

Create `docker-compose.override.yml`:

```yaml
services:
  laravel.test:
    labels:
      # Enable Traefik
      - "traefik.enable=true"

      # Router configuration
      - "traefik.http.routers.promptlyagent.rule=Host(`app.example.com`)"
      - "traefik.http.routers.promptlyagent.entrypoints=websecure"
      - "traefik.http.routers.promptlyagent.tls=true"
      - "traefik.http.routers.promptlyagent.tls.certresolver=letsencrypt"

      # Service configuration (port 80 handles everything)
      - "traefik.http.services.promptlyagent.loadbalancer.server.port=80"

      # Connect to Traefik network
    networks:
      - traefik
      - sail

networks:
  traefik:
    external: true
```

**Method 2: Traefik Dynamic Configuration**

`config/traefik/dynamic/promptlyagent.yml`:

```yaml
http:
  routers:
    promptlyagent:
      rule: "Host(`app.example.com`)"
      entryPoints:
        - websecure
      service: promptlyagent
      tls:
        certResolver: letsencrypt

  services:
    promptlyagent:
      loadBalancer:
        servers:
          - url: "http://<sail-host>:80"
```

**Traefik Static Configuration** (`traefik.yml`):

```yaml
entryPoints:
  web:
    address: ":80"
    http:
      redirections:
        entryPoint:
          to: websecure
          scheme: https
  websecure:
    address: ":443"

certificatesResolvers:
  letsencrypt:
    acme:
      email: admin@example.com
      storage: /letsencrypt/acme.json
      httpChallenge:
        entryPoint: web
```

---

## HTTP Setup (Local Network Without SSL)

If you only need HTTP access (e.g., local network, development environment without SSL):

### Configuration

```bash
# .env configuration
APP_HOST=app.local
APP_PROTOCOL=http

APP_URL=http://app.local

REVERB_HOST=app.local
REVERB_PORT=80
REVERB_SCHEME=http

VITE_REVERB_HOST=app.local
VITE_REVERB_PORT=80
VITE_REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_APP_ID="${REVERB_APP_ID}"

# Optional: Remote hot reload
VITE_HMR_HOST=app.local
```

### Reverse Proxy (Optional)

If using a reverse proxy for HTTP:

**Caddy:**
```caddy
app.local {
    reverse_proxy <sail-host>:80
}
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name app.local;

    location / {
        proxy_pass http://<sail-host>:80;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;

        # WebSocket support
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
    }
}
```

### DNS Configuration

Add to `/etc/hosts` on client machines:

```
192.168.1.100  app.local
```

Replace `192.168.1.100` with your actual server IP.

---

## Diagnostic Commands

Use these commands to verify your configuration and diagnose issues.

### Check Environment Variables

```bash
# In your project directory
grep -E "APP_URL|APP_HOST|APP_PROTOCOL|REVERB_HOST|REVERB_SCHEME|VITE_REVERB" .env
```

**Expected output:**
```
APP_HOST=app.example.com
APP_PROTOCOL=https
APP_URL=https://app.example.com
REVERB_HOST=app.example.com
REVERB_SCHEME=https
VITE_REVERB_HOST=app.example.com
VITE_REVERB_SCHEME=https
```

### Check Container Environment

```bash
# Verify variables are passed to container
./vendor/bin/sail exec laravel.test env | grep -E "REVERB_HOST|REVERB_SCHEME|VITE_"
```

**Expected output:**
```
REVERB_HOST=app.example.com
REVERB_SCHEME=https
VITE_HMR_HOST=app.example.com
VITE_DEV_SERVER_URL=https://app.example.com
```

### Check Laravel Configuration

```bash
# Check APP_URL
./vendor/bin/sail artisan tinker --execute="echo config('app.url') . PHP_EOL;"

# Check Reverb configuration
./vendor/bin/sail artisan tinker --execute="print_r(config('reverb.apps.apps')[0]['options']);"
```

**Expected output:**
```
https://app.example.com

Array
(
    [host] => app.example.com
    [port] => 443
    [scheme] => https
    [useTLS] => 1
)
```

### Check Asset URLs (Production)

```bash
# Stop Vite dev server first
./vendor/bin/sail exec laravel.test pkill -f "npm run dev"

# Check HTML source
curl -s https://app.example.com | grep -o 'href="[^"]*build[^"]*"' | head -5
```

**Expected output:**
```
href="https://app.example.com/build/assets/app-abc123.css"
href="https://app.example.com/build/assets/app-def456.js"
```

### Check Asset URLs (Development)

```bash
# With Vite dev server running
curl -s https://app.example.com | grep -E '/@vite/|/resources/' | head -5
```

**Expected output:**
```
src="https://app.example.com/@vite/client"
href="https://app.example.com/resources/css/app.css"
src="https://app.example.com/resources/js/app.js"
```

### Check WebSocket Connection

```bash
# Test WebSocket upgrade
curl -i -N \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Host: app.example.com" \
  https://app.example.com/ws/
```

**Expected output:**
```
HTTP/1.1 101 Switching Protocols
Upgrade: websocket
Connection: Upgrade
```

### Check Reverb Server Status

```bash
# Check if Reverb is running
./vendor/bin/sail ps | grep reverb

# Check Reverb logs
./vendor/bin/sail logs reverb | tail -20
```

**Expected in logs:**
```
INFO  Starting server on 0.0.0.0:8080 (app.example.com).
```

### Check Nginx Configuration

```bash
# Check if Vite HMR proxy exists
./vendor/bin/sail exec laravel.test grep -A 5 "Proxy Vite HMR" /etc/nginx/sites-available/default

# Check if WebSocket proxy exists
./vendor/bin/sail exec laravel.test grep -A 5 "/ws/" /etc/nginx/sites-available/default
```

### Check Reverse Proxy Connectivity

```bash
# Test if reverse proxy can reach Sail
# From reverse proxy host:
curl -I http://<sail-host>:80

# Test WebSocket through reverse proxy
curl -I -H "Upgrade: websocket" https://app.example.com/ws/
```

---

## Troubleshooting

### Assets Still Loading from localhost

**Symptoms:**
- Browser shows `http://localhost/build/assets/app-xyz.js`
- CSS/JavaScript fail to load
- Console errors: "Failed to load resource"

**Root Cause:** `APP_URL` still set to `localhost` or assets not rebuilt after config change.

**Fix:**
```bash
# 1. Verify .env configuration
grep -E "APP_URL|REVERB_HOST|VITE_REVERB" .env

# 2. Update .env using the simplified pattern
APP_HOST=app.example.com
APP_PROTOCOL=https
APP_URL=https://app.example.com

REVERB_HOST=app.example.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_HOST=app.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# 3. Clear config cache
./vendor/bin/sail artisan config:clear

# 4. Rebuild assets (Vite variables are embedded at build time)
./vendor/bin/sail npm run build

# 5. Restart services
./vendor/bin/sail restart

# 6. Verify
curl https://app.example.com | grep 'href='
# Should show: https://app.example.com/build/assets/...
```

---

### WebSocket Connection Failed

**Symptoms:**
- Console error: "WebSocket connection to 'wss://app.example.com/ws/' failed"
- Real-time features don't work (chat, notifications)
- Network tab shows failed WS connection

**Root Cause:** `REVERB_HOST`, `REVERB_PORT`, or `REVERB_SCHEME` misconfigured, or reverse proxy doesn't support WebSocket upgrades.

**Fix:**

**1. Verify environment variables match:**
```bash
# All three must be consistent
grep REVERB .env
grep VITE_REVERB .env

# Example correct values for HTTPS:
REVERB_HOST=app.example.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_HOST=app.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

**2. Test WebSocket upgrade:**
```bash
curl -i -N \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Host: app.example.com" \
  https://app.example.com/ws/

# Expected: HTTP/1.1 101 Switching Protocols
# If you get 404 or 502, check reverse proxy configuration
```

**3. Verify reverse proxy supports WebSocket:**

For Nginx, ensure you have:
```nginx
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection $connection_upgrade;
```

For Caddy, WebSocket support is automatic.

For Traefik, WebSocket support is automatic.

**4. Rebuild assets and restart:**
```bash
./vendor/bin/sail npm run build
./vendor/bin/sail restart
```

**5. Check Reverb is running:**
```bash
./vendor/bin/sail ps

# Should show "reverb" container running
# If not: ./vendor/bin/sail up -d
```

---

### SSL Protocol Errors

**Symptoms:**
- Console error: "Mixed content: This page was loaded over HTTPS, but requested an insecure resource"
- Console error: "The page at 'https://...' was loaded over HTTPS, but attempted to connect to 'ws://...'"

**Root Cause:** `REVERB_SCHEME` set to `http` instead of `https`, or `VITE_REVERB_SCHEME` not matching.

**Fix:**
```bash
# Set all scheme values to https
REVERB_SCHEME=https
VITE_REVERB_SCHEME=https

# Rebuild and restart
./vendor/bin/sail npm run build
./vendor/bin/sail restart
```

**Verification:**
```bash
# Check compiled JavaScript for correct WebSocket URL
./vendor/bin/sail cat public/build/manifest.json
# Should reference wss:// (not ws://)
```

---

### Vite HMR Not Connecting

**Symptoms:**
- Hot reload doesn't work from remote host
- Console error: "WebSocket connection to 'ws://localhost:5173/' failed"
- Must manually refresh browser after code changes

**Root Cause:** `VITE_HMR_HOST` not set, or Vite dev server not running.

**Fix:**

**1. Set HMR host:**
```bash
# Add to .env
VITE_HMR_HOST=app.example.com
```

**2. Start Vite dev server:**
```bash
./vendor/bin/sail npm run dev
# Must keep running in separate terminal
```

**3. Verify Vite is listening:**
```bash
./vendor/bin/sail exec laravel.test curl http://localhost:5173
# Should return Vite dev server response
```

**4. Check reverse proxy forwards HMR:**

The Sail nginx configuration already proxies `/@vite/*` and `/vite/*` to localhost:5173. Verify your external reverse proxy forwards these paths.

**Note:** HMR is only for development. In production, use `npm run build` instead of `npm run dev`.

---

### Mixed Content Warnings

**Symptoms:**
- Console warning: "Mixed Content: The page at 'https://...' was loaded over HTTPS, but requested an insecure resource 'http://...'"
- Some assets load, others don't

**Root Cause:** Some URLs still reference `http://` instead of `https://`.

**Fix:**

**1. Verify all scheme values use https:**
```bash
grep -E "SCHEME|APP_URL" .env

# All should use https:
APP_URL=https://app.example.com
REVERB_SCHEME=https
VITE_REVERB_SCHEME=https
```

**2. Clear cache and rebuild:**
```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail npm run build
./vendor/bin/sail restart
```

**3. Verify X-Forwarded-Proto header:**

Laravel trusts `X-Forwarded-Proto` to detect HTTPS. Ensure your reverse proxy sets it:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

For Caddy and Traefik, this is automatic.

---

### Assets Load But Reverb Doesn't Connect

**Symptoms:**
- CSS and JavaScript load correctly
- WebSocket connection fails
- Console shows: "Failed to connect to Reverb"

**Root Cause:** `VITE_REVERB_*` variables don't match `REVERB_*` backend configuration.

**Fix:**

**1. Ensure values match exactly:**
```bash
# These MUST be identical
REVERB_HOST=app.example.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_HOST=app.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

**2. Rebuild assets (critical!):**

Frontend `VITE_*` variables are embedded at **build time**, not runtime.

```bash
./vendor/bin/sail npm run build
```

**3. Verify compiled JavaScript:**
```bash
# Check manifest for correct WebSocket URL
./vendor/bin/sail exec laravel.test cat public/build/manifest.json | grep reverb
```

**4. Test backend WebSocket directly:**
```bash
# Should work from browser console:
new WebSocket('wss://app.example.com/ws/')
```

---

### Reverse Proxy Returns 502 Bad Gateway

**Symptoms:**
- Reverse proxy error: "502 Bad Gateway"
- Nginx error log: "connect() failed (111: Connection refused)"

**Root Cause:** Reverse proxy can't reach Sail container.

**Fix:**

**1. Verify Sail is running:**
```bash
./vendor/bin/sail ps
# All services should show "Up"

# If not:
./vendor/bin/sail up -d
```

**2. Test connectivity from reverse proxy:**
```bash
# If reverse proxy is on same host:
curl http://localhost:80

# If using host.docker.internal:
docker run --rm curlimages/curl curl http://host.docker.internal:80

# If using actual IP:
curl http://192.168.1.100:80
```

**3. Check Docker networks:**

If reverse proxy runs in Docker, ensure it can reach Sail:

```bash
# List networks
docker network ls

# Inspect Sail network
docker network inspect promptlyagent_sail

# Connect reverse proxy to Sail network
docker network connect promptlyagent_sail <proxy-container>
```

**4. Check firewall rules:**

If on different machines:
```bash
# Test from reverse proxy host
telnet 192.168.1.100 80

# If connection refused, check firewall
sudo ufw status
sudo ufw allow 80/tcp
```

---

### Vite Dev Server Shows localhost URLs

**Symptoms:**
- When running `npm run dev`, assets load from `http://localhost:5173`
- Browser shows mixed content errors on HTTPS page
- HMR (hot reload) doesn't work from remote hosts

**Root Cause:** `VITE_HMR_HOST` and `VITE_DEV_SERVER_URL` not passed to container, or Vite config not using them.

**Fix:**

**1. Verify docker-compose.yml has pass-through:**
```bash
grep -A 2 "VITE_HMR_HOST" docker-compose.yml
```

**Expected:**
```yaml
VITE_HMR_HOST: '${REVERB_HOST}'
VITE_DEV_SERVER_URL: '${REVERB_SCHEME}://${REVERB_HOST}'
```

**2. Restart Sail to apply docker-compose.yml changes:**
```bash
./vendor/bin/sail down
./vendor/bin/sail up -d
```

**3. Verify variables are in container:**
```bash
./vendor/bin/sail exec laravel.test env | grep VITE_
```

**Expected output:**
```
VITE_HMR_HOST=app.example.com
VITE_DEV_SERVER_URL=https://app.example.com
```

**4. Restart Vite dev server:**
```bash
./vendor/bin/sail exec laravel.test pkill -f "vite"
./vendor/bin/sail npm run dev
```

**5. Verify URLs:**
```bash
curl -s https://app.example.com | grep '@vite/client'
# Should show: https://app.example.com/@vite/client
```

---

### Configuration Changes Not Taking Effect

**Symptoms:**
- Changed `.env` but still see old values
- Rebuilt assets but URLs still wrong
- Restarted Sail but configuration unchanged

**Root Cause:** Multiple caches need clearing, or configuration not reloaded properly.

**Fix:**

**Complete reload procedure:**
```bash
# 1. Stop everything
./vendor/bin/sail down

# 2. Clear all caches on host
rm -rf bootstrap/cache/*.php
rm -rf storage/framework/cache/*
rm -rf storage/framework/views/*

# 3. Start fresh
./vendor/bin/sail up -d

# 4. Clear Laravel caches
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan view:clear
./vendor/bin/sail artisan route:clear

# 5. Rebuild frontend
./vendor/bin/sail npm run build

# 6. Verify
./vendor/bin/sail artisan tinker --execute="echo config('app.url') . PHP_EOL;"
curl -s https://app.example.com | grep -o 'href="[^"]*build[^"]*"' | head -3
```

**Understanding cache layers:**
- **Laravel config cache** - `config:clear` (runtime PHP)
- **Laravel view cache** - `view:clear` (compiled Blade)
- **Frontend build** - `npm run build` (compiled JavaScript/CSS)
- **Browser cache** - Hard refresh (Ctrl+Shift+R)

---

### Environment Variables Not in Container

**Symptoms:**
- Variables work on host but not in container
- `env | grep VARIABLE` shows nothing in container
- Application uses default values instead of .env values

**Root Cause:** Variables not defined in `docker-compose.yml` environment section, or Sail not restarted after adding them.

**Fix:**

**1. Check if variable is in docker-compose.yml:**
```bash
grep "VARIABLE_NAME" docker-compose.yml
```

**2. Add variable to docker-compose.yml if missing:**
```yaml
services:
  laravel.test:
    environment:
      YOUR_VARIABLE: '${YOUR_VARIABLE}'
```

**3. Restart Sail (required for docker-compose.yml changes):**
```bash
./vendor/bin/sail down
./vendor/bin/sail up -d
```

**4. Verify variable is passed:**
```bash
./vendor/bin/sail exec laravel.test env | grep YOUR_VARIABLE
```

**Note:** Adding variables to `.env` alone is NOT enough for container access. They must also be in `docker-compose.yml`.

---

### Assets Work But HMR Doesn't

**Symptoms:**
- Production assets (`npm run build`) work correctly
- Dev server (`npm run dev`) assets load from custom domain
- But hot reload doesn't work - must manually refresh after changes

**Root Cause:** HMR WebSocket connection failing, or Vite HMR not properly configured.

**Fix:**

**1. Check Vite dev server is running:**
```bash
./vendor/bin/sail exec laravel.test ps aux | grep vite
```

**2. Check HMR WebSocket in browser console:**
```
Open DevTools → Console
Look for: "WebSocket connection to 'wss://app.example.com/@vite/client' failed"
```

**3. Verify Vite HMR configuration:**
```bash
# Check vite.config.js has HMR host set
cat vite.config.js | grep -A 5 "hmr:"
```

**Expected:**
```javascript
hmr: {
    host: process.env.VITE_HMR_HOST || 'localhost',
},
```

**4. Verify VITE_HMR_HOST is set:**
```bash
./vendor/bin/sail exec laravel.test env | grep VITE_HMR_HOST
# Should show: VITE_HMR_HOST=app.example.com
```

**5. Check Sail nginx has Vite HMR proxy:**
```bash
./vendor/bin/sail exec laravel.test grep -A 3 "/@vite" /etc/nginx/sites-available/default
```

**6. If nginx doesn't have proxy, rebuild Docker image:**
```bash
./vendor/bin/sail build --no-cache laravel.test
./vendor/bin/sail up -d
```

---

### Container Can't Resolve Custom Domain

**Symptoms:**
- Browser can access `https://app.example.com`
- But inside container: `curl https://app.example.com` fails
- PHP/Laravel can't make requests to own domain

**Root Cause:** Container's DNS doesn't resolve custom domain, or trying to connect through reverse proxy from inside container.

**Fix:**

**Option 1: Don't use custom domain internally**

Laravel inside container should use internal URLs:
```bash
# Use localhost for internal requests
curl http://localhost
# Not: curl https://app.example.com
```

**Option 2: Add to container's /etc/hosts** (if really needed)

```yaml
# docker-compose.yml
services:
  laravel.test:
    extra_hosts:
      - "app.example.com:127.0.0.1"
```

Then restart:
```bash
./vendor/bin/sail down
./vendor/bin/sail up -d
```

**Best Practice:** Use environment-specific URLs:
- Browser → `APP_URL` (https://app.example.com)
- Internal → `APP_INTERNAL_URL` (http://localhost or http://laravel.test)

---

### Vite Build Works But Dev Server Doesn't

**Symptoms:**
- `npm run build` creates assets with correct URLs
- But `npm run dev` shows localhost URLs
- HMR doesn't connect

**Root Cause:** Vite config using wrong environment variables, or variables not passed to container.

**Fix:**

**1. Check docker-compose.yml has Vite variables:**
```bash
grep -B 2 -A 2 "VITE_" docker-compose.yml
```

**Must have:**
```yaml
VITE_HMR_HOST: '${REVERB_HOST}'
VITE_DEV_SERVER_URL: '${REVERB_SCHEME}://${REVERB_HOST}'
```

**2. Check vite.config.js uses these variables:**
```javascript
server: {
    host: '0.0.0.0',
    origin: process.env.VITE_DEV_SERVER_URL || undefined,
    hmr: {
        host: process.env.VITE_HMR_HOST || 'localhost',
    },
},
```

**3. Restart everything:**
```bash
./vendor/bin/sail down
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev
```

**4. Test:**
```bash
curl -s https://app.example.com | grep '@vite'
# Should show: https://app.example.com/@vite/client
```

---

## Verification Checklist

Use this checklist to verify your configuration is correct:

### Environment Variables
- [ ] `APP_URL` uses your custom domain (e.g., `https://app.example.com`)
- [ ] `REVERB_HOST` matches your domain (no protocol)
- [ ] `REVERB_PORT` is 443 for HTTPS or 80 for HTTP
- [ ] `REVERB_SCHEME` is `https` or `http`
- [ ] `VITE_REVERB_HOST` matches `REVERB_HOST`
- [ ] `VITE_REVERB_PORT` matches `REVERB_PORT`
- [ ] `VITE_REVERB_SCHEME` matches `REVERB_SCHEME`
- [ ] `VITE_REVERB_APP_KEY` and `VITE_REVERB_APP_ID` are set

### Reverse Proxy
- [ ] Forwards all traffic to Sail port 80
- [ ] Sets `X-Forwarded-*` headers (Proto, Host, Port, For)
- [ ] Supports WebSocket upgrade (Upgrade, Connection headers)
- [ ] Disables buffering for streaming (`proxy_buffering off`)
- [ ] SSL certificate is valid (if using HTTPS)

### Build & Deploy
- [ ] Ran `./vendor/bin/sail artisan config:clear`
- [ ] Ran `./vendor/bin/sail npm run build`
- [ ] Ran `./vendor/bin/sail restart`
- [ ] All Sail containers are running (`./vendor/bin/sail ps`)

### Browser Tests
- [ ] Website loads at `https://app.example.com`
- [ ] Assets load with correct domain (check Network tab)
- [ ] WebSocket connects (check Console for errors)
- [ ] Real-time features work (test chat or notifications)
- [ ] No mixed content warnings (check Console)
- [ ] PWA installs correctly (if applicable)

### Command-Line Tests
```bash
# Test HTTP response
curl -I https://app.example.com
# Expected: HTTP/1.1 200 OK

# Test asset URLs
curl https://app.example.com | grep 'href='
# Should show: https://app.example.com/build/assets/...

# Test WebSocket upgrade
curl -i -N \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Host: app.example.com" \
  https://app.example.com/ws/
# Expected: HTTP/1.1 101 Switching Protocols
```

---

## Advanced Scenarios

### Using CDN for Assets

If you want to serve static assets from a CDN (CloudFlare, AWS CloudFront, etc.):

**Configuration:**
```bash
# .env
APP_URL=https://app.example.com
ASSET_URL=https://cdn.example.com
```

**Laravel automatically uses `ASSET_URL` for asset generation:**
```php
// config/app.php
'asset_url' => env('ASSET_URL'),
```

**Requirements:**
- CDN must be configured to pull from your origin (`app.example.com`)
- Configure CDN cache rules for `/build/*` paths
- Set appropriate Cache-Control headers

---

### Custom Ports

If your reverse proxy uses non-standard ports (e.g., `:8443` for HTTPS):

**Configuration:**
```bash
# .env
APP_HOST=app.example.com
APP_PROTOCOL=https

APP_URL=https://app.example.com:8443

REVERB_HOST=app.example.com
REVERB_PORT=8443
REVERB_SCHEME=https

VITE_REVERB_HOST=app.example.com
VITE_REVERB_PORT=8443
VITE_REVERB_SCHEME=https
```

**Reverse Proxy:**
```nginx
server {
    listen 8443 ssl http2;
    server_name app.example.com;

    # ... rest of configuration
}
```

---

### Docker Networks

If your reverse proxy runs in a separate Docker network:

**Option 1: Connect Reverse Proxy to Sail Network**
```bash
docker network connect promptlyagent_sail <proxy-container>
```

**Option 2: Create Shared Network**
```yaml
# docker-compose.override.yml
services:
  laravel.test:
    networks:
      - sail
      - shared

networks:
  shared:
    external: true
```

```bash
# Create shared network
docker network create shared

# Start reverse proxy on shared network
docker run -d --name proxy --network shared ...
```

**Option 3: Use Host Network Mode** (Linux only)
```yaml
# docker-compose.override.yml
services:
  laravel.test:
    network_mode: host
```

---

### Multiple Environments

If you run multiple Sail instances (dev, staging, production) on the same host:

**Use different ports:**
```yaml
# docker-compose.override.yml (dev)
services:
  laravel.test:
    ports:
      - "8001:80"

# docker-compose.override.yml (staging)
services:
  laravel.test:
    ports:
      - "8002:80"
```

**Configure reverse proxy:**
```nginx
# dev.example.com
server {
    server_name dev.example.com;
    location / {
        proxy_pass http://localhost:8001;
    }
}

# staging.example.com
server {
    server_name staging.example.com;
    location / {
        proxy_pass http://localhost:8002;
    }
}
```

---

## Security Considerations

### HTTPS Best Practices

**1. Use Strong TLS Configuration:**
```nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256';
ssl_prefer_server_ciphers off;
```

**2. Enable HSTS:**
```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

**3. Configure OCSP Stapling:**
```nginx
ssl_stapling on;
ssl_stapling_verify on;
ssl_trusted_certificate /path/to/chain.pem;
```

### Firewall Rules

**Only expose necessary ports:**
```bash
# Allow HTTPS
sudo ufw allow 443/tcp

# Allow HTTP (for redirect only)
sudo ufw allow 80/tcp

# Block Sail port from external access
sudo ufw deny 80/tcp from any to <sail-host-ip>
```

### Rate Limiting

**Nginx:**
```nginx
limit_req_zone $binary_remote_addr zone=main:10m rate=10r/s;

server {
    location / {
        limit_req zone=main burst=20 nodelay;
        # ... rest of config
    }
}
```

**Caddy:**
```caddy
app.example.com {
    rate_limit {
        zone dynamic 10mb
        rate 100r/m
    }
    reverse_proxy localhost:80
}
```

---

## Additional Resources

### Official Documentation
- **Laravel Documentation:** https://laravel.com/docs/12.x
- **Laravel Sail:** https://laravel.com/docs/12.x/sail
- **Laravel Reverb:** https://laravel.com/docs/12.x/reverb
- **Vite:** https://vitejs.dev/

### Reverse Proxy Documentation
- **Caddy:** https://caddyserver.com/docs/
- **Nginx:** https://nginx.org/en/docs/
- **Traefik:** https://doc.traefik.io/traefik/

### SSL/TLS
- **Let's Encrypt:** https://letsencrypt.org/
- **Certbot:** https://certbot.eff.org/
- **SSL Labs Test:** https://www.ssllabs.com/ssltest/

### Project Documentation
- **Getting Started:** `docs/01-getting-started.md`
- **Docker Infrastructure:** See `CLAUDE.md` section "Docker Infrastructure"

---

## Template Files

### Quick Reference Template

For a ready-to-use `.env` template with reverse proxy examples, see:

**`.env.example.remote`** - Complete configuration template with inline comments and proxy examples.

---

## Support

If you encounter issues not covered in this guide:

1. **Check logs:**
   ```bash
   # Laravel logs
   ./vendor/bin/sail artisan pail

   # Nginx logs (inside container)
   ./vendor/bin/sail exec laravel.test tail -f /var/log/nginx/error.log

   # Reverb logs
   ./vendor/bin/sail logs reverb
   ```

2. **Verify configuration:**
   ```bash
   # Check environment
   ./vendor/bin/sail artisan about

   # Test database connectivity
   ./vendor/bin/sail artisan db:show
   ```

3. **Community Resources:**
   - **PromptlyAgent Community:** https://promptlyagent.ai/community
   - **Documentation:** https://promptlyagent.ai/docs/
   - **GitHub Issues:** https://github.com/promptlyagent/promptlyagent/issues

---

**Last Updated:** 2026-03-14
**Laravel Version:** 12.x
**Sail Version:** 1.x
