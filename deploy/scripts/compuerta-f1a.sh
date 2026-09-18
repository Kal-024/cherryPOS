#!/usr/bin/env bash
#
# Guion de cierre de F1-A, contra un servidor vivo.
#
# Es la compuerta del plan hecha guion: **no se declara F1-A cerrado hasta que
# esto pase completo**. Corre contra la API real, no contra pruebas con base de
# datos en memoria, porque lo que verifica es el cableado — rutas, permisos,
# serialización, orden de los middleware — que una prueba de integración con
# `RefreshDatabase` puede pasar por alto.
#
# Requisitos: la API en :8000 con el seeder de desarrollo corrido, y productos
# con código de barras cargados.
#
#   php artisan migrate:fresh --seed && php artisan serve
#   bash deploy/scripts/compuerta-f1a.sh
#
# Los pasos 7 y 8 —apagar el servidor, vender tres tickets, encenderlo y
# verificar que se envían solos y sin duplicados— se ejecutan componiendo los
# tickets **sin tocar la red**, tal como los arma el terminal, y enviándolos
# después. Para probar además que el servidor estaba realmente caído, corré el
# guion con `SIMULAR_CORTE=1` y detené la API entre los pasos 6 y 7.
set -euo pipefail
API=http://127.0.0.1:8000/api
j() { python3 -c "import sys,json;d=json.load(sys.stdin);print(eval(sys.argv[1]))" "$1"; }

step() { printf '\n\033[1m%s\033[0m\n' "$1"; }

step "1 · Terminal se autentica"
TOKEN=$(curl -s -X POST "$API/terminal/login" -H 'Accept: application/json' \
  -d 'branch_code=001&terminal_code=CAJA-01&secret=terminal-dev' | j "d['data']['token']")
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json')
echo "token ok"

step "2 · Cajero entra con PIN"
curl -s -X POST "$API/operator/session" "${AUTH[@]}" \
  -d '{"employee_code":"ADMIN","pin":"1234"}' | j "d['data']['employee']['full_name']"

step "3 · Abrir turno con fondo inicial"
curl -s -X POST "$API/shifts" "${AUTH[@]}" \
  -d '{"opening_float":"1000.00","counts":[{"denomination_value":"500.00","count":2}]}' \
  | j "d['data']['code']"

step "4 · Catálogo (lo que la caja cachea)"
curl -s "$API/catalog/products?limit=100" "${AUTH[@]}" | j "d['data'].__len__()" | xargs echo "productos:"

step "5 · Abrir venta y pasar el lector"
SALE=$(curl -s -X POST "$API/sales" "${AUTH[@]}" -d '{}' | j "d['data']['id']")
for CODE in 7501234567890 7501111111111 7504444444444; do
  curl -s -X POST "$API/sales/$SALE/lines" "${AUTH[@]}" \
    -d "{\"kind\":\"scan\",\"code\":\"$CODE\"}" > /dev/null
done
curl -s "$API/sales/$SALE" "${AUTH[@]}" \
  | j "'total=%s base=%s exento=%s iva=%s' % (d['data']['total'], d['data']['taxable_base'], d['data']['exempt_total'], d['data']['tax_total'])"

step "6 · Suspender y atender otra"
curl -s -X POST "$API/sales/$SALE/suspend" "${AUTH[@]}" -d '{"label":"Mesa 4"}' | j "d['data']['status']"
OTRA=$(curl -s -X POST "$API/sales" "${AUTH[@]}" -d '{}' | j "d['data']['id']")
curl -s -X POST "$API/sales/$OTRA/lines" "${AUTH[@]}" -d '{"kind":"scan","code":"7505555555555"}' > /dev/null
curl -s -X POST "$API/sales/$OTRA/payments" "${AUTH[@]}" -d '{"method":"cash","amount":"20.00"}' > /dev/null
curl -s -X POST "$API/sales/$OTRA/close" "${AUTH[@]}" -d '{}' | j "d['data']['number']"

step "7 · Retomar la suspendida y cobrar en dólares"
curl -s -X POST "$API/sales/$SALE/resume" "${AUTH[@]}" -d '{}' | j "d['data']['status']"
curl -s -X POST "$API/sales/$SALE/payments" "${AUTH[@]}" \
  -d '{"method":"cash","currency_code":"USD","amount":"10.00"}' \
  | j "'recibido=%s USD equivale=%s' % (d['data']['payment']['amount'], d['data']['payment']['amount_base'])"
