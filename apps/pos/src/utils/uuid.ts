/**
 * UUID v7 generado en el terminal.
 *
 * El identificador de la venta lo crea la caja, **sin consultar al servidor**
 * (D-21, precondición 1). De eso dependen dos cosas: el modo degradado —una
 * venta tiene que poder existir sin red— y la idempotencia del ERP, que usa ese
 * mismo UUID como clave del documento.
 *
 * v7 y no v4 porque lleva el instante adentro: ordena por creación sin columna
 * extra y no fragmenta los índices como lo haría un identificador aleatorio en
 * una tabla que crece un ticket por minuto. Es el mismo formato que genera
 * `Str::uuid7()` en el backend.
 */
export function uuid7(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)

  const timestamp = BigInt(Date.now())

  // 48 bits de milisegundos desde la época, big-endian.
  for (let i = 0; i < 6; i++) {
    bytes[i] = Number((timestamp >> BigInt(8 * (5 - i))) & 0xffn)
  }

  // Versión 7 en los cuatro bits altos del séptimo byte.
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x70
  // Variante RFC 4122 en los dos bits altos del noveno.
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20)
  ].join('-')
}
