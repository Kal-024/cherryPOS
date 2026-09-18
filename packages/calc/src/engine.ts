import {
  SCALE,
  abs,
  distribute,
  divRoundHalfUp,
  format,
  mulShift,
  parse
} from './decimal'
import type {
  Discount,
  LineResult,
  Payment,
  SaleInput,
  SaleResult,
  TaxAmount,
  TaxRate
} from './types'

/**
 * Motor de cálculo de una venta.
 *
 * El orden de las operaciones **es** el activo: descuento de línea, descuento de
 * venta repartido, base imponible, impuesto, y solo al final el redondeo de
 * efectivo. Cualquier permutación produce otro total, y es donde casi todo POS
 * nuevo se equivoca (D-02).
 *
 * Espejo exacto de `App\Services\Calc\SaleCalculator` en `apps/api`. Ningún
 * cambio entra aquí sin su caso en `fixtures/`, y el fixture se escribe antes.
 */

/** Diferencia entre `10^6` y las escalas: se usa en casi todas las divisiones. */
const SHIFT_QTY_PRICE = SCALE.qty + SCALE.price - SCALE.money // 4 + 4 - 2 = 6
const SHIFT_RATE = SCALE.rate + 2 // porcentaje: /100 y /10^4
const RATE_ONE = 10n ** BigInt(SHIFT_RATE) // 100 % expresado a escala de tasa

interface WorkingLine {
  id: string
  gross: bigint
  lineDiscount: bigint
  saleDiscountShare: bigint
  taxableBase: bigint
  taxes: { code: string, rate: bigint, base: bigint, amount: bigint }[]
  taxTotal: bigint
  total: bigint
  /**
   * Exenta por naturaleza del bien o por exoneración del comprador. **No** lo
   * es una venta bajo cuota fija: esa es no gravada, que fiscalmente es otra
   * cosa aunque el total coincida.
   */
  exempt: boolean
}

