import { computed, ref, type Ref } from 'vue'

/**
 * El lienzo del plano: rejilla, desplazamiento y acercamiento.
 *
 * Un plano de salón es un plano, no una lista de botones: el mesero reconoce
 * "la del ventanal" mucho antes que "Mesa 7". Para que eso funcione, las mesas
 * tienen que estar donde están de verdad, y colocarlas pide arrastrarlas.
 *
 * **El ajuste a la rejilla no es cosmético.** Sin él, dos mesas alineadas a ojo
 * quedan a tres píxeles de distancia y el plano se ve desordenado justo en la
 * pantalla que tiene que leerse de un vistazo desde el otro lado del salón.
 *
 * Los gestos son los que cualquiera espera de un lienzo: arrastrar el fondo
 * mueve la vista, la rueda acerca y aleja. Se implementan con eventos de
 * puntero —no de ratón— porque la misma pantalla se usa en la tablet del
 * mesero.
 */

/** Separación de la rejilla, en píxeles del plano. */
export const GRID = 20

const MIN_ZOOM = 0.4
const MAX_ZOOM = 2

export function snap(value: number): number {
  return Math.round(value / GRID) * GRID
}

export function useFloorCanvas(surface: Ref<HTMLElement | null>) {
  const zoom = ref(1)
  const panX = ref(0)
  const panY = ref(0)

  let panning = false
  let startX = 0
  let startY = 0

  const transform = computed(
    () => `translate(${panX.value}px, ${panY.value}px) scale(${zoom.value})`
  )

  /**
   * De coordenadas de pantalla a coordenadas del plano.
   *
   * Es lo que hace que una mesa caiga donde el dedo la soltó y no desplazada:
   * hay que deshacer el desplazamiento y la escala en ese orden.
   */
  function toFloor(clientX: number, clientY: number): { x: number, y: number } {
    const box = surface.value?.getBoundingClientRect()

    if (!box) return { x: 0, y: 0 }

    return {
      x: (clientX - box.left - panX.value) / zoom.value,
      y: (clientY - box.top - panY.value) / zoom.value
    }
  }

  function startPan(event: PointerEvent) {
    // Solo el fondo: si el gesto empezó sobre una mesa, la que se mueve es la
    // mesa.
    if (event.target !== event.currentTarget) return

    panning = true
    startX = event.clientX - panX.value
    startY = event.clientY - panY.value
    // El puntero queda capturado para que soltar fuera del lienzo también
    // termine el gesto. Opcional porque no todo objetivo lo soporta.
    ;(event.currentTarget as HTMLElement)?.setPointerCapture?.(event.pointerId)
  }

  function movePan(event: PointerEvent) {
    if (!panning) return

    panX.value = event.clientX - startX
    panY.value = event.clientY - startY
  }

  function endPan() {
    panning = false
  }

  function zoomBy(delta: number) {
    zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number((zoom.value + delta).toFixed(2))))
  }

  function onWheel(event: WheelEvent) {
    event.preventDefault()
    zoomBy(event.deltaY > 0 ? -0.1 : 0.1)
  }

  /** Vuelve al origen. Perderse en el lienzo es fácil y salir tiene que serlo más. */
  function reset() {
    zoom.value = 1
    panX.value = 0
    panY.value = 0
  }

  return { zoom, panX, panY, transform, toFloor, startPan, movePan, endPan, zoomBy, onWheel, reset }
}
