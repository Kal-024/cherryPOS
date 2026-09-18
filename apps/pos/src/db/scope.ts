/**
 * Dueño de cada dato guardado en el equipo.
 *
 * Un mismo navegador puede ser el mostrador un rato y el salón otro: la tablet
 * que de día atiende mesas y de noche cobra, el equipo de repuesto que entra
 * como la caja que se rompió. Lo que se guarda en disco no es del navegador —es
 * **de la terminal**— y sin decirlo en la clave, cambiar de caja atribuía el
 * carrito y los correlativos a la equivocada.
 *
 * Tres cosas llevan dueño: la venta en curso, la bandeja de tickets sin enviar y
 * el bloque de números reservados. El catálogo no: es de la sucursal, y volver a
 * bajar tres mil productos en cada cambio sería peor que el problema original.
 *
 * **La credencial no se guarda por terminal.** Se conservan los datos, no el
 * token: volver al mostrador exige su código y su secreto, igual que la
 * activación de cada día. Es lo que mantiene que un equipo perdido deje de
 * servir apenas se desactiva.
 */

/** Donde `useTerminal` deja la terminal activa. Se lee directo para no crear un ciclo. */
const TERMINAL_KEY = 'cherrypos.terminal.info'

export function terminalPrefix(terminalId: string): string {
  return `t:${terminalId}:`
}

/**
 * La terminal activa, o `null`.
 *
 * Se lee de `localStorage` en vez de importar `useTerminal` a propósito: ese
 * módulo importa `idb`, y el ciclo dejaría a los singletons a medio inicializar
 * justo cuando la caja arranca.
 */
export function activeTerminalId(): string | null {
  try {
    const raw = localStorage.getItem(TERMINAL_KEY)

    return raw ? ((JSON.parse(raw) as { id?: string }).id ?? null) : null
  } catch {
    // Un `localStorage` corrupto no puede impedir que la caja abra.
    return null
  }
}

/**
 * La clave con su dueño.
 *
 * Sin terminal activa devuelve la clave tal cual: en la pantalla de activación
 * no hay nada que guardar, y ponerle un prefijo vacío solo crearía una tercera
 * forma de nombrar lo mismo.
 */
export function scoped(key: string): string {
  const terminal = activeTerminalId()

  return terminal ? terminalPrefix(terminal) + key : key
}

/** ¿Esta clave es de esta terminal? */
export function belongsTo(key: string, terminalId: string): boolean {
  return key.startsWith(terminalPrefix(terminalId))
}

/**
 * De qué terminal es una clave, si lo dice.
 *
 * Devuelve `null` para las claves sin prefijo, que son las de una versión
 * anterior a que el estado tuviera dueño.
 */
export function ownerOf(key: string): string | null {
  const match = /^t:([^:]+):/.exec(key)

  return match ? (match[1] ?? null) : null
}