export function calculateSale(input: SaleInput): SaleResult {
  const { config } = input
  const fixedQuota = config.fixedQuotaRegime === true
  const customerExempt = input.customer?.taxExempt === true

  // ── 1. Bruto y descuento de línea ────────────────────────────────────────
  const lines: WorkingLine[] = input.lines.map((line) => {
    const qty = parse(line.qty, SCALE.qty)
    const unitPrice = parse(line.unitPrice, SCALE.price)
    const gross = mulShift(qty, unitPrice, SHIFT_QTY_PRICE)

    return {
      id: line.id,
      gross,
      lineDiscount: resolveDiscount(line.discount, gross),
      saleDiscountShare: 0n,
      taxableBase: 0n,
      taxes: [],
      taxTotal: 0n,
      total: 0n,
      exempt: false
    }
  })

  // ── 2. Descuento de venta, repartido proporcionalmente ───────────────────
  // El peso es el importe ya neto de descuento de línea: una línea que ya tuvo
  // su rebaja no debe absorber además la parte mayor del descuento general.
  const weights = lines.map((l) => l.gross - l.lineDiscount)
  const netAfterLineDiscounts = weights.reduce((acc, w) => acc + w, 0n)
  const saleDiscount = resolveDiscount(input.saleDiscount, netAfterLineDiscounts)
  const shares = distribute(saleDiscount, weights)

  lines.forEach((line, index) => {
    line.saleDiscountShare = shares[index] ?? 0n
  })

  // ── 3. Impuestos, línea por línea ────────────────────────────────────────
  input.lines.forEach((source, index) => {
    const line = lines[index]
    if (!line) return

    const amount = line.gross - line.lineDiscount - line.saleDiscountShare
    const rates = source.taxes ?? []

    // Exento es del bien o del comprador. Que no haya impuesto configurado, o
    // que el negocio sea de cuota fija, es otra cosa: no gravado.
    line.exempt = source.exempt === true || customerExempt

    const untaxed = fixedQuota || line.exempt || rates.length === 0

    if (untaxed) {
      // Sin impuesto el precio es el precio, venga incluido o excluido: no hay
      // nada que extraer ni que agregar.
      line.taxableBase = amount
      line.taxTotal = 0n
      line.total = amount
      return
    }

    // Basta con que un impuesto de la línea se declare `gross` para que el
    // precio traiga impuesto dentro: mezclarlos en una misma línea es un error
    // de configuración, no un caso a soportar.
    if (rates.some((tax) => tax.base === 'gross')) {
      applyIncludedTaxes(line, amount, rates)
    } else {
      applyExcludedTaxes(line, amount, rates)
    }
  })

  // ── 4. Totales del ticket ────────────────────────────────────────────────
  const gross = sum(lines.map((l) => l.gross))
  const lineDiscountTotal = sum(lines.map((l) => l.lineDiscount))
  const subtotal = sum(lines.map((l) => l.taxableBase))
  const exemptTotal = sum(lines.filter((l) => l.exempt).map((l) => l.taxableBase))
  const taxableBase = subtotal - exemptTotal
  const taxTotal = sum(lines.map((l) => l.taxTotal))
  const total = subtotal + taxTotal

  // ── 5. Redondeo de efectivo y cobro ──────────────────────────────────────
  const cashTotal = applyCashRounding(total, config.cashRounding)
  const payments = input.payments ?? []

  // "Total en efectivo redondeado; total en otros medios sin redondear" (B-09).
  // El redondeo solo rige cuando el ticket entero se salda en efectivo: en
  // cuanto entra una tarjeta, el importe exacto es cobrable y redondear sería
  // regalar o cobrar de más sin motivo.
  const allCash = payments.length === 0 || payments.every((p) => p.method === 'cash')

  // La propina se suma **después** del redondeo y fuera de todo importe fiscal
  // (G-16). No es venta: no la cobra el negocio para sí, no lleva impuesto y no
  // viaja al ERP. Meterla en `total` la volvería base gravada y `tax_difference`
  // dejaría de ser cero en cada cuenta de restaurante.
  const tip = parse(input.tip ?? '0', SCALE.money)
  const due = (allCash ? cashTotal : total) + tip

  const paid = sum(payments.map(toBaseCurrency(config.currency)))

  // Venta y devolución no se leen igual. En una venta el saldo es lo que falta
  // cobrar y el sobrante es vuelto; en una devolución el importe es negativo y
  // lo pendiente es **entregar** dinero, que sale como saldo negativo. Sin esa
  // distinción una devolución sin pago registrado mostraría un vuelto enorme
  // en la pantalla del cajero.
  const signed = due - paid
  const isRefund = due < 0n
  const change = isRefund || signed >= 0n ? 0n : -signed
  const balance = isRefund ? signed : signed > 0n ? signed : 0n

  return {
    lines: lines.map(renderLine),
    gross: format(gross, SCALE.money),
    lineDiscountTotal: format(lineDiscountTotal, SCALE.money),
    saleDiscount: format(saleDiscount, SCALE.money),
    discountTotal: format(lineDiscountTotal + saleDiscount, SCALE.money),
    subtotal: format(subtotal, SCALE.money),
    taxableBase: format(taxableBase, SCALE.money),
    exemptTotal: format(exemptTotal, SCALE.money),
    taxes: aggregateTaxes(lines),
    taxTotal: format(taxTotal, SCALE.money),
    total: format(total, SCALE.money),
    cashRounding: format(cashTotal - total, SCALE.money),
    cashTotal: format(cashTotal, SCALE.money),
    tip: format(tip, SCALE.money),
    due: format(due, SCALE.money),
    paid: format(paid, SCALE.money),
    change: format(change, SCALE.money),
    balance: format(balance, SCALE.money)
  }
}

/**
 * Impuesto excluido: se agrega sobre la base. Cada impuesto se redondea por su
 * cuenta — son tributos distintos y cada uno se declara por separado.
 */
function applyExcludedTaxes(line: WorkingLine, amount: bigint, rates: readonly TaxRate[]): void {
  line.taxableBase = amount

  for (const tax of rates) {
    const rate = parse(tax.rate, SCALE.rate)
    line.taxes.push({
      code: tax.code,
      rate,
      base: amount,
      amount: mulShift(amount, rate, SHIFT_RATE)
    })
  }

  line.taxTotal = sum(line.taxes.map((t) => t.amount))
  line.total = amount + line.taxTotal
}

/**
 * Impuesto incluido: hay que extraerlo del precio.
 *
 * Con varias tasas se extrae **una sola vez** contra la suma de tasas y después
 * se reparte por resto mayor. Extraer una por una dejaría un residuo que no
 * pertenece a ningún impuesto y que descuadra el libro de ventas.
 */
