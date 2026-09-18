import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Clientes desde la trastienda (Q-03, P-03).
 *
 * **Crear clientes es del supervisor, no del cajero**: en caja solo se usan los
 * ya registrados. Por eso esta capa vive en la administración y no en la venta,
 * y por eso el día que se integre el ERP desaparece entera — la creación pasa
 * allá y el POS solo consulta.
 *
 * Las dos clases de cliente son toda la ficha: el de **efectivo** paga y se va
 * —solo nombre, y ni siquiera obligatorio—; el de **cuenta** abre crédito, y
 * ahí la cédula es obligatoria porque la cuenta es personal (G-10).
 */

export interface CreditAccountSummary {
  id: string
  credit_limit: string
  balance: string
  cut_off_day: number
  is_blocked: boolean
  blocked_reason: string | null
}

export interface Customer {
  id: string
  name: string
  kind: 'cash' | 'account'
  code: string | null
  is_tax_exempt: boolean
  tax_exempt_reference: string | null
  discount_percent: string | null
  consent_email: boolean
  consent_whatsapp: boolean
  is_active: boolean
  person?: {
    full_name: string
    national_id: string | null
    tax_id: string | null
    email: string | null
    phone: string | null
    whatsapp: string | null
    address: string | null
  } | null
  credit_account?: CreditAccountSummary | null
}

/** Lo que el formulario edita: persona y rol aplanados, como los recibe la API. */
export interface CustomerDraft {
  name: string
  kind: 'cash' | 'account'
  national_id: string
  tax_id: string
  email: string
  phone: string
  whatsapp: string
  address: string
  code: string
  is_tax_exempt: boolean
  tax_exempt_reference: string
  discount_percent: string
  consent_email: boolean
  consent_whatsapp: boolean
  is_active: boolean
}

export function emptyCustomerDraft(): CustomerDraft {
  return {
    name: '',
    // El caso común es el cliente de efectivo; el de cuenta se elige a
    // conciencia porque arrastra cédula obligatoria y crédito.
    kind: 'cash',
    national_id: '',
    tax_id: '',
    email: '',
    phone: '',
    whatsapp: '',
    address: '',
    code: '',
    is_tax_exempt: false,
    tax_exempt_reference: '',
    discount_percent: '',
    consent_email: false,
    consent_whatsapp: false,
    is_active: true
  }
}

export function customerDraftOf(customer: Customer): CustomerDraft {
  return {
    name: customer.name,
    kind: customer.kind,
    national_id: customer.person?.national_id ?? '',
    tax_id: customer.person?.tax_id ?? '',
    email: customer.person?.email ?? '',
    phone: customer.person?.phone ?? '',
    whatsapp: customer.person?.whatsapp ?? '',
    address: customer.person?.address ?? '',
    code: customer.code ?? '',
    is_tax_exempt: customer.is_tax_exempt,
    tax_exempt_reference: customer.tax_exempt_reference ?? '',
    discount_percent: customer.discount_percent ?? '',
    consent_email: customer.consent_email,
    consent_whatsapp: customer.consent_whatsapp,
    is_active: customer.is_active
  }
}

export function useCustomers() {
  const customers = shallowRef<Customer[]>([])
  const loading = ref(false)
  const saving = ref(false)

  async function list(options: { search?: string, kind?: string | null, includeInactive?: boolean } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (options.search) query.set('search', options.search)
      if (options.kind) query.set('kind', options.kind)
      if (options.includeInactive) query.set('include_inactive', '1')

      customers.value = await apiGetList<Customer>(`/customers?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  async function find(id: string): Promise<Customer> {
    return apiFetch<Customer>(`/customers/${id}`)
  }

  async function create(draft: CustomerDraft): Promise<Customer> {
    saving.value = true

    try {
      return await apiFetch<Customer>('/customers', {
        method: 'POST',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  async function update(id: string, draft: CustomerDraft): Promise<Customer> {
    saving.value = true

    try {
      return await apiFetch<Customer>(`/customers/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  return { customers, loading, saving, list, find, create, update }
}

/**
 * El campo en blanco viaja como `null`.
 *
 * `nullable|email` rechaza la cadena vacía, y un 422 sobre un campo que el
 * supervisor dejó a propósito sin llenar es el peor error posible: el correcto
 * parece incorrecto.
 */
function payload(draft: CustomerDraft): Record<string, unknown> {
  const blank = (value: string) => (value.trim() === '' ? null : value.trim())

  return {
    ...draft,
    name: draft.name.trim(),
    national_id: blank(draft.national_id),
    tax_id: blank(draft.tax_id),
    email: blank(draft.email),
    phone: blank(draft.phone),
    whatsapp: blank(draft.whatsapp),
    address: blank(draft.address),
    code: blank(draft.code),
    tax_exempt_reference: blank(draft.tax_exempt_reference),
    discount_percent: blank(draft.discount_percent)
  }
}