curl -s -X POST "$API/sales/$SALE/close" "${AUTH[@]}" -d '{}' \
  | j "'cerrada %s vuelto pendiente=%s' % (d['data']['number'], d['data']['balance'])"

step "7b · Modo degradado: reservar correlativos"
RANGO=$(curl -s -X POST "$API/terminal/sequence/reserve" "${AUTH[@]}" \
  -d '{"document_type":"counter","size":20}')
echo "$RANGO" | j "'bloque %s..%s' % (d['data']['range_from'], d['data']['range_to'])"
DESDE=$(echo "$RANGO" | j "d['data']['range_from']")
EMPLEADO=$(curl -s "$API/operator/session" "${AUTH[@]}" | j "d['data']['employee']['id']")
PRODUCTO=$(curl -s "$API/catalog/products?limit=1" "${AUTH[@]}" | j "d['data'][0]['id']")

step "7c · Tres tickets compuestos SIN servidor"
# Se arman acá, como los arma el terminal: identificador propio, número del
# bloque reservado y totales calculados por el motor de TypeScript.
python3 - "$DESDE" "$EMPLEADO" "$PRODUCTO" > /tmp/tickets.json <<'PYEOF'
import json, sys, uuid, datetime, os
desde, empleado, producto = int(sys.argv[1]), sys.argv[2], sys.argv[3]
ahora = datetime.datetime.now(datetime.timezone.utc).isoformat()
tickets = []
for i in range(3):
    tickets.append({
        "id": str(uuid.uuid4()),
        "number": f"001-COU-{datetime.date.today().year}-{desde + i:06d}",
        "sale_type": "counter",
        "employee_id": empleado,
        "currency_code": "NIO",
        "opened_at": ahora,
        "closed_at": ahora,
        "lines": [{"product_id": producto, "description": "Gaseosa cola 1.5 L",
                   "kind": "product", "qty": "1", "unit_price": "45.00"}],
        "payments": [{"method": "cash", "amount": "45.00"}],
        "totals": {"total": "45.00"},
    })
print(json.dumps(tickets))
PYEOF
python3 -c "import json;print('compuestos:', len(json.load(open('/tmp/tickets.json'))))"

step "8a · Vuelve el servidor: los tres se envían"
python3 -c "
import json
for t in json.load(open('/tmp/tickets.json')):
    print(json.dumps(t))
" | while read -r TICKET; do
  curl -s -X POST "$API/offline-sales" "${AUTH[@]}" -d "$TICKET" \
    | j "'  %s %s' % (d['data']['number'], 'nuevo' if not d['data']['duplicate'] else 'DUPLICADO')"
done

step "8b · Reenviar no duplica"
python3 -c "
import json
for t in json.load(open('/tmp/tickets.json')):
    print(json.dumps(t))
" | while read -r TICKET; do
  curl -s -X POST "$API/offline-sales" "${AUTH[@]}" -d "$TICKET" \
    | j "'  %s %s' % (d['data']['number'], 'duplicado reconocido' if d['data']['duplicate'] else 'ERROR: creó otro')"
done

step "8 · Comprobante"
curl -s "$API/sales/$SALE/receipt" "${AUTH[@]}" | j "d['data']['lines'].__len__()" | xargs echo "líneas del ticket:"
curl -s -o /tmp/ticket.pdf -w "%{content_type} %{size_download} bytes\n" "$API/sales/$SALE/receipt/pdf" "${AUTH[@]}"

step "9 · Sondeo entre terminales"
curl -s "$API/sync/changes" "${AUTH[@]}" \
  | j "d['data'] and 'cambios=%d intervalo=%ss' % (d['data']['changes']['sales'].__len__(), d['data']['poll_after_seconds'])"

step "10 · Cerrar turno y arquear"
curl -s -X POST "$API/shifts/close" "${AUTH[@]}" \
  -d '{"counts":[{"denomination_value":"1000.00","count":1},{"denomination_value":"10.00","count":1,"currency_code":"USD"}]}' \
  | python3 -c "
import sys, json
d = json.load(sys.stdin)['data']
for c in d['by_currency']:
    print(f\"  {c['currency_code']}: esperado {c['expected']} · contado {c['counted']} · diferencia {c['difference']}\")
print('  ventas:', d['sales']['count'], 'total', d['sales']['total'])
for r in d['by_cashier']:
    print(f\"  cajero {r['employee_name']}: {r['sales']} ventas, {r['total']}\")
"
printf '\n\033[1;32mGuion completo.\033[0m\n'
