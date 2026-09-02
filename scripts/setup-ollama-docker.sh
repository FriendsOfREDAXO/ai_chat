#!/usr/bin/env bash
set -euo pipefail

CHAT_MODEL="${1:-gemma4:e4b}"
EMBED_MODEL="${2:-nomic-embed-text:latest}"
WEB_CONTAINER="${3:-coreweb}"
OLLAMA_HOST_URL="${4:-http://host.docker.internal:11434}"

log() {
  printf '%s\n' "$1"
}

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    log "Fehlt: $1"
    exit 1
  fi
}

need_cmd ollama
need_cmd docker

log "Pruefe lokale Ollama-Modelle..."
if ! ollama list >/dev/null 2>&1; then
  log "Ollama scheint nicht erreichbar zu sein. Bitte zuerst lokal starten."
  exit 1
fi

pull_if_missing() {
  local model="$1"
  if ollama list | awk 'NR>1 {print $1}' | grep -Fx "$model" >/dev/null 2>&1; then
    log "Modell vorhanden: $model"
  else
    log "Modell fehlt, wird geladen: $model"
    ollama pull "$model"
  fi
}

pull_if_missing "$CHAT_MODEL"
pull_if_missing "$EMBED_MODEL"

log "Pruefe Erreichbarkeit aus Docker-Container: $WEB_CONTAINER"
if ! docker ps --format '{{.Names}}' | grep -Fx "$WEB_CONTAINER" >/dev/null 2>&1; then
  log "Container '$WEB_CONTAINER' laeuft nicht. Bitte REDAXO-Container starten."
  exit 1
fi

if docker exec "$WEB_CONTAINER" sh -lc "curl -fsS ${OLLAMA_HOST_URL}/v1/models >/dev/null"; then
  log "OK: ${OLLAMA_HOST_URL}/v1/models ist aus Docker erreichbar"
else
  log "Fehler: ${OLLAMA_HOST_URL}/v1/models ist aus Docker NICHT erreichbar"
  exit 1
fi

cat <<EOF

Fertig. Bitte in REDAXO -> klxmchat -> Einstellungen eintragen:

KI Provider: OpenWebUI / OpenAI Compatible
Base URL: ${OLLAMA_HOST_URL}/v1
API Key: (leer)
Model Name: ${CHAT_MODEL}
Embedding Model: ${EMBED_MODEL}

Danach:
1) Einmal "Jetzt indexieren" fuer den Erstaufbau
2) Danach "Refresh (inkrementell)" fuer laufende Updates
EOF
