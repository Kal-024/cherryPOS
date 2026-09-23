import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { GRID, snap, useFloorCanvas } from './useFloorCanvas'

/**
 * El lienzo del plano.
 *
 * Lo que se prueba es la aritmética, que es donde un editor de este tipo falla
 * de forma difícil de ver: una mesa que cae desplazada del punto donde la
 * soltaron, o un acercamiento que se va a cero y deja la pantalla en blanco.
 */

function surfaceAt(left: number, top: number) {
  return ref({
    getBoundingClientRect: () => ({ left, top, width: 800, height: 600 })
  } as unknown as HTMLElement)
}

/** El gesto que empieza en el fondo: el que mueve la vista. */
function pointerDown(): PointerEvent {
  const fondo = { setPointerCapture: () => undefined }

  return {
    target: fondo,
    currentTarget: fondo,
    clientX: 0,
    clientY: 0,
    pointerId: 1
  } as unknown as PointerEvent
}

/** El que empieza sobre una mesa: la vista no se toca. */
function pointerDownOnChild(): PointerEvent {
  return {
    target: { setPointerCapture: () => undefined },
    currentTarget: { setPointerCapture: () => undefined },
    clientX: 0,
    clientY: 0,
    pointerId: 1
  } as unknown as PointerEvent
}

describe('snap', () => {
  it('lleva la posición al punto de rejilla más cercano', () => {
    expect(snap(0)).toBe(0)
    expect(snap(9)).toBe(0)
    expect(snap(11)).toBe(GRID)
    expect(snap(51)).toBe(GRID * 3)
  })

  it('dos mesas alineadas a ojo terminan exactamente alineadas', () => {
    // Es el motivo de que exista: sin esto quedan a tres píxeles y el plano se
    // ve torcido en la pantalla que hay que leer desde lejos.
    expect(snap(198)).toBe(snap(203))
  })
})

describe('useFloorCanvas', () => {
  it('traduce el punto de la pantalla a coordenadas del plano', () => {
    const canvas = useFloorCanvas(surfaceAt(100, 50))

    expect(canvas.toFloor(140, 90)).toEqual({ x: 40, y: 40 })
  })

  it('descuenta el desplazamiento y la escala, en ese orden', () => {
    const canvas = useFloorCanvas(surfaceAt(0, 0))

    canvas.zoomBy(1) // 2x, el tope
    canvas.startPan(pointerDown())
    canvas.movePan({ clientX: 60, clientY: 20 } as PointerEvent)

    // Con la vista corrida 60 y al doble de tamaño, el píxel 160 de pantalla es
    // el 50 del plano. Equivocar el orden deja la mesa lejos del dedo.
    expect(canvas.toFloor(160, 120)).toEqual({ x: 50, y: 50 })
  })

  it('el acercamiento tiene tope por arriba y por abajo', () => {
    const canvas = useFloorCanvas(surfaceAt(0, 0))

    for (let i = 0; i < 40; i++) canvas.zoomBy(-0.1)
    expect(canvas.zoom.value).toBeGreaterThan(0)

    for (let i = 0; i < 80; i++) canvas.zoomBy(0.1)
    expect(canvas.zoom.value).toBeLessThanOrEqual(2)
  })

  it('volver al origen deshace vista y escala', () => {
    const canvas = useFloorCanvas(surfaceAt(0, 0))

    canvas.zoomBy(0.5)
    canvas.startPan(pointerDown())
    canvas.movePan({ clientX: 200, clientY: 120 } as PointerEvent)

    canvas.reset()

    expect(canvas.zoom.value).toBe(1)
    expect(canvas.panX.value).toBe(0)
    expect(canvas.panY.value).toBe(0)
  })

  it('arrastrar sobre una mesa no mueve la vista', () => {
    const canvas = useFloorCanvas(surfaceAt(0, 0))

    // El gesto empezó sobre un hijo, no sobre el fondo: lo que se mueve es la
    // mesa. Sin esta distinción, colocar una mesa arrastraría todo el plano.
    canvas.startPan(pointerDownOnChild())
    canvas.movePan({ clientX: 200, clientY: 120 } as PointerEvent)

    expect(canvas.panX.value).toBe(0)
  })
})
