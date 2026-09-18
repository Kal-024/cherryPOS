#!/usr/bin/env bash
#
# Restauración de un respaldo de cherryPOS.
#
# Objetivo de recuperación: **menos de 30 minutos**, ensayado. El ensayo es
# `drill.sh` y se corre una vez al mes; este guion es el del día de la falla y
# **sí toca la instalación**.
#
# Dos caminos, y la diferencia es cuánto se pierde:
#
#   ./restore.sh copia.tar.xz.enc
#       Restaura el **volcado lógico**. Rápido y simple, y deja la base tal como
#       estaba en el momento del respaldo: se pierde todo lo vendido desde
#       entonces.
#
#   ./restore.sh --pitr copia.tar.xz.enc ["2026-09-18 14:30:00-06"]
#       Restaura la **copia física y reproduce los WAL** hasta el final del
#       archivo —o hasta el momento que se indique, que es lo que sirve cuando
#       lo que hay que deshacer es un borrado y no una falla de disco—. Es el
#       camino que convierte la pérdida de un día en la de unos minutos, y la
#       razón de que el respaldo lleve las dos copias.
#
# Uso:  BACKUP_PASSPHRASE=… ./restore.sh [--pitr] archivo.tar.xz.enc [momento]
set -euo pipefail

MODE="logical"

if [ "${1:-}" = "--pitr" ]; then
  MODE="pitr"
  shift
fi

ARCHIVE="${1:?Indicá el archivo de respaldo}"
TARGET_TIME="${2:-}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

: "${BACKUP_PASSPHRASE:?Hace falta la clave con la que se cifró el respaldo}"

COMPOSE="${COMPOSE:-docker compose -f "$(dirname "$0")/../docker-compose.sucursal.yml"}"
DB_USER="${DB_USERNAME:-cherrypos}"
DB_NAME="${DB_DATABASE:-cherrypos}"
PGDATA_DIR="${PGDATA_DIR:-/var/lib/postgresql/data}"
WAL_DIR="${WAL_ARCHIVE_DIR:-/var/lib/postgresql/wal}"

echo "→ Descifrado"
openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$ARCHIVE" \
  | tar -xJf - -C "$WORK"

if [ "$MODE" = "pitr" ]; then
  [ -s "$WORK/base.tar.gz" ] || { echo "El respaldo no trae copia física." >&2; exit 1; }

  echo "→ Se detiene todo lo que escribe"
  # Primero los que escriben y después la base: al revés, la API pasaría unos
  # segundos hablándole a una base que ya no está y llenaría la bitácora de
  # errores que no son el problema.
  $COMPOSE stop web api
  $COMPOSE stop db

  echo "→ Reemplazo del directorio de datos"
  # Un contenedor auxiliar del propio servicio: hereda sus volúmenes sin que
  # haga falta saber cómo se llaman.
  $COMPOSE run --rm --no-deps --entrypoint sh -T db -c \
    "rm -rf ${PGDATA_DIR:?}/* && mkdir -p ${PGDATA_DIR} && tar -xzf - -C ${PGDATA_DIR}" \
    < "$WORK/base.tar.gz"

  echo "→ Archivo de WAL"
  $COMPOSE run --rm --no-deps --entrypoint sh -T db -c \
    "mkdir -p ${WAL_DIR} && tar -xf - -C ${WAL_DIR}" < "$WORK/wal.tar"

  echo "→ Configuración de recuperación"
  RECOVERY="restore_command = 'cp ${WAL_DIR}/%f %p'"

  if [ -n "$TARGET_TIME" ]; then
    # `promote` y no `pause`: la caja tiene que poder vender cuando termine, no
    # quedarse esperando a que alguien ejecute una sentencia.
    RECOVERY="$RECOVERY
recovery_target_time = '$TARGET_TIME'
recovery_target_action = 'promote'"
  fi

  $COMPOSE run --rm --no-deps -T -e RECOVERY_CONF="$RECOVERY" --entrypoint sh db -c \
    "printf '%s\n' \"\$RECOVERY_CONF\" >> ${PGDATA_DIR}/postgresql.auto.conf \
     && touch ${PGDATA_DIR}/recovery.signal \
     && chown -R postgres:postgres ${PGDATA_DIR} \
     && chmod 700 ${PGDATA_DIR}"

  echo "→ Arranque y reproducción de los WAL"
  $COMPOSE up -d db

  # La reproducción tarda lo que tarde; hasta que termine, la base rechaza
  # conexiones y arrancar la API antes solo produce ruido.
  until $COMPOSE exec -T db pg_isready -U "$DB_USER" >/dev/null 2>&1; do
    sleep 2
  done

  $COMPOSE up -d api web
else
  [ -s "$WORK/db.dump" ] || { echo "El respaldo no trae volcado lógico." >&2; exit 1; }

  echo "→ Restauración del volcado"
  $COMPOSE up -d db
  $COMPOSE exec -T db pg_restore -U "$DB_USER" -d "$DB_NAME" --clean --if-exists < "$WORK/db.dump"

  echo "→ Archivos subidos"
  $COMPOSE up -d api
  $COMPOSE exec -T api tar -xf - -C /var/www/html/storage < "$WORK/storage.tar"
fi

echo "→ Verificación de versión de esquema"
$COMPOSE exec -T api php artisan migrate --force
$COMPOSE exec -T db psql -U "$DB_USER" -d "$DB_NAME" -tAc \
  "SELECT 'esquema ' || version FROM cmn_schema_version WHERE id = 1"

echo
echo "Restaurado. Falta reapuntar el nombre pos.local a este equipo:"
echo "sin eso las terminales quedan ciegas, por más que el servidor esté sano."
