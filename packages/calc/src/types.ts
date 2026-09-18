/**
 * Contrato del motor de cálculo.
 *
 * Todo importe entra y sale como **cadena decimal**. Nunca `number`: el JSON de
 * un fixture leído como float ya perdió la partida antes de empezar.
 */

/** Descuento por porcentaje o por monto fijo (D-02). */
export type DiscountType = 'percent' | 'amount'

export interface Discount {
  type: DiscountType
  /** Porcentaje (`"10"` = 10 %) o monto en la moneda base. */
  value: string
}

export interface TaxRate {
  /** Código del impuesto tal como lo conoce el ERP (`tax_code`). */
  code: string
  /** Tasa porcentual: `"15"` para el IVA de Nicaragua. */
  rate: string
  /**
   * Sobre qué se calcula, con el mismo vocabulario que `tax_codes.base` de
   * cherryB:
   *
   *  - `net` (por defecto) — el precio no trae impuesto y se le agrega.
   *  - `gross` — el precio ya lo trae dentro y hay que extraerlo.
   *
   * **Es propiedad del impuesto, no de la instalación.** El ERP lo modela así y
   * aquí se copia a propósito: si el POS lo decidiera globalmente y el ERP por
   * código, `tax_difference` saldría distinto de cero en cada ticket.
   */
  base?: 'net' | 'gross'
}

export interface SaleLine {
  id: string
  qty: string
  unitPrice: string
  discount?: Discount | null
  taxes?: readonly TaxRate[]
  /**
   * Producto exento (Q-07). Los medicamentos están exentos de IVA en Nicaragua:
   * sin esta bandera el vertical farmacia no opera.
   */
  exempt?: boolean
}

export interface SaleCustomer {
  /** Cliente exonerado: organismos y regímenes especiales (Q-07). */
  taxExempt?: boolean
}

/**
 * Redondeo de efectivo (B-09).
 *
 * `nearest` redondea al múltiplo más cercano con medio hacia arriba; `up` y
 * `down` se entienden **en valor absoluto** —alejándose o acercándose al cero—
 * para que una devolución sea el negativo exacto de su venta.
 */
export interface CashRounding {
  mode: 'none' | 'nearest' | 'up' | 'down'
  /** Múltiplo al que se redondea, en la moneda base. */
  increment: string
}

export interface SaleConfig {
  /** Moneda base de la instalación. En Nicaragua, `NIO`. */
  currency: string
  /**
   * Régimen de cuota fija (Q-07): el negocio no traslada IVA. Desactiva el
   * impuesto en todo el sistema, no producto por producto.
   */
  fixedQuotaRegime?: boolean
  cashRounding?: CashRounding
}

export type PaymentMethod = 'cash' | 'card' | 'credit' | 'transfer' | 'other'

export interface Payment {
  method: PaymentMethod
  /** Moneda recibida. Si se omite, la base. */
  currency?: string
  amount: string
  /**
   * Tipo de cambio aplicado, obligatorio cuando `currency` no es la base
   * (Q-06). Se persiste con el pago: la tasa de hoy no sirve para releer el
   * ticket de ayer.
   */
  rate?: string
}

export interface SaleInput {
  config: SaleConfig
  customer?: SaleCustomer
  lines: readonly SaleLine[]
  /** Descuento sobre el total, repartido proporcionalmente entre las líneas. */
  saleDiscount?: Discount | null
  /**
   * Propina (G-16). Se cobra con la cuenta y **no se factura**: no entra en la
   * base gravada, no lleva impuesto y no viaja al ERP. El signo lo pone quien
   * arma el ticket — una devolución la devuelve en negativo, igual que sus
   * líneas.
   */
  tip?: string | null
  payments?: readonly Payment[]
}

export interface TaxAmount {
  code: string
  rate: string
  /** Base imponible sobre la que se calculó. */
  base: string
  amount: string
}

export interface LineResult {
  id: string
  /** `cantidad × precio unitario`, antes de cualquier descuento. */
  gross: string
  lineDiscount: string
  /** Parte del descuento de venta que le tocó a esta línea. */
  saleDiscountShare: string
  discountTotal: string
  /** Base imponible: el importe **sin** impuesto, en ambos modos. */
  taxableBase: string
  taxes: TaxAmount[]
  taxTotal: string
  /** `taxableBase + taxTotal`. Lo que esta línea aporta al total del ticket. */
  total: string
}

export interface SaleResult {
  lines: LineResult[]
  gross: string
  lineDiscountTotal: string
  saleDiscount: string
  discountTotal: string
  /**
   * Importe neto de descuentos y **sin** impuesto.
   * `subtotal === taxableBase + exemptTotal`.
   */
  subtotal: string
  /**
   * Base gravada: la parte del subtotal sobre la que se calculó impuesto.
   *
   * Se separa de `exemptTotal` porque **el libro de ventas las declara
   * distinto**, aunque den el mismo total. Es la misma distinción que mantiene
   * `TaxCalculationService` de cherryB.
   */
  taxableBase: string
  /**
   * Importe exento: líneas exentas por naturaleza del bien o por exoneración
   * del comprador (Q-07).
   *
   * Una venta bajo régimen de cuota fija **no** es exenta: es no gravada, y va a
   * `taxableBase` con impuesto cero.
   */
  exemptTotal: string
  taxes: TaxAmount[]
  taxTotal: string
  /** `subtotal + taxTotal`. El total fiscal, nunca redondeado. */
  total: string
  /** Ajuste por redondeo de efectivo: `cashTotal - total`. */
  cashRounding: string
  /** Total a cobrar si todo se paga en efectivo. */
  cashTotal: string
  /** Propina, tal como entró. Fuera del total fiscal y dentro de lo que se cobra. */
  tip: string
  /** Lo que realmente hay que cobrar según los medios usados, propina incluida. */
  due: string
  /** Suma de los pagos, convertidos a moneda base. */
  paid: string
  /** Vuelto, siempre en moneda base (Q-06). Cero en una devolución. */
  change: string
  /**
   * Saldo. Positivo mientras falte cobrar; negativo en una devolución cuando
   * todavía falta entregarle el dinero al cliente. Cero cuando el ticket está
   * saldado, cobre o pague.
   */
  balance: string
}
