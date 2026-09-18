#!/usr/bin/env bash
#
# Simulacro mensual de restauración (§11 del plan, nivel "Operación").
#
# **Un respaldo no verificado no cuenta como respaldo.** Este guion es el que
# convierte la frase en un hecho: descifra el respaldo, lo restaura en una base
# desechable y comprueba que lo restaurado sirve para operar.
#
# Es distinto de `restore.sh` a propósito. Aquel restaura **sobre la
# instalación** y es el del día de la falla; este no toca nada de producción,
# porque un ensayo que pueda romper lo que ensaya no se hace nunca.
#
# Lo que mide, y por qué importa cada cosa:
#
#  - **Que el archivo se descifre.** La clave equivocada se descubre hoy y no el
#    día que arde el servidor.
#  - **Que la restauración termine.** Un volcado truncado pesa parecido a uno
#    entero.
#  - **Cuánto se habría perdido** (RPO): la venta más nueva que trae el respaldo
#    frente a ahora. Es la cifra que delata un archivado de WAL que dejó de
#    funcionar hace semanas.
#  - **Cuánto tardó** (RTO): el objetivo son 30 minutos, ensayados.
#
# Uso:  BACKUP_PASSPHRASE=… ./drill.sh archivo.tar.xz.enc
set -euo pipefail

ARCHIVE="${1:?Indicá el archivo de respaldo a ensayar}"
WORK="$(mktemp -d)"
STARTED="$(date +%s)"

COMPOSE="${COMPOSE:-docker compose -f "$(dirname "$0")/../docker-compose.sucursal.yml"}"
DB_USER="${DB_USERNAME:-cherrypos}"
DB_NAME="${DB_DATABASE:-cherrypos}"
DRILL_DB="${DRILL_DATABASE:-cherrypos_drill}"
# El objetivo de recuperación, en minutos. Se compara al final.
RTO_MINUTES="${RTO_MINUTES:-30}"

: "${BACKUP_PASSPHRASE:?Hace falta la clave con la que se cifró el respaldo}"

if [ "$DRILL_DB" = "$DB_NAME" ]; then
  echo "La base del simulacro no puede ser la de producción ($DB_NAME)." >&2
  exit 2
fi

# Una consulta que falla no corta el guion: lo que sigue es un mensaje que dice
# qué pasó. "relation cmn_schema_version does not exist" es verdad y no ayuda;
# "lo restaurado no es una base de cherryPOS" sí.
psql_drill() { $COMPOSE exec -T db psql -U "$DB_USER" -d "$DRILL_DB" -tAc "$1" 2>/dev/null | tr -d '\r' || true; }

cleanup() {
  rm -rf "$WORK"
  # La base desechable se tira siempre, incluso si el ensayo falló: dejarla
  # ocupando disco en el servidor del cliente es el peor recuerdo del simulacro.
  $COMPOSE exec -T db psql -U "$DB_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS \"$DRILL_DB\"" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "→ Descifrado"
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$ARCHIVE" \
  | tar -xJf - -C "$WORK"

[ -s "$WORK/db.dump" ] || { echo "El respaldo no trae volcado de base." >&2; exit 1; }

echo "→ Base desechable: $DRILL_DB"
$COMPOSE exec -T db psql -U "$DB_USER" -d postgres \
  -c "DROP DATABASE IF EXISTS \"$DRILL_DB\"" >/dev/null
$COMPOSE exec -T db psql -U "$DB_USER" -d postgres \
  -c "CREATE DATABASE \"$DRILL_DB\" OWNER \"$DB_USER\"" >/dev/null

echo "→ Restauración del volcado"
$COMPOSE exec -T db pg_restore -U "$DB_USER" -d "$DRILL_DB" --no-owner < "$WORK/db.dump"

echo "→ Comprobaciones"

SCHEMA="$(psql_drill "SELECT version FROM cmn_schema_version WHERE id = 1")"
[ -n "$SCHEMA" ] || { echo "  ✗ Sin versión de esquema: lo restaurado no es una base de cherryPOS." >&2; exit 1; }
echo "  ✓ Versión de esquema: $SCHEMA"

SALES="$(psql_drill "SELECT COUNT(*) FROM pos_sales")"
AUDIT="$(psql_drill "SELECT COUNT(*) FROM sec_audit_log")"
echo "  ✓ Ventas restauradas: $SALES · bitácora: $AUDIT"

# La bitácora es solo-inserción (P1): que esté vacía con ventas registradas
# significa que se restauró una parte y no el todo.
if [ "$SALES" -gt 0 ] && [ "$AUDIT" -eq 0 ]; then
  echo "  ✗ Hay ventas y la bitácora está vacía: la restauración quedó a medias." >&2
  exit 1
fi

LAST_SALE="$(psql_drill "SELECT COALESCE(MAX(closed_at)::text, '')  FROM pos_sales WHERE closed_at IS NOT NULL")"

if [ -n "$LAST_SALE" ]; then
  LOST_MINUTES="$(psql_drill "SELECT ROUND(EXTRACT(EPOCH FROM (now() - MAX(closed_at))) / 60) FROM pos_sales WHERE closed_at IS NOT NULL")"
  echo "  ✓ Última venta del respaldo: $LAST_SALE (hace ${LOST_MINUTES} min)"
  echo
  echo "    Eso es lo que se perdería restaurando **solo este archivo**. Si la"
  echo "    cifra es de horas, el archivado de WAL no está cumpliendo su papel:"
  echo "    revisar que el directorio de archivo esté recibiendo segmentos."
else
  echo "  · El respaldo no tiene ventas cerradas: instalación nueva o de prueba."
fi

ELAPSED=$(( ($(date +%s) - STARTED) / 60 ))
echo
echo "Simulacro completo en ${ELAPSED} min (objetivo: ${RTO_MINUTES})."

if [ "$ELAPSED" -gt "$RTO_MINUTES" ]; then
  echo "Por encima del objetivo de recuperación. No es un fallo del respaldo," >&2
  echo "es una promesa que hoy no se cumple: hay que revisar el procedimiento." >&2
  exit 1
fi

echo "Anotá la fecha: el próximo toca en un mes."
