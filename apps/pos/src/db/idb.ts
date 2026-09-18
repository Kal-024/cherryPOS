/**
 * Envoltura mínima sobre IndexedDB.
 *
 * Escrita a mano en vez de traer una dependencia porque lo que el POS necesita
 * es poco y muy estable: guardar el catálogo, leerlo entero, y un par de
 * contadores. Una biblioteca de propósito general traería un modelo de consultas
 * que no vamos a usar y una superficie de actualización que sí habría que
 * vigilar en cada instalación en sitio.
 *
 * **Por qué IndexedDB y no localStorage:** el catálogo de una ferretería son
 * miles de productos. `localStorage` es síncrono —bloquea la interfaz mientras
 * serializa— y ronda los 5 MB. IndexedDB es asíncrono y no tiene ese techo.
 */

const DB_NAME = 'cherrypos'
const DB_VERSION = 1

/** Almacenes. Cambiar esta lista exige subir `DB_VERSION`. */
export const STORES = {
  /** Catálogo cacheado para la búsqueda local (D-04). */
  products: 'products',
  /** Cuántas veces usó cada cajero cada producto: el ranking de D-04. */
  usage: 'usage',
  /** Carrito en curso, para que sobreviva al cierre del navegador (D-21). */
  carts: 'carts',
  /** Metadatos de sincronización: hasta cuándo está al día cada cosa. */
  meta: 'meta'
} as const

export type StoreName = (typeof STORES)[keyof typeof STORES]

let connection: Promise<IDBDatabase> | null = null

function open(): Promise<IDBDatabase> {
  if (connection) return connection

  connection = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result

      for (const store of Object.values(STORES)) {
        if (!db.objectStoreNames.contains(store)) {
          db.createObjectStore(store)
        }
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })

  return connection
}

function run<T>(
  store: StoreName,
  mode: IDBTransactionMode,
  work: (store: IDBObjectStore) => IDBRequest<T>
): Promise<T> {
  return open().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const transaction = db.transaction(store, mode)
        const request = work(transaction.objectStore(store))

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
      })
  )
}

export async function get<T>(store: StoreName, key: string): Promise<T | undefined> {
  return run<T | undefined>(store, 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>)
}

export async function put(store: StoreName, key: string, value: unknown): Promise<void> {
  await run(store, 'readwrite', (s) => s.put(value, key))
}

export async function remove(store: StoreName, key: string): Promise<void> {
  await run(store, 'readwrite', (s) => s.delete(key))
}

export async function all<T>(store: StoreName): Promise<T[]> {
  return run<T[]>(store, 'readonly', (s) => s.getAll() as IDBRequest<T[]>)
}

/**
 * Todo el almacén, **con sus claves**.
 *
 * `all()` devuelve solo valores, y eso alcanzaba mientras todo lo guardado era
 * de la única terminal del equipo. Desde que cada dato lleva dueño en la clave
 * —`t:<terminal>:…`— hace falta leerla para saber qué es de quién.
 *
 * Las dos lecturas van en la **misma transacción**: hacerlas en dos abriría la
 * puerta a que entre medio se escriba un ticket y los arreglos dejen de
 * corresponderse.
 */
export async function entries<T>(store: StoreName): Promise<Array<[string, T]>> {
  const db = await open()

  return new Promise((resolve, reject) => {
    const transaction = db.transaction(store, 'readonly')
    const target = transaction.objectStore(store)
    const keys = target.getAllKeys()
    const values = target.getAll()

    transaction.oncomplete = () => resolve(
      (keys.result as IDBValidKey[]).map((key, index) => [String(key), (values.result as T[])[index] as T])
    )
    transaction.onerror = () => reject(transaction.error)
  })
}

export async function clear(store: StoreName): Promise<void> {
  await run(store, 'readwrite', (s) => s.clear())
}

/**
 * Escribe muchos registros en **una sola transacción**.
 *
 * Importa más de lo que parece: sincronizar tres mil productos con una
 * transacción por fila tarda segundos y deja la caja trabada justo cuando abre.
 */
export async function putMany(
  store: StoreName,
  entries: ReadonlyArray<readonly [string, unknown]>
): Promise<void> {
  const db = await open()

  return new Promise((resolve, reject) => {
    const transaction = db.transaction(store, 'readwrite')
    const target = transaction.objectStore(store)

    for (const [key, value] of entries) {
      target.put(value, key)
    }

    transaction.oncomplete = () => resolve()
    transaction.onerror = () => reject(transaction.error)
  })
}

/** ¿Está disponible el almacenamiento local? Un navegador en modo privado puede negarlo. */
export function isAvailable(): boolean {
  return typeof indexedDB !== 'undefined'
}
