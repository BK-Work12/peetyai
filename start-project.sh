#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

log() {
  echo "[PeetyAI] $*"
}

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

start_stack() {
  log "Building and starting containers..."
  docker compose up -d --build

  log "Running Laravel setup..."
  if grep -Eq '^APP_KEY=$' backend/.env || ! grep -Eq '^APP_KEY=base64:' backend/.env; then
    docker compose exec -T backend php artisan key:generate --force
  fi

  docker compose exec -T backend php artisan migrate --force
  docker compose exec -T backend php artisan config:cache
  docker compose exec -T backend php artisan route:cache
  docker compose exec -T backend php artisan view:cache

  log "Project is up."
  log "Open: http://$(curl -s ifconfig.me 2>/dev/null || echo "<EC2-IP>")"
  log "Check status: docker compose ps"
  log "Tail logs: docker compose logs -f"
}

main() {
  install_docker_if_missing
  ensure_env_files
  start_stack

  cat <<'EOF'

IMPORTANT:
- Edit backend/.env with your real production values (DB, Redis, AI keys, WhatsApp keys).
- Re-run this script after changing backend/.env:
    bash start-project.sh
EOF
}

main "$@"
