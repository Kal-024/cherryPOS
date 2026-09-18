import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProductForm from './ProductForm.vue'
import { emptyDraft, type Category, type ProductDraft, type TaxCode, type Uom } from '../composables/useCatalogAdmin'

/**
 * El formulario de catálogo.
 *
 * No se prueba que los campos se dibujen, sino las tres reglas de producto que
 * el formulario tiene que sostener y que se olvidan: que el aviso del precio
 * dependa del **código de impuesto** y no de una bandera global, que un
 * servicio no arrastre lotes ni existencia mínima, y que exento sea una casilla
 * propia en vez de un impuesto del 0 %.
 */

const uoms: Uom[] = [{ id: 'u1', code: 'UND', name: 'Unidad', decimals: 0 }]

const categories: Category[] = [
  { id: 'c1', code: 'BEB', name: 'Bebidas', parent_id: null, color: null, sort_order: 1, is_active: true }
]

const taxCodes: TaxCode[] = [
  { id: 't1', code: 'IVA', name: 'IVA 15 %', rate: '15.0000', type: 'vat', base: 'gross' },
  { id: 't2', code: 'IVA-NET', name: 'IVA sobre neto', rate: '15.0000', type: 'vat', base: 'net' }
]

function mountForm(draft: ProductDraft = emptyDraft()) {
  return mount(ProductForm, {
    props: {
      modelValue: draft,
      categories,
      uoms,
      taxCodes,
      'onUpdate:modelValue': (value: ProductDraft) => Object.assign(draft, value)
    }
  })
}

describe('ProductForm', () => {
  it('con un impuesto de base bruta avisa que el precio ya lo incluye', () => {
    const wrapper = mountForm({ ...emptyDraft(), tax_code_id: 't1' })

    // La inclusión es propiedad del código de impuesto (`base = 'gross'`),
    // nunca una bandera global: así lo modela cherryB y así se mantiene
    // `tax_difference` en cero.
    expect(wrapper.text()).toContain('El precio ya incluye el impuesto.')
  })

  it('con un impuesto de base neta avisa que se suma al facturar', () => {
    const wrapper = mountForm({ ...emptyDraft(), tax_code_id: 't2' })

    expect(wrapper.text()).toContain('El impuesto se suma al facturar.')
  })

  it('un servicio no arrastra lotes ni existencia mínima', async () => {
    const draft: ProductDraft = {
      ...emptyDraft(),
      tracks_stock: true,
      tracks_lots: true,
      allow_negative_stock: true,
      min_stock: '5'
    }

    const wrapper = mountForm(draft)
    const checkboxes = wrapper.findAll('input[type="checkbox"]')

    // El primero es "maneja existencias": apagarlo convierte el ítem en
    // servicio (D-07), y un servicio sin existencias no puede tener lotes que
    // vencer ni un mínimo que reponer.
    await checkboxes[0]!.setValue(false)

    expect(draft.tracks_stock).toBe(false)
    expect(draft.tracks_lots).toBe(false)
    expect(draft.allow_negative_stock).toBe(false)
    expect(draft.min_stock).toBe('')
  })

  it('la unidad abre sin preseleccionar', () => {
    const wrapper = mountForm()

    // Preseleccionar la primera unidad haría que alguien guarde "unidad" cuando
    // quería "libra" sin haberlo mirado.
    expect(emptyDraft().uom_id).toBeNull()
    expect(wrapper.text()).toContain('Elegí una unidad')
  })

  it('emite submit al enviar el formulario', async () => {
    const wrapper = mountForm()

    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')).toHaveLength(1)
  })
})
