---
name: cloud-deployment
description: >
  How to stand up this stack on a fresh cloud server (any provider — Hetzner,
  DigitalOcean, etc.) behind a shared host-level nginx, so multiple projects
  can live side by side on one box each with its own subdomain and HTTPS.
  Covers server prep, Docker install, the host-nginx reverse-proxy pattern,
  two HTTPS paths (Let's Encrypt and Cloudflare Origin CA), firewall, and
  setting up a non-root admin user.
triggers:
  - "deploy this to a server" / "move this to the cloud"
  - "set up HTTPS" / "point my domain at the server"
  - Provisioning a fresh Ubuntu server for this stack
  - Adding a second project to a server that already runs this stack
---

# Skill: Cloud deployment (shared host, HTTPS, multi-project)

## Architecture

A single server can host **multiple independent projects**, each on its own
subdomain, without any of them fighting over ports 80/443:

```
Internet → host-level nginx (80/443, owns TLS) → per-project internal port
                                                    ├─ project-a: 127.0.0.1:8081
                                                    ├─ project-b: 127.0.0.1:8082
                                                    └─ ...
```

- The **host-level nginx** (installed directly on the server, not in Docker)
  is the only thing that ever binds 80/443. It picks the right project by
  `server_name` (domain) and reverse-proxies to that project's own internal
  port. It should stay generic — no project-specific logic, auth, or content
  belongs here.
- **Each project's own `docker-compose.yml` nginx** does *not* publish to
  the host's public interface. It binds to `127.0.0.1:<some-port>` instead,
  reachable only from the host-level nginx, not the internet directly.
- This repo's `docker-compose.yml` already supports this via env vars — see
  `.env.example`: `NGINX_BIND_ADDR`, `NGINX_HTTP_PORT`, `NGINX_HTTPS_PORT`.
  Defaults (`0.0.0.0:80`/`443`) are for a standalone single-project server;
  override to `127.0.0.1:8081` (pick an unused port per project) for a
  shared-host deployment.

Any project-specific concern (authentication, rate limiting, etc.) belongs
**inside that project's own compose stack** (e.g. an `auth_request` in its
own nginx against a bundled auth service), not in the shared host-level
config — that keeps each project portable and self-contained, and keeps the
host layer reusable for arbitrary future projects with different needs.

## Server prep (once per server, not per project)

```bash
# Docker
curl -fsSL https://get.docker.com | sh

# Host nginx + certbot (only needed if using the Let's Encrypt path below)
apt-get update -qq && apt-get install -y -qq nginx certbot python3-certbot-nginx

# Firewall — allow only what's needed
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```

### Non-root admin user

Don't do ongoing work as `root`. Create a sudo user and reuse the same SSH
key already trusted for root (no need for a second keypair):

```bash
adduser --disabled-password --gecos '' deployuser
usermod -aG sudo deployuser
mkdir -p /home/deployuser/.ssh
cp /root/.ssh/authorized_keys /home/deployuser/.ssh/authorized_keys
chown -R deployuser:deployuser /home/deployuser/.ssh
chmod 700 /home/deployuser/.ssh && chmod 600 /home/deployuser/.ssh/authorized_keys
echo 'deployuser ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/deployuser
chmod 440 /etc/sudoers.d/deployuser
visudo -c   # verify before trusting it
```

Test `ssh deployuser@<server>` and `sudo whoami` before relying on it. Leave
root login enabled unless explicitly asked to disable it — that's a real
lockout risk if the new user turns out to be misconfigured.

If an agent will be doing ongoing admin work on the box, install Claude Code
under that user's own home rather than root's:

```bash
ssh deployuser@<server> "curl -fsSL https://claude.ai/install.sh | bash"
```

## Per-project deployment

```bash
mkdir -p /opt/<project-name>
cd /opt && git clone <repo-url> <project-name>
cd <project-name>
```

Generate **fresh** secrets on the server — never copy a local `.env` or
`secrets/companies.php` over. Random DB/MinIO passwords, empty/placeholder
`companies.php` (copy from `secrets/companies.php.example`), and set the
nginx binding for shared-host mode:

```
NGINX_BIND_ADDR=127.0.0.1
NGINX_HTTP_PORT=8081     # pick an unused port per project
NGINX_HTTPS_PORT=8444
```

`docker compose up -d --build` as usual (see `onboarding.md`).

## Host-nginx vhost (per project)

```
server {
    listen 80;
    server_name project.yourdomain.com;
    client_max_body_size 200M;

    location / {
        proxy_pass         http://127.0.0.1:8081;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```

