#!/usr/bin/env bash
#
# Respaldo diario de una instalación de cherryPOS.
#
# Respalda **lo irrecuperable**: la base, los archivos subidos y la
# configuración con secretos. El código no: se reconstruye desde la imagen.
#
# El `.env` y la licencia son los que suelen olvidarse — son kilobytes, pero sin
# ellos el volcado de la base no sirve de nada.
#
# ── Dos copias de la base, y no es redundancia ──────────────────────────────
#
# **Base física (`pg_basebackup`) más los WAL archivados.** Es la única
# combinación que permite restaurar a un momento exacto: los WAL solo se pueden
# reproducir sobre una copia física. Con el volcado lógico no se reproducen — es
# un archivo de sentencias, no el estado del disco—, así que un respaldo que
# archive WAL junto a un `pg_dump` da una falsa sensación de estar cubierto y
# pierde igual todo lo ocurrido desde el último volcado.
#
# **Volcado lógico (`pg_dump -Fc`).** Sobrevive a lo que la copia física no: un
# disco con bloques corruptos, y una restauración en otra versión mayor de
# PostgreSQL. Cuesta segundos y es el camino del simulacro mensual.
#
# Uso:  BACKUP_PASSPHRASE=… ./backup.sh [destino]
set -euo pipefail

DESTINATION="${1:-/var/backups/cherrypos}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

: "${BACKUP_PASSPHRASE:?Los respaldos van cifrados: contienen cédulas de clientes}"

COMPOSE="${COMPOSE:-docker compose -f "$(dirname "$0")/../docker-compose.sucursal.yml"}"
DB_USER="${DB_USERNAME:-cherrypos}"
DB_NAME="${DB_DATABASE:-cherrypos}"
WAL_DIR="${WAL_ARCHIVE_DIR:-/var/lib/postgresql/wal}"
WAL_RETENTION_DAYS="${WAL_RETENTION_DAYS:-30}"

# Sin archivado no hay recuperación a un momento exacto, y el fallo es
# silencioso: `archive_mode` queda encendido, los segmentos se acumulan y el
# respaldo parece correcto hasta el día que hay que restaurar. Se comprueba
# **antes** de respaldar, que es cuando todavía se puede arreglar.
echo "→ Estado del archivado de WAL"
# Se compara el **último** intento fallido contra el último éxito, no el
# contador acumulado: `failed_count` no se reinicia nunca, así que un problema
# ya resuelto —permisos arreglados hace meses— dejaría el respaldo bloqueado
# para siempre. Lo que importa es si está fallando ahora.
ARCHIVER="$($COMPOSE exec -T db psql -U "$DB_USER" -d "$DB_NAME" -tAc "
  SELECT CASE
    WHEN last_archived_wal IS NULL THEN 'nunca'
    WHEN last_failed_time IS NOT NULL AND last_failed_time > last_archived_time THEN 'fallando'
    ELSE 'ok ' || last_archived_wal
  END FROM pg_stat_archiver" | tr -d '\r')"

case "$ARCHIVER" in
  ok*) LAST_ARCHIVED="${ARCHIVER#ok }" ;;
  *)
    echo "  ✗ El archivado de WAL no está funcionando ($ARCHIVER)." >&2
    echo "    Revisar permisos del directorio de archivo: tiene que ser del usuario" >&2
    echo "    postgres. Mientras no funcione, la pérdida máxima es de un día entero" >&2
    echo "    y el disco se va llenando de segmentos que nadie retira." >&2
    exit 1
    ;;
esac

echo "  ✓ Último segmento archivado: $LAST_ARCHIVED"

echo "→ Base física"
# `-X fetch` y no `-X none`: con `none`, `pg_basebackup` **espera** a que se
# archiven los segmentos que necesita, y en una caja sin movimiento —la de la
# madrugada, justo cuando corre el respaldo— el segmento en curso no se archiva
# hasta llenarse. El respaldo se quedaba colgado toda la noche. Con `fetch`, los
# WAL imprescindibles viajan dentro del tar y la copia se basta a sí misma; los
# posteriores siguen saliendo del archivo, que es lo que permite avanzar **más
# allá** del momento del respaldo.
$COMPOSE exec -T db pg_basebackup -U "$DB_USER" -Ft -z -X fetch -D - -c fast \
  > "$WORK/base.tar.gz"

# Se fuerza el cambio de segmento para que lo ocurrido hasta este momento quede
# archivado ya. Sin esto, lo último vendido vive en el segmento en curso y no se
# puede reproducir hasta que ese segmento se llene — que en una caja tranquila
# puede ser al día siguiente.
$COMPOSE exec -T db psql -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT pg_switch_wal()" >/dev/null

echo "→ Volcado lógico"
$COMPOSE exec -T db pg_dump -U "$DB_USER" -Fc "$DB_NAME" > "$WORK/db.dump"

echo "→ Archivo de WAL"
$COMPOSE exec -T db tar -cf - -C "$WAL_DIR" . > "$WORK/wal.tar"

echo "→ Archivos subidos"
$COMPOSE exec -T api tar -cf - -C /var/www/html/storage app > "$WORK/storage.tar"

echo "→ Configuración con secretos"
tar -cf "$WORK/config.tar" -C "$(dirname "$0")/../.." \
    apps/api/.env 2>/dev/null || echo "  (sin .env: instalación de desarrollo)"

echo "→ Empaquetado y cifrado"
mkdir -p "$DESTINATION"
tar -cJf - -C "$WORK" . \
  | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE \
  > "$DESTINATION/cherrypos-$STAMP.tar.xz.enc"

# Los segmentos viejos se retiran por edad y **con la misma ventana que los
# respaldos**: guardar treinta días de copias y siete de WAL deja veintitrés días
# de respaldos que ya no se pueden reproducir más allá del minuto en que se
# tomaron. Si el disco aprieta, lo que se baja es la ventana entera, respaldos
# incluidos, no solo el WAL.
echo "→ Retención del archivo de WAL: ${WAL_RETENTION_DAYS} días"
$COMPOSE exec -T db sh -c \
  "find '$WAL_DIR' -type f -mtime +$WAL_RETENTION_DAYS -delete" || true

echo "→ Retención: diarios 30 días"
find "$DESTINATION" -name 'cherrypos-*.tar.xz.enc' -mtime +30 -delete

echo "Listo: $DESTINATION/cherrypos-$STAMP.tar.xz.enc"
echo
echo "Recordatorio: regla 3-2-1. Esta copia es la local. Falta llevar una fuera"
echo "del local, y correr el simulacro una vez al mes:"
echo
echo "    BACKUP_PASSPHRASE=… ./drill.sh $DESTINATION/cherrypos-$STAMP.tar.xz.enc"
echo
echo "Un respaldo no verificado no cuenta como respaldo."
