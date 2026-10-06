#!/usr/bin/env bash
# Logs dos containers. Uso: docker/scripts/logs.sh [app|scheduler|postgres|redis] [-f] [--tail N]
# Sem servico: mostra o app. Demais argumentos vao direto para `docker logs`.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
exige_docker

servico="app"
if [[ $# -gt 0 && "$1" != -* ]]; then servico="$1"; shift; fi
case "$servico" in
    app) c="$C_APP" ;; scheduler) c="$C_SCHEDULER" ;;
    postgres) c="$C_POSTGRES" ;; redis) c="$C_REDIS" ;;
    *) die "servico desconhecido: $servico" ;;
esac
exec docker logs "$@" "$c"