**Gotcha (hit this exact bug live):** if you generate this config via a
heredoc over SSH (`ssh host "cat > file <<EOF ... EOF"`), an *unquoted*
heredoc delimiter (`<<EOF`) lets the **remote** shell expand `$host`,
`$uri`, etc. before nginx ever sees them — silently producing
`proxy_pass http://127.0.0.1:8081; proxy_set_header Host ;` (empty
variables) that still passes `nginx -t` since it's syntactically valid, just
functionally broken. Always quote the delimiter (`<<'EOF'`) when the heredoc
body contains nginx variables, and separately escape any `$` you *do* want
the local shell to leave alone if the outer `ssh "..."` command itself is
double-quoted. After writing any generated nginx config this way, `cat` it
back and actually read it before reloading — `nginx -t` alone won't catch
this class of bug.

`ln -sf` it into `sites-enabled/`, `nginx -t`, `systemctl reload nginx`.

## Getting HTTPS — two paths

### Path A: Let's Encrypt (any registrar)

Works once DNS actually points at the server:

```bash
certbot --nginx -d project.yourdomain.com -d www.project.yourdomain.com
```

**To get a cert *before* pointing DNS at the server** (so there's no window
of the domain resolving here without HTTPS once you do flip it): use the
DNS-01 challenge instead of HTTP-01. It proves domain control via a TXT
record, independent of where the A record currently points. Certbot's
`--manual` DNS challenge normally pauses interactively waiting for you to
add the record — to avoid babysitting that, use a `--manual-auth-hook`
script that polls public DNS (e.g. Google's DNS-over-HTTPS,
`https://dns.google/resolve?name=...&type=TXT`) in a loop until the record
appears, then exits 0 so certbot proceeds automatically:

```bash
certbot certonly --manual --preferred-challenges dns \
  --manual-auth-hook /path/to/poll-dns-hook.sh \
  --manual-cleanup-hook /bin/true \
  --register-unsafely-without-email --agree-tos --non-interactive \
  -d project.yourdomain.com -d www.project.yourdomain.com
```

Run this in the background (`nohup ... &`) since it can take a while waiting
on you to add each TXT record; certbot calls the hook once per `-d` domain,
sequentially, each needing its own `_acme-challenge.<domain>` TXT record.

**Careful killing a stray `certbot certonly` background process** with
`pkill -f 'certbot certonly'`: the pattern can match `pkill`'s own argv
(since the search string is literally present in the command line invoking
it), which can signal the shell session itself and abort the SSH channel
with a false-looking error — the kill usually still worked; verify with
`ps aux | grep certbot` rather than trusting the exit code.

### Path B: Cloudflare (when the domain's DNS is on Cloudflare)

Simpler — no Let's Encrypt dance needed at all:

1. Cloudflare dashboard → **SSL/TLS → Origin Server → Create Certificate**
   → "Generate private key and CSR with Cloudflare" → RSA 2048 → leave the
   prefilled hostnames (apex + wildcard) → Create. This is a **different**
   feature from "Edge Certificates" (that's Cloudflare's own automatic
   browser-facing cert, nothing to do here) and "Client Certificates"
   (mTLS, unrelated) — it's specifically for securing the Cloudflare↔origin
   leg.
2. Save the shown cert + key on the server (only shown once):
   ```bash
   mkdir -p /etc/nginx/ssl/project.yourdomain.com
   # paste into cert.pem / key.pem via the dashboard's two boxes
   chmod 600 /etc/nginx/ssl/project.yourdomain.com/key.pem
   chmod 644 /etc/nginx/ssl/project.yourdomain.com/cert.pem
   ```
3. Host-nginx vhost: `listen 443 ssl; http2 on;` referencing those two
   files, plus a `listen 80` block that 301-redirects to https. (Same
   heredoc-quoting gotcha as above applies here too.)
4. On Cloudflare: add A records for the domain (and `www`) pointing at the
   server, **Proxied** (orange cloud) — this alone gives instant public
   HTTPS via Cloudflare's own edge cert, no waiting. Then set
   **SSL/TLS → Overview → Full (strict)** so Cloudflare actually verifies
   the Origin CA cert from step 1 instead of trusting anything.

**Gotcha**: a Cloudflare-**proxied** domain resolves to Cloudflare's edge
IPs, not the origin's real IP, and only proxies HTTP(S) by default — **SSH
to a proxied hostname will not work**. Either SSH by IP directly, or add a
*separate*, **DNS-only** (grey cloud) record (e.g. `server.yourdomain.com`)
for admin access — TTL can stay short/"Auto" since it's low-traffic and you
want fast propagation if the server's IP ever changes.

Cloudflare also silently rewrites plain-text email addresses in served HTML
into an obfuscated `/cdn-cgi/l/email-protection` span (anti-scraping
"Email Address Obfuscation") — real browsers decode it fine via injected
JS; don't mistake the raw HTML source looking different through Cloudflare
vs. hitting the origin directly for a deployment bug.

## Related skills

- `stack-orientation.md` — the services this deployment wraps.
- `onboarding.md` — first-time setup of the stack itself, once it's on the
  server.
- `gestoria-document-store.md` — the MinIO document store this stack also
  runs, same shared-host considerations apply.
