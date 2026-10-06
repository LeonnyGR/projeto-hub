#!/usr/bin/env bash
# Para e remove os containers (volumes e rede sao mantidos).
# Uso: docker/scripts/down.sh [--purge]
#   --purge  tambem apaga os volumes (Postgres, Redis, etiquetas) e a rede. DESTRUTIVO.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
exige_docker

PURGE=0
for a in "$@"; do
    case "$a" in
        --purge) PURGE=1 ;;
        -h|--help) sed -n '2,5p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "argumento desconhecido: $a" ;;
    esac
done

for c in "$C_SCHEDULER" "$C_APP" "$C_REDIS" "$C_POSTGRES"; do remove_container "$c"; done

if [[ "$PURGE" == 1 ]]; then
    aviso "Isto APAGA os dados: volumes $V_POSTGRES, $V_REDIS e $V_STORAGE."
    read -r -p "Digite 'apagar' para confirmar: " r
    [[ "$r" == "apagar" ]] || die "cancelado"
    docker volume rm "$V_POSTGRES" "$V_REDIS" "$V_STORAGE" 2>/dev/null || true
    docker network rm "$REDE" 2>/dev/null || true
fi
log "Stack parada."
