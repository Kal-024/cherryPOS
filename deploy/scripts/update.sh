#!/usr/bin/env bash
#
# Actualización de una instalación de cherryPOS.
#
# Cuatro requisitos, y ninguno es negociable (docs/OPERACION.md §4):
#
#  1. **Respaldo automático previo.** No el de anoche: uno de ahora.
#  2. **Nunca durante un turno abierto.** Un turno es el cajón físico contado al
#     abrir; reiniciar la API en medio deja el arqueo a mitad de camino y nadie
#     sabe después si la diferencia fue del cajero o del despliegue.
#  3. **Migraciones reversibles**, que es condición del código, no de este guion.
#  4. **Posibilidad de volver atrás**, que es lo que imprime al terminar.
#
# Uso:  BACKUP_PASSPHRASE=… ./update.sh [etiqueta-de-imagen]
set -euo pipefail

TAG="${1:-latest}"
HERE="$(dirname "$0")"

COMPOSE="${COMPOSE:-docker compose -f "$HERE/../docker-compose.sucursal.yml"}"
DB_USER="${DB_USERNAME:-cherrypos}"
DB_NAME="${DB_DATABASE:-cherrypos}"

: "${BACKUP_PASSPHRASE:?Toda actualización empieza por un respaldo, y va cifrado}"

psql_pos() { $COMPOSE exec -T db psql -U "$DB_USER" -d "$DB_NAME" -tAc "$1" | tr -d '\r'; }

echo "→ Turnos abiertos"
OPEN="$(psql_pos "SELECT COUNT(*) FROM pos_shifts WHERE status = 'open'")"

if [ "${OPEN:-0}" -gt 0 ]; then
  echo
  echo "Hay $OPEN turno(s) abierto(s). La actualización se hace **fuera del horario"
  echo "de operación**: pedile al supervisor que cierre caja y volvé a intentar." >&2
  exit 1
fi

echo "→ Envíos al ERP todavía en la cola"
# No frena la actualización: la cola es de solo inserción y sobrevive al
# reinicio (P1, P4). Se avisa porque un despliegue con la cola llena es el
# momento en que conviene mirar por qué no está saliendo.
PENDING="$(psql_pos "SELECT COUNT(*) FROM pos_erp_outbox WHERE status <> 'sent'")"
echo "  $PENDING pendiente(s)"

BEFORE="$(psql_pos "SELECT version FROM cmn_schema_version WHERE id = 1")"
echo "→ Versión de esquema actual: $BEFORE"

echo "→ Respaldo previo"
"$HERE/backup.sh"

echo "→ Imagen $TAG"
export IMAGE_TAG="$TAG"

# Con registro se baja la imagen etiquetada; sin él —una instalación que se
# actualiza desde el código— se construye en el equipo. El guion no elige por el
# cliente: intenta bajar y, si no hay de dónde, construye.
if ! $COMPOSE pull api web 2>/dev/null; then
  echo "  (sin registro: se construye la imagen localmente)"
  $COMPOSE build --pull api web
fi

$COMPOSE up -d --remove-orphans

echo "→ Migraciones"
$COMPOSE exec -T api php artisan migrate --force

AFTER="$(psql_pos "SELECT version FROM cmn_schema_version WHERE id = 1")"

echo
echo "Actualizado. Esquema: $BEFORE → $AFTER"
echo
echo "Comprobá la caja **antes de irte del local**: abrir turno, vender un ticket"
echo "de prueba y anularlo. Una API que arranca no es una caja que vende."
echo
echo "Si algo salió mal, el camino de vuelta es este, en este orden:"
echo
echo "    $COMPOSE exec -T api php artisan migrate:rollback --force"
echo "    IMAGE_TAG=<etiqueta-anterior> $COMPOSE up -d"
echo
echo "y si ni eso, el respaldo de hace un minuto con restore.sh."