function applyIncludedTaxes(line: WorkingLine, amount: bigint, rates: readonly TaxRate[]): void {
  const parsed = rates.map((tax) => ({ code: tax.code, rate: parse(tax.rate, SCALE.rate) }))
  const rateSum = sum(parsed.map((t) => t.rate))

  const base = divRoundHalfUp(amount * RATE_ONE, RATE_ONE + rateSum)
  const taxTotal = amount - base
  const split = distribute(taxTotal, parsed.map((t) => t.rate))

  line.taxableBase = base
  line.taxes = parsed.map((tax, index) => ({
    code: tax.code,
    rate: tax.rate,
    base,
    amount: split[index] ?? 0n
  }))
  line.taxTotal = taxTotal
  line.total = amount
}

/** Porcentaje sobre una base, o monto fijo. El monto nunca supera la base. */
function resolveDiscount(discount: Discount | null | undefined, base: bigint): bigint {
  if (!discount) return 0n

  if (discount.type === 'percent') {
    return mulShift(base, parse(discount.value, SCALE.rate), SHIFT_RATE)
  }

  const amount = parse(discount.value, SCALE.money)
  // Un descuento mayor que el importe convertiría la venta en un pago al
  // cliente. Se acota en vez de fallar: en caja, un tope silencioso es mejor
  // que un error que detiene la fila.
  return abs(amount) > abs(base) ? base : amount
}

function applyCashRounding(total: bigint, rounding: SaleInput['config']['cashRounding']): bigint {
  if (!rounding || rounding.mode === 'none') return total

  const increment = parse(rounding.increment, SCALE.money)
  if (increment <= 0n) return total

  const negative = total < 0n
  const magnitude = negative ? -total : total
  const remainder = magnitude % increment

  let rounded: bigint
  if (remainder === 0n) {
    rounded = magnitude
  } else if (rounding.mode === 'up') {
    rounded = magnitude - remainder + increment
  } else if (rounding.mode === 'down') {
    rounded = magnitude - remainder
  } else {
    rounded = remainder * 2n >= increment
      ? magnitude - remainder + increment
      : magnitude - remainder
  }

  return negative ? -rounded : rounded
}

/**
 * Convierte un pago a moneda base (Q-06).
 *
 * El dólar en caja es rutina en Nicaragua: se recibe en dólares, se cobra a la
 * tasa del día y el vuelto sale en córdobas. La tasa viaja con el pago porque
 * se persiste con él.
 */
function toBaseCurrency(baseCurrency: string) {
  return (payment: Payment): bigint => {
    const amount = parse(payment.amount, SCALE.money)
    const currency = payment.currency ?? baseCurrency

    if (currency === baseCurrency) return amount

    if (payment.rate === undefined || payment.rate === null) {
      throw new Error(
        `El pago en ${currency} no trae tipo de cambio y la moneda base es ${baseCurrency}.`
      )
    }

    return mulShift(amount, parse(payment.rate, SCALE.fx), SCALE.fx)
  }
}

/** Desglose de impuestos del ticket, agrupado por código y en orden de aparición. */
function aggregateTaxes(lines: readonly WorkingLine[]): TaxAmount[] {
  const order: string[] = []
  const totals = new Map<string, { rate: bigint, base: bigint, amount: bigint }>()

  for (const line of lines) {
    for (const tax of line.taxes) {
      const current = totals.get(tax.code)
      if (current) {
        current.base += tax.base
        current.amount += tax.amount
      } else {
        order.push(tax.code)
        totals.set(tax.code, { rate: tax.rate, base: tax.base, amount: tax.amount })
      }
    }
  }

  return order.map((code) => {
    const entry = totals.get(code)!
    return {
      code,
      rate: format(entry.rate, SCALE.rate),
      base: format(entry.base, SCALE.money),
      amount: format(entry.amount, SCALE.money)
    }
  })
}

function renderLine(line: WorkingLine): LineResult {
  return {
    id: line.id,
    gross: format(line.gross, SCALE.money),
    lineDiscount: format(line.lineDiscount, SCALE.money),
    saleDiscountShare: format(line.saleDiscountShare, SCALE.money),
    discountTotal: format(line.lineDiscount + line.saleDiscountShare, SCALE.money),
    taxableBase: format(line.taxableBase, SCALE.money),
    taxes: line.taxes.map((tax) => ({
      code: tax.code,
      rate: format(tax.rate, SCALE.rate),
      base: format(tax.base, SCALE.money),
      amount: format(tax.amount, SCALE.money)
    })),
    taxTotal: format(line.taxTotal, SCALE.money),
    total: format(line.total, SCALE.money)
  }
}

function sum(values: readonly bigint[]): bigint {
  return values.reduce((acc, value) => acc + value, 0n)
}
