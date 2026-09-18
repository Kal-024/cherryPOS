<?php

/**
 * Configuración de arranque del punto de venta.
 *
 * Solo lo que el proceso necesita **antes** de poder leer la base: el resto de
 * la configuración vive en `cmn_settings`, tipada y jerárquica —negocio →
 * sucursal → terminal— tal como decidió D-12.
 */
return [
    /** Identidad de esta instalación. Una instalación, una sucursal (P-05). */
    'branch_code' => env('POS_BRANCH_CODE', '001'),
    'branch_name' => env('POS_BRANCH_NAME', 'Sucursal principal'),
    'is_headquarters' => env('POS_IS_HEADQUARTERS', true),

    /**
     * Perfil de negocio activo (A-04).
     *
     * Preconfigura módulos, atributos de producto, vocabulario y pantalla según
     * el rubro. Es lo que convierte "un POS configurable" en "el POS para
     * farmacias".
     */
    'business_profile' => env('POS_BUSINESS_PROFILE', 'retail'),

    /*
     * Minutos tras los que el pase marca una comanda como atrasada (G-16).
     *
     * Es un umbral de atención, no una regla: sirve para que el KDS destaque lo
     * que lleva demasiado. Cero lo apaga.
     */
    'kitchen_late_minutes' => (int) env('POS_KITCHEN_LATE_MINUTES', 15),

    /** Monedas de caja (Q-06). El dólar en Nicaragua es operación diaria. */
    'base_currency' => env('POS_BASE_CURRENCY', 'NIO'),
    'secondary_currency' => env('POS_SECONDARY_CURRENCY', 'USD'),

    /**
     * Cómo se verifica el PIN sin conexión (§7 del contrato).
     *
     *   session_grant — se valida en línea y la concesión dura el turno. No
     *                   permite relevo de cajero sin red.
     *   cached_hash   — la terminal cachea los hashes. Permite el relevo
     *                   offline a cambio de exponerlos en el equipo.
     */
    'offline_pin_mode' => env('POS_OFFLINE_PIN_MODE', 'session_grant'),

    /** Ventana máxima sin sincronizar, en horas (H6.5). */
    'offline_max_hours' => (int) env('POS_OFFLINE_MAX_HOURS', 72),

    /** Exigir turno abierto para vender (G-04). */
    'require_shift' => env('POS_REQUIRE_SHIFT', true),

    /**
     * Límite diario de ítems temporales y ventas por monto, por cajero (D-03).
     * Superado, la operación exige autorización con PIN de supervisor — que es
     * **otro PIN**, distinto del de sesión (P-11).
     */
    'temporary_item_daily_limit' => (int) env('POS_TEMP_ITEM_DAILY_LIMIT', 5),

    /**
     * Método de costeo (Q-02, P3).
     *
     * Configurable, no cableado: manda el sistema que se instaló primero. Con
     * ERP presente se adopta el suyo; sin ERP, rige este y el ERP se alinea al
     * integrarse.
     *
     * Mismo vocabulario que el ERP: `AVG` o `FIFO`. El FIFO por capas todavía no
     * está implementado en el POS.
     */
    'costing_method' => env('POS_COSTING_METHOD', 'AVG'),

    /**
     * Impuesto dentro del precio de catálogo.
     *
     * **Nicaragua: sí.** El precio de góndola es lo que paga el cliente y el
     * motor extrae el IVA al facturar. Ponerlo al revés hace que cada precio
     * cargado signifique otra cosa, y eso se descubre con el catálogo entero
     * adentro.
     */
    'tax_included_in_price' => env('POS_TAX_INCLUDED', true),

    /**
     * Redondeo de efectivo (B-09).
     *
     * Apagado por defecto: se cobra el importe exacto. El mecanismo está
     * construido y probado por si un cliente lo pide —la moneda más chica del
     * país es de 0,25 córdobas— pero encenderlo es una decisión del negocio.
     */
    'cash_rounding_mode' => env('POS_CASH_ROUNDING_MODE', 'none'),
    'cash_rounding_increment' => env('POS_CASH_ROUNDING_INCREMENT', '0.25'),

    /**
     * La devolución exige el ticket original.
     *
     * No es una preferencia: el ERP rechaza la nota de crédito con
     * `refund_original_missing` si el documento original no existe o no está
     * emitido (§6 del contrato). Permitir devolver sin ticket sería fabricar
     * excepciones a mano.
     */
    'refund_requires_original' => env('POS_REFUND_REQUIRES_ORIGINAL', true),

    /**
     * Códigos de barras con peso o precio embebidos (B-04).
     *
     * Los emiten las balanzas de mostrador. El prefijo 2x está reservado por
     * GS1 justamente para uso interno del comercio, así que no choca con ningún
     * código de fabricante.
     *
     * `divisor` lleva el número embebido a su unidad real: una balanza que
     * imprime gramos usa 1000 para dar kilos; una que imprime centavos usa 100.
     * Es lo primero que hay que ajustar con una balanza nueva.
     */
    'barcode_patterns' => [
        [
            'kind' => 'weight',
            'prefix' => '2',
            'length' => 13,
            'value_from' => 7,
            'value_length' => 5,
            'divisor' => 1000,
        ],
        [
            'kind' => 'price',
            'prefix' => '2',
            'length' => 13,
            'value_from' => 7,
            'value_length' => 5,
            'divisor' => 100,
        ],
    ],

    /**
     * Envío de comprobantes por WhatsApp (D-10, Q-11).
     *
     * Se configura **por instalación** y la cuenta va a nombre del negocio.
     * Vacío = canal no disponible: la cola marca el envío como `unconfigured` y
     * no lo reintenta, porque reintentar no consigue una cuenta.
     *
     * `provider` está vacío a propósito mientras siga abierto el punto A5.
     */
    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER'),
        'base_url' => env('WHATSAPP_BASE_URL'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],

    /** Integración con cherryERP. Vacío = el POS opera solo (P-01, P4). */
    'erp' => [
        'base_url' => env('ERP_BASE_URL'),
        'token' => env('ERP_TOKEN'),
        'company_id' => env('ERP_COMPANY_ID'),
    ],
];
