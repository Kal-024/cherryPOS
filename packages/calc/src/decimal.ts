/**
 * Aritmética decimal exacta sobre enteros escalados.
 *
 * El motor de cálculo existe dos veces —aquí en TypeScript y en
 * `apps/api/app/Services/Calc` en PHP— y la única forma de que no diverjan es
 * que ninguno de los dos use coma flotante en ningún punto. Un `0.1 + 0.2` de
 * JavaScript contra un `bcadd` de PHP produce el centavo que después aparece
 * cuadrando una caja.
 *
 * Todo importe viaja como cadena decimal en la frontera (fixtures, API, JSON) y
 * vive como `bigint` escalado dentro. `bigint` no desborda: el equivalente PHP
 * usa BCMath por el mismo motivo, porque `qty × precio` a escala 8 supera
 * `PHP_INT_MAX` con valores todavía plausibles de ferretería.
 */

/** Escalas fijas del sistema. Cambiar una es cambiar el contrato con PHP. */
export const SCALE = {
  /** Importes de dinero: al centavo. */
  money: 2,
  /** Cantidades: 4 decimales, como `decimal:4` de cherryB. */
  qty: 4,
  /** Precio unitario: 4 decimales. */
  price: 4,
  /** Tasas de impuesto y porcentajes de descuento: 4 decimales. */
  rate: 4,
  /** Tipo de cambio: 6 decimales. */
  fx: 6
} as const

export type Scale = (typeof SCALE)[keyof typeof SCALE]

/** Valor aceptado en la frontera. Nunca `number`: perdería exactitud. */
export type DecimalInput = string | number | bigint

const POW10: bigint[] = Array.from({ length: 19 }, (_, i) => 10n ** BigInt(i))

function pow10(n: number): bigint {
  const cached = POW10[n]
  if (cached !== undefined) return cached
  return 10n ** BigInt(n)
}

/**
 * Convierte una cadena decimal a entero escalado.
 *
 * Lanza si el valor trae más decimales de los que la escala admite, en vez de
 * redondear en silencio: un fixture con un decimal de más es un error del
 * fixture, y descubrirlo aquí cuesta un segundo — descubrirlo en un arqueo,
 * semanas.
 */
export function parse(value: DecimalInput, scale: number): bigint {
  if (typeof value === 'bigint') return value * pow10(scale)

  const raw = typeof value === 'number' ? numberToString(value) : value.trim()

  if (!/^[+-]?\d+(\.\d+)?$/.test(raw)) {
    throw new Error(`Valor decimal inválido: ${JSON.stringify(value)}`)
  }

  const negative = raw.startsWith('-')
  const unsigned = raw.replace(/^[+-]/, '')
  const [intPart = '0', fracPart = ''] = unsigned.split('.')

  if (fracPart.length > scale) {
    throw new Error(
      `"${raw}" tiene ${fracPart.length} decimales y la escala admite ${scale}. ` +
        'Redondear en la frontera es responsabilidad de quien produce el dato.'
    )
  }

  const padded = fracPart.padEnd(scale, '0')
  const magnitude = BigInt(intPart + padded)

  return negative ? -magnitude : magnitude
}

/**
 * `number` solo se acepta por comodidad en pruebas. Se rechaza todo lo que no
 * sea representable de forma exacta para que nadie meta un float por descuido.
 */
function numberToString(value: number): string {
  if (!Number.isFinite(value)) throw new Error(`Valor decimal inválido: ${value}`)
  const asString = String(value)
  if (asString.includes('e') || asString.includes('E')) {
    throw new Error(`Notación exponencial no admitida: ${value}. Usar cadena decimal.`)
  }
  return asString
}

/** Entero escalado a cadena decimal con exactamente `scale` decimales. */
export function format(value: bigint, scale: number): string {
  const negative = value < 0n
  const digits = (negative ? -value : value).toString().padStart(scale + 1, '0')
  const cut = digits.length - scale
  const intPart = digits.slice(0, cut)
  const fracPart = digits.slice(cut)
  const body = scale === 0 ? intPart : `${intPart}.${fracPart}`
  return negative ? `-${body}` : body
}

/**
 * División con redondeo de medio hacia arriba **alejándose del cero**
 * (`half-up`, equivalente a `ROUND_HALF_UP` de PHP).
 *
 * Es la única regla de redondeo del sistema. Que sea simétrica importa: sin eso
 * una devolución no sería el negativo exacto de su venta, y la nota de crédito
 * dejaría un centavo colgando.
 */
export function divRoundHalfUp(numerator: bigint, denominator: bigint): bigint {
  if (denominator === 0n) throw new Error('División por cero en el motor de cálculo')

  const negative = numerator < 0n !== denominator < 0n
  const absNum = numerator < 0n ? -numerator : numerator
  const absDen = denominator < 0n ? -denominator : denominator

  const quotient = absNum / absDen
  const remainder = absNum % absDen
  const rounded = remainder * 2n >= absDen ? quotient + 1n : quotient

  return negative ? -rounded : rounded
}

/** `a × b / 10^shift`, redondeado half-up. El caballo de batalla del motor. */
export function mulShift(a: bigint, b: bigint, shift: number): bigint {
  return divRoundHalfUp(a * b, pow10(shift))
}

/** Reescala un entero de `from` decimales a `to`, redondeando half-up. */
export function rescale(value: bigint, from: number, to: number): bigint {
  if (to >= from) return value * pow10(to - from)
  return divRoundHalfUp(value, pow10(from - to))
}

/** Valor absoluto. */
export function abs(value: bigint): bigint {
  return value < 0n ? -value : value
}

/**
 * Reparte `total` entre `weights` de forma proporcional, en enteros y **sin
 * perder ni un centavo**: el método del resto mayor.
 *
 * Se usa para bajar el descuento de venta a las líneas. Repartir con redondeo
 * independiente por línea deja un descuadre de céntimos entre la suma de las
 * partes y el descuento que el cajero tecleó; el resto mayor garantiza que
 * `sum(resultado) === total`.
 *
 * El desempate es por **orden de línea**, nunca por valor: es lo que hace que
 * PHP y TypeScript produzcan la misma asignación ante dos líneas idénticas.
 */
export function distribute(total: bigint, weights: readonly bigint[]): bigint[] {
  const count = weights.length
  if (count === 0) return []

  const weightSum = weights.reduce((acc, w) => acc + w, 0n)

  // Sin peso donde apoyarse (todas las líneas en cero) el reparto proporcional
  // no está definido: se carga todo a la primera línea, que es el único
  // resultado estable y reproducible.
  if (weightSum === 0n) {
    const result = new Array<bigint>(count).fill(0n)
    result[0] = total
    return result
  }

  const negative = total < 0n
  const magnitude = negative ? -total : total

  const base: bigint[] = []
  const remainders: { index: number, value: bigint }[] = []
  let assigned = 0n

  for (let i = 0; i < count; i++) {
    const weight = weights[i] ?? 0n
    const product = magnitude * weight
    const share = product / weightSum
    base.push(share)
    remainders.push({ index: i, value: product % weightSum })
    assigned += share
  }

  let leftover = magnitude - assigned

  remainders.sort((a, b) => {
    if (a.value === b.value) return a.index - b.index
    return a.value > b.value ? -1 : 1
  })

  for (const { index } of remainders) {
    if (leftover <= 0n) break
    base[index] = (base[index] ?? 0n) + 1n
    leftover -= 1n
  }

  return negative ? base.map((v) => -v) : base
}
