#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

log() {
  echo "[PeetyAI] $*"
}

usage() {
  cat <<'EOF'
Usage: bash start-project.sh [--ssl] [--domain example.com] [--email admin@example.com]

Options:
  --ssl             Issue or renew a Let's Encrypt certificate and enable HTTPS.
  --domain DOMAIN   Domain name to secure, for example peety.ai.
  --email EMAIL     Email address used by Let's Encrypt for expiry notices.
EOF
}

SSL_ENABLED=false
SSL_DOMAIN=""
SSL_EMAIL=""

need_sudo() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "sudo"
  fi
}

install_docker_if_missing() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    log "Docker and Docker Compose already installed."
    return
  fi

  log "Installing Docker and Docker Compose plugin (Ubuntu)..."
  SUDO="$(need_sudo)"

  $SUDO apt-get update
  $SUDO apt-get install -y ca-certificates curl gnupg
  $SUDO install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | $SUDO gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  $SUDO chmod a+r /etc/apt/keyrings/docker.gpg

  . /etc/os-release
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    | $SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null

  $SUDO apt-get update
  $SUDO apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

  if [[ "${EUID}" -ne 0 ]]; then
    if ! groups "$USER" | grep -q "\bdocker\b"; then
      $SUDO usermod -aG docker "$USER" || true
      log "Added $USER to docker group. You may need to log out and log in once."
    fi
  fi
}

ensure_env_files() {
  if [[ ! -f backend/.env ]]; then
    cp backend/.env.example backend/.env
    log "Created backend/.env from backend/.env.example"
  fi

  if [[ ! -f frontend/.env.local ]]; then
    cat > frontend/.env.local <<'EOF'
NEXT_PUBLIC_API_BASE_URL=/api
EOF
    log "Created frontend/.env.local"
  fi
}

write_http_nginx_config() {
  cat > deploy/nginx/default.conf <<'EOF'
server {
    listen 80;
    server_name _;

    resolver 127.0.0.11 valid=30s;
    resolver_timeout 5s;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
        default_type "text/plain";
        allow all;
    }

    location /api/ {
        set $backend_upstream backend:8000;
        proxy_pass http://$backend_upstream;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        set $frontend_upstream frontend:3000;
        proxy_pass http://$frontend_upstream;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }
}
EOF
}

write_ssl_nginx_config() {
  if [[ -z "$SSL_DOMAIN" ]]; then
    echo "SSL domain is required before writing the HTTPS config." >&2
    exit 1
  fi

  sed "s/__DOMAIN__/${SSL_DOMAIN}/g" deploy/nginx/default-ssl.conf > deploy/nginx/default.conf
}

start_http_only_stack() {
  log "Starting Nginx for certificate validation..."
  docker compose up -d --no-deps nginx
}

issue_ssl_certificate() {
  if [[ -z "$SSL_DOMAIN" || -z "$SSL_EMAIL" ]]; then
    echo "--domain and --email are required when --ssl is enabled." >&2
    exit 1
  fi

  log "Requesting Let's Encrypt certificate for ${SSL_DOMAIN}..."
  docker compose run --rm certbot certonly \
    --webroot \
    --webroot-path /var/www/certbot \
    --email "$SSL_EMAIL" \
    --agree-tos \
    --no-eff-email \
    --keep-until-expiring \
    --non-interactive \
    -d "$SSL_DOMAIN"
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --ssl)
        SSL_ENABLED=true
        shift
        ;;
      --domain)
        SSL_DOMAIN="${2:-}"
        shift 2
        ;;
      --email)
        SSL_EMAIL="${2:-}"
        shift 2
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        echo "Unknown argument: $1" >&2
        usage >&2
        exit 1
        ;;
    esac
  done
}

wait_for_service_running() {
  local service="$1"
  local timeout_seconds="${2:-30}"
  local elapsed=0

  while (( elapsed < timeout_seconds )); do
    if docker compose ps --status running --services | grep -qx "$service"; then
      return 0
    fi
    sleep 1
    elapsed=$((elapsed + 1))
  done

  log "Service '$service' is not running after ${timeout_seconds}s."
  docker compose ps || true
  log "Recent logs for '$service':"
  docker compose logs --tail 200 "$service" || true
  return 1
}

start_stack() {
  log "Building and starting containers..."
  docker compose up -d --build

  wait_for_service_running backend 45

  log "Running Laravel setup..."
  if grep -Eq '^APP_KEY=$' backend/.env || ! grep -Eq '^APP_KEY=base64:' backend/.env; then
    docker compose exec -T backend php artisan key:generate --force
  fi

  docker compose exec -T backend php artisan migrate --force
  docker compose exec -T backend php artisan db:seed --force
  docker compose exec -T backend php artisan config:cache
  docker compose exec -T backend php artisan route:cache
  docker compose exec -T backend php artisan view:cache

  log "Project is up."
  log "Open: http://$(curl -s ifconfig.me 2>/dev/null || echo "<EC2-IP>")"
  log "Check status: docker compose ps"
  log "Tail logs: docker compose logs -f"
}

main() {
  parse_args "$@"
  install_docker_if_missing
  ensure_env_files
  write_http_nginx_config

  if [[ "$SSL_ENABLED" == true ]]; then
    start_http_only_stack
    issue_ssl_certificate
    write_ssl_nginx_config
  fi

  start_stack

  cat <<'EOF'

IMPORTANT:
- Edit backend/.env with your real production values (DB, Redis, AI keys, WhatsApp keys).
- Re-run this script after changing backend/.env:
    bash start-project.sh
EOF

  if [[ "$SSL_ENABLED" == true ]]; then
    cat <<EOF

HTTPS enabled for ${SSL_DOMAIN}
Open: https://${SSL_DOMAIN}
EOF
  fi
}

main "$@"
