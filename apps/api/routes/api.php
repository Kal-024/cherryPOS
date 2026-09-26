<?php

use App\Http\Controllers\Api\CatalogScanController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DenominationController;
use App\Http\Controllers\Api\DiningRoomController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ErpIntegrationController;
use App\Http\Controllers\Api\ErpOutboxController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\KitchenTicketController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\OfflineSaleController;
use App\Http\Controllers\Api\OperatorSessionController;
use App\Http\Controllers\Api\ProductAvailabilityController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReceiptTemplateController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SaleLineController;
use App\Http\Controllers\Api\SalePaymentController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\SupervisorNotificationController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaxCodeController;
use App\Http\Controllers\Api\TerminalAuthController;
use App\Http\Controllers\Api\UomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de cherryPOS
|--------------------------------------------------------------------------
|
| **P5 — API primero.** La interfaz web es un consumidor más, nunca un caso
| privilegiado: toda funcionalidad existe antes como endpoint documentado.
|
| Tres anillos de acceso, que son la doble credencial de D-05 hecha rutas:
|
|   1. **Público** — versión de esquema y autenticación de terminal.
|   2. **Terminal autenticada** (`auth:sanctum`) — puede leer catálogo y
|      configuración, y abrir una sesión de cajero. No puede facturar.
|   3. **Cajero identificado** (`operator`) — todo lo que mueve dinero, con su
|      permiso declarado en la ruta.
*/

Route::get('/system/version', [SystemController::class, 'version']);

// B-15: protección de fuerza bruta. El límite se define en `AppServiceProvider`
// para que la respuesta explique la espera en vez de decir "Too Many Attempts".
Route::post('/terminal/login', [TerminalAuthController::class, 'login'])
    ->middleware('throttle:terminal-login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/terminal/logout', [TerminalAuthController::class, 'logout']);

    // Apertura y cierre de sesión de cajero. El relevo no exige reautenticar la
    // terminal: ese es todo el punto de D-05.
    Route::get('/operator/session', [OperatorSessionController::class, 'show']);
    Route::post('/operator/session', [OperatorSessionController::class, 'store'])
        ->middleware('throttle:operator-session');
    Route::delete('/operator/session', [OperatorSessionController::class, 'destroy']);

    Route::middleware('operator')->group(function () {
        Route::get('/operator/me', [OperatorSessionController::class, 'show']);

        /*
         * Catálogo.
         *
         * En operación normal la caja **no** consulta estos endpoints al vender:
         * el catálogo está cacheado en el terminal y la búsqueda es local (D-04).
         * Son la fuente de esa caché y el respaldo de lo que no tenga.
         */
        Route::get('/catalog/products', [ProductController::class, 'index'])
            ->middleware('permission:catalog.product.read');
        Route::get('/catalog/products/{id}', [ProductController::class, 'show'])
            ->middleware('permission:catalog.product.read');
        Route::post('/catalog/products', [ProductController::class, 'store'])
            ->middleware('permission:catalog.product.create');
        Route::put('/catalog/products/{id}', [ProductController::class, 'update'])
            ->middleware('permission:catalog.product.update');
        Route::delete('/catalog/products/{id}', [ProductController::class, 'destroy'])
            ->middleware('permission:catalog.product.delete');

        Route::get('/catalog/scan', [CatalogScanController::class, 'show'])
            ->middleware('permission:catalog.product.read');

        /*
         * La lista 86: lo que se acabó hoy (F1-B).
         *
         * Leerla es de cualquiera que venda —el mesero necesita ver el plato
         * atenuado—; marcarla es de quien lleva el local. Y `index` devuelve
         * solo identificadores porque el terminal la sondea: bajar el catálogo
         * entero para saber que se acabó el pescado sería absurdo (D-04).
         */
        // Las válvulas de escape del catálogo (D-03): cuántas quedan hoy. La caja
        // lo pregunta al abrir el diálogo, no al sexto intento.
        Route::get('/special-lines/quota', [SaleLineController::class, 'quota'])
            ->middleware('permission:pos_sale.create');

        Route::get('/catalog/unavailable', [ProductAvailabilityController::class, 'index'])
            ->middleware('permission:catalog.product.read');
        Route::post('/catalog/products/{id}/unavailable', [ProductAvailabilityController::class, 'store'])
            ->middleware('permission:catalog.availability');
        Route::delete('/catalog/products/{id}/unavailable', [ProductAvailabilityController::class, 'destroy'])
            ->middleware('permission:catalog.availability');

        /*
         * Referencias del catálogo.
         *
         * Categorías, unidades e impuestos: lo que el formulario de producto
         * necesita para ofrecer opciones en vez de pedir un UUID escrito a
         * mano. Unidades e impuestos son solo lectura —los siembra la
         * instalación y, habiendo ERP, manda él—; las categorías sí se
         * mantienen desde la trastienda, porque son la cuadrícula del perfil
         * táctil.
         */
        Route::get('/catalog/categories', [CategoryController::class, 'index'])
            ->middleware('permission:catalog.product.read');
        Route::post('/catalog/categories', [CategoryController::class, 'store'])
            ->middleware('permission:catalog.product.create');
        Route::put('/catalog/categories/{id}', [CategoryController::class, 'update'])
            ->middleware('permission:catalog.product.update');
        Route::delete('/catalog/categories/{id}', [CategoryController::class, 'destroy'])
            ->middleware('permission:catalog.product.delete');

        Route::get('/catalog/uoms', [UomController::class, 'index'])
            ->middleware('permission:catalog.product.read');
        Route::get('/catalog/tax-codes', [TaxCodeController::class, 'index'])
            ->middleware('permission:catalog.product.read');

        // Inventario. No hay ruta para escribir el stock: solo para sumarle
        // movimientos (B-03).
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:inventory.read');
        Route::get('/inventory/balance', [InventoryController::class, 'balance'])
            ->middleware('permission:inventory.read');
        Route::get('/inventory/balances', [InventoryController::class, 'balances'])
            ->middleware('permission:inventory.read');
        Route::get('/inventory/locations', [LocationController::class, 'index'])
            ->middleware('permission:inventory.read');
        Route::get('/inventory/expired-lots', [InventoryController::class, 'expiredLots'])
            ->middleware('permission:inventory.read');
        Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])
            ->middleware('permission:inventory.adjust');
        Route::post('/inventory/receipts', [InventoryController::class, 'receive'])
            ->middleware('permission:inventory.receive');

        /*
         * Importación y exportación masiva (D-15).
         *
         * Tres pasos, y el del medio es el que OSPOS no tiene: previsualizar,
         * mirar, aplicar. Más `revert` por si el archivo estaba mal.
         */
        Route::get('/imports/kinds', [ImportController::class, 'kinds'])
            ->middleware('permission:catalog.import');
        Route::get('/imports/templates/{kind}', [ImportController::class, 'template'])
            ->middleware('permission:catalog.import');
        Route::post('/imports/{kind}/preview', [ImportController::class, 'preview'])
            ->middleware('permission:catalog.import');
        Route::get('/imports/{id}', [ImportController::class, 'show'])
            ->middleware('permission:catalog.import');
        Route::post('/imports/{id}/apply', [ImportController::class, 'apply'])
            ->middleware('permission:catalog.import');
        Route::post('/imports/{id}/revert', [ImportController::class, 'revert'])
            ->middleware('permission:catalog.import');
        Route::get('/exports/{kind}', [ImportController::class, 'export'])
            ->middleware('permission:catalog.import');

        /*
         * Clientes y crédito (D-09, G-10, Q-03, Q-10).
         *
         * En caja **solo se usan clientes ya registrados**: crearlos es del
         * supervisor. El crédito del POS es la versión reducida — intereses y
         * planes de pago son del módulo del ERP.
         */
        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:customer.read');
        Route::get('/customers/{id}', [CustomerController::class, 'show'])
            ->middleware('permission:customer.read');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('permission:customer.create');
        Route::put('/customers/{id}', [CustomerController::class, 'update'])
            ->middleware('permission:customer.update');

        Route::get('/credit/accounts', [CreditController::class, 'index'])
            ->middleware('permission:credit.read');
        Route::get('/customers/{customer}/credit', [CreditController::class, 'show'])
            ->middleware('permission:credit.read');
        Route::post('/customers/{customer}/credit', [CreditController::class, 'open'])
            ->middleware('permission:customer.update');
        Route::get('/customers/{customer}/credit/statement', [CreditController::class, 'statement'])
            ->middleware('permission:credit.read');
        Route::post('/customers/{customer}/credit/payments', [CreditController::class, 'pay'])
            ->middleware('permission:credit.payment');
        Route::post('/customers/{customer}/credit/authorized', [CreditController::class, 'addAuthorized'])
            ->middleware('permission:customer.update');
        Route::delete('/customers/{customer}/credit/authorized/{authorized}', [CreditController::class, 'removeAuthorized'])
            ->middleware('permission:customer.update');
        Route::post('/customers/{customer}/credit/block', [CreditController::class, 'block'])
            ->middleware('permission:credit.block');
        Route::post('/customers/{customer}/credit/unblock', [CreditController::class, 'unblock'])
            ->middleware('permission:credit.block');

        /*
         * Comprobantes (H5).
         *
         * Líneas para dibujar o imprimir, PDF para entregar, y envío digital
         * **solo a pedido del cliente**, siempre encolado (P-04).
         */
        Route::get('/sales/{sale}/receipt', [ReceiptController::class, 'show'])
            ->middleware('permission:pos_sale.create');
        // La precuenta del salón: lo consumido, sin cobrar. Imprimirla marca la
        // mesa como "cuenta pedida", que es el estado que el mapa necesita.
        Route::get('/sales/{sale}/pre-bill/pdf', [ReceiptController::class, 'preBill'])
            ->middleware('permission:pos_sale.create');
        Route::get('/sales/{sale}/receipt/pdf', [ReceiptController::class, 'pdf'])
            ->middleware('permission:pos_sale.create');
        Route::post('/sales/{sale}/receipt/deliver', [ReceiptController::class, 'deliver'])
            ->middleware('permission:pos_sale.create');
        Route::get('/sales/{sale}/receipt/deliveries', [ReceiptController::class, 'deliveries'])
            ->middleware('permission:pos_sale.create');

        /*
         * Configuración operativa (D-12).
         *
         * Solo lo que el negocio cambia de verdad. Tres de estas claves —el
         * redondeo, la cuota fija y la ventana sin sincronizar— cambian cómo se
         * calcula el dinero, así que la escritura queda en la bitácora.
         */
        Route::get('/settings', [SettingsController::class, 'show'])
            ->middleware('permission:pos_settings.read');
        Route::put('/settings', [SettingsController::class, 'update'])
            ->middleware('permission:pos_settings.update');

        /*
         * Denominaciones del arqueo (D-06): configurables por país, porque no
         * hay dos iguales.
         */
        Route::get('/cash/denominations/all', [DenominationController::class, 'index'])
            ->middleware('permission:pos_settings.read');
        Route::post('/cash/denominations', [DenominationController::class, 'store'])
            ->middleware('permission:pos_settings.update');
        Route::put('/cash/denominations/{id}', [DenominationController::class, 'update'])
            ->middleware('permission:pos_settings.update');
        Route::delete('/cash/denominations/{id}', [DenominationController::class, 'destroy'])
            ->middleware('permission:pos_settings.update');

        /*
         * Editor de plantillas (D-11): la plantilla es dato, no veinte banderas
         * en el código. La vista previa es lo que lo hace usable.
         */
        Route::get('/receipt-templates', [ReceiptTemplateController::class, 'index'])
            ->middleware('permission:pos_settings.read');
        Route::post('/receipt-templates/preview', [ReceiptTemplateController::class, 'preview'])
            ->middleware('permission:pos_settings.read');
        Route::get('/receipt-templates/{id}', [ReceiptTemplateController::class, 'show'])
            ->middleware('permission:pos_settings.read');
        Route::post('/receipt-templates', [ReceiptTemplateController::class, 'store'])
            ->middleware('permission:pos_settings.update');
        Route::put('/receipt-templates/{id}', [ReceiptTemplateController::class, 'update'])
            ->middleware('permission:pos_settings.update');
        Route::delete('/receipt-templates/{id}', [ReceiptTemplateController::class, 'destroy'])
            ->middleware('permission:pos_settings.update');
        Route::post('/receipt-templates/{id}/duplicate', [ReceiptTemplateController::class, 'duplicate'])
            ->middleware('permission:pos_settings.update');

        /*
         * Salón (F1-B, §10).
         *
         * El mapa viaja entero porque la pantalla se refresca por sondeo:
         * preguntar mesa por mesa multiplicaría por treinta el tráfico sin ganar
         * nada en una red local. Configurar el salón es otro permiso que
         * atenderlo.
         */
        Route::get('/dining/map', [DiningRoomController::class, 'map'])
            ->middleware('permission:dining.read');
        Route::post('/dining/tables/{id}/open', [DiningRoomController::class, 'open'])
            ->middleware('permission:dining.serve');
        Route::post('/dining/tables/{id}/merge', [DiningRoomController::class, 'merge'])
            ->middleware('permission:dining.serve');
        Route::post('/dining/tables/{id}/split', [DiningRoomController::class, 'split'])
            ->middleware('permission:dining.serve');
        // La libreta del mesero sobre la cuenta abierta: «cumpleaños»,
        // «apurados». La nota de la línea es otra cosa y viaja a la comanda.
        Route::put('/dining/tables/{id}/note', [DiningRoomController::class, 'note'])
            ->middleware('permission:dining.serve');

        /*
         * Comandas y pantalla de cocina (F1-B).
         *
         * Mandar a cocina es del mesero; avanzar la comanda, de quien está
         * frente al KDS. Son permisos distintos porque el cocinero no tiene un
         * cajero identificado detrás ni las manos libres para un PIN.
         */
        Route::get('/sales/{sale}/kitchen/pending', [KitchenTicketController::class, 'pending'])
            ->middleware('permission:dining.serve');
        Route::post('/sales/{sale}/kitchen/send', [KitchenTicketController::class, 'store'])
            ->middleware('permission:dining.serve');
        /*
         * Marchar el curso que sigue (G-16). Es del salón y no del pase: quien
         * ve que la mesa terminó el fuerte es el mesero, no el cocinero.
         */
        Route::post('/sales/{sale}/kitchen/fire', [KitchenTicketController::class, 'fire'])
            ->middleware('permission:dining.serve');
        Route::get('/kitchen/tickets', [KitchenTicketController::class, 'index'])
            ->middleware('permission:kitchen.display');
        Route::post('/kitchen/tickets/{id}/status', [KitchenTicketController::class, 'advance'])
            ->middleware('permission:kitchen.update');

        Route::post('/dining/areas', [DiningRoomController::class, 'storeArea'])
            ->middleware('permission:dining.manage');
        Route::post('/dining/tables', [DiningRoomController::class, 'storeTable'])
            ->middleware('permission:dining.manage');
        Route::put('/dining/tables/{id}', [DiningRoomController::class, 'updateTable'])
            ->middleware('permission:dining.manage');
        Route::delete('/dining/tables/{id}', [DiningRoomController::class, 'destroyTable'])
            ->middleware('permission:dining.manage');

        /*
         * Modo degradado (H6).
         *
         * `bootstrap` entrega lo que la caja necesita para vender sin servidor;
         * `reserve` aparta el bloque de correlativos para que dos cajas no
         * emitan el mismo número; `offline-sales` recibe lo que se vendió
         * mientras el servidor estuvo caído.
         */
        Route::get('/terminal/bootstrap', [OfflineSaleController::class, 'bootstrap'])
            ->middleware('permission:pos_sale.create');
        Route::post('/terminal/sequence/reserve', [OfflineSaleController::class, 'reserve'])
            ->middleware('permission:pos_sale.create');
        Route::post('/offline-sales', [OfflineSaleController::class, 'store'])
            ->middleware('permission:pos_sale.create');

        /*
         * Sincronización entre terminales (G-13, R-01).
         *
         * Consulta periódica sobre la red local, no WebSockets: la latencia no
         * se nota en una LAN y no hay que operar un servidor de conexiones
         * persistentes en el equipo de cada cliente.
         */
        Route::get('/sync/changes', [SyncController::class, 'changes'])
            ->middleware('permission:pos_sale.create');

        /*
         * Turno de caja y arqueo (G-04, D-06).
         *
         * El turno es del equipo —el cajón es físico— y el corte se desglosa
         * por cajero.
         */
        Route::get('/cash/denominations', [ShiftController::class, 'denominations'])
            ->middleware('permission:pos_shift.open,pos_shift.close');
        Route::get('/shifts/current', [ShiftController::class, 'current'])
            ->middleware('permission:pos_sale.create');
        Route::post('/shifts', [ShiftController::class, 'open'])
            ->middleware('permission:pos_shift.open');
        Route::post('/shifts/close', [ShiftController::class, 'close'])
            ->middleware('permission:pos_shift.close');
        Route::get('/shifts/{id}/summary', [ShiftController::class, 'summary'])
            ->middleware('permission:report.shift_cut');
        /*
         * Cajas cerradas y su descuadre (H4.3).
         *
         * Cuadrar no reescribe el arqueo: asienta un movimiento de caja con su
         * motivo y su autorización. Exige **PIN de supervisor**, distinto del de
         * sesión (P-11), porque mover plata de un arqueo cerrado es donde
         * conviene el segundo freno.
         */
        Route::get('/shifts', [ShiftController::class, 'index'])
            ->middleware('permission:report.shift_cut');
        Route::post('/shifts/{id}/settle', [ShiftController::class, 'settle'])
            ->middleware('permission:pos_shift.settle');
        Route::get('/shifts/{id}/cut/pdf', [ShiftController::class, 'cutPdf'])
            ->middleware('permission:report.shift_cut');
        Route::post('/cash/movements', [ShiftController::class, 'movement'])
            ->middleware('permission:pos_cash.movement');

        /*
         * Gastos categorizados (B-14).
         *
         * Sin esto el POS reporta ingresos, no utilidad. Un gasto pagado del
         * cajón sale además como movimiento de caja.
         */
        Route::get('/expenses/categories', [ExpenseController::class, 'categories'])
            ->middleware('permission:expense.read');
        Route::get('/expenses', [ExpenseController::class, 'index'])
            ->middleware('permission:expense.read');
        Route::post('/expenses', [ExpenseController::class, 'store'])
            ->middleware('permission:expense.create');
        Route::post('/expenses/{id}/void', [ExpenseController::class, 'void'])
            ->middleware('permission:expense.void');

        // Venta y carrito.
        Route::get('/sales', [SaleController::class, 'index'])
            ->middleware('permission:pos_sale.create,report.daily_sales');
        Route::post('/sales', [SaleController::class, 'store'])
            ->middleware('permission:pos_sale.create');
        /*
         * Qué queda por devolver de un ticket (H2.6).
         *
         * Lo consulta la pantalla de devolución antes de dejar elegir nada: la
         * cuenta descuenta lo ya devuelto, porque tres devoluciones parciales de
         * una unidad vaciarían un ticket de dos.
         */
        Route::get('/sales/{id}/refundable', [SaleController::class, 'refundable'])
            ->middleware('permission:pos_sale.refund');

        Route::get('/sales/{id}', [SaleController::class, 'show'])
            ->middleware('permission:pos_sale.create,report.daily_sales');
        Route::post('/sales/{id}/close', [SaleController::class, 'close'])
            ->middleware('permission:pos_sale.create');
        Route::post('/sales/{id}/discount', [SaleController::class, 'discount'])
            ->middleware('permission:pos_sale.create');
        /*
         * La propina (G-16). No lleva permiso propio: quien puede cobrar la
         * cuenta puede anotar lo que el cliente dejó, y pedir autorización para
         * eso pararía la caja en cada mesa.
         */
        Route::post('/sales/{id}/tip', [SaleController::class, 'tip'])
            ->middleware('permission:pos_sale.create');
        /*
         * Traspasar y dividir (F1-B).
         *
         * Mover líneas es del mesero: el grupo que se cambia de mesa y la pareja
         * que paga aparte son la misma noche. Lo que ya salió a cocina no se
         * reescribe.
         */
        Route::post('/sales/{id}/transfer', [SaleController::class, 'transfer'])
            ->middleware('permission:dining.serve,pos_sale.suspend');
        Route::post('/sales/{id}/split', [SaleController::class, 'split'])
            ->middleware('permission:dining.serve,pos_sale.suspend');

        /*
         * A quién se le vende. Quien puede vender puede elegirlo; crear
         * clientes sigue siendo del supervisor (Q-03). Cambia el impuesto si el
         * cliente está exonerado, así que recalcula la venta.
         */
        Route::post('/sales/{id}/customer', [SaleController::class, 'setCustomer'])
            ->middleware('permission:pos_sale.create');

        Route::post('/sales/{id}/suspend', [SaleController::class, 'suspend'])
            ->middleware('permission:pos_sale.suspend');
        Route::post('/sales/{id}/resume', [SaleController::class, 'resume'])
            ->middleware('permission:pos_sale.suspend');

        Route::post('/sales/{sale}/lines', [SaleLineController::class, 'store'])
            ->middleware('permission:pos_sale.create');
        Route::put('/sales/{sale}/lines/{line}', [SaleLineController::class, 'update'])
            ->middleware('permission:pos_sale.create');
        Route::delete('/sales/{sale}/lines/{line}', [SaleLineController::class, 'destroy'])
            ->middleware('permission:pos_sale.create');

        Route::post('/sales/{sale}/payments', [SalePaymentController::class, 'store'])
            ->middleware('permission:pos_sale.create');
        Route::delete('/sales/{sale}/payments/{payment}', [SalePaymentController::class, 'destroy'])
            ->middleware('permission:pos_sale.create');

        // Tipo de cambio: lo lee cualquiera que venda, lo escribe el supervisor.
        Route::get('/exchange-rates', [ExchangeRateController::class, 'show'])
            ->middleware('permission:pos_sale.create');
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])
            ->middleware('permission:pos_exchange_rate.update');

        /*
         * Empleados (D-05, D-02, D-03, D-13).
         *
         * Es donde se pone el PIN —la segunda mitad de la doble credencial—, el
         * de supervisor, que es **otro** (P-11), los topes propios de cada
         * cajero y las excepciones individuales de permisos.
         */
        Route::get('/security/employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employee.read');
        Route::get('/security/employees/{id}', [EmployeeController::class, 'show'])
            ->middleware('permission:employee.read');
        Route::post('/security/employees', [EmployeeController::class, 'store'])
            ->middleware('permission:employee.manage');
        Route::put('/security/employees/{id}', [EmployeeController::class, 'update'])
            ->middleware('permission:employee.manage');
        Route::put('/security/employees/{id}/overrides', [EmployeeController::class, 'overrides'])
            ->middleware('permission:employee.manage');
        // Fijar un PIN es lo más sensible de esta pantalla: va limitado también
        // por frecuencia, como el login (B-15).
        Route::post('/security/employees/{id}/pin', [EmployeeController::class, 'setPin'])
            ->middleware(['permission:employee.manage', 'throttle:20,1']);
        Route::post('/security/employees/{id}/unlock', [EmployeeController::class, 'unlock'])
            ->middleware('permission:employee.manage');

        /*
         * Roles y permisos (D-13, H7).
         *
         * Se administran desde la trastienda porque en una instalación sin
         * internet no hay a quién llamar para que los arregle. Los del sistema
         * se ajustan pero no se borran, y `admin` no se toca: es la salida de
         * emergencia.
         */
        Route::get('/security/permissions', [RoleController::class, 'permissions'])
            ->middleware('permission:role.manage');
        Route::get('/security/roles', [RoleController::class, 'index'])
            ->middleware('permission:role.manage');
        Route::post('/security/roles', [RoleController::class, 'store'])
            ->middleware('permission:role.manage');
        Route::put('/security/roles/{id}', [RoleController::class, 'update'])
            ->middleware('permission:role.manage');
        Route::delete('/security/roles/{id}', [RoleController::class, 'destroy'])
            ->middleware('permission:role.manage');

        /*
         * Bandeja de envíos al ERP (P4, F1-C).
         *
         * El POS cierra la venta sin el ERP; lo que se ve acá es lo que quedó
         * por enviar y lo que el ERP rechazó. Reintentar es para cuando la causa
         * se arregló del otro lado; resolver a mano, para lo que nunca va a
         * aceptar —una tarjeta de regalo mientras A3 siga abierto—.
         */
        Route::get('/erp/status', [ErpIntegrationController::class, 'status'])
            ->middleware('permission:erp_outbox.read');
        // Bajar la configuración es una lectura: con ERP presente, la
        // configuración de caja es suya y el POS la adopta (P2, P3).
        Route::post('/erp/settings/pull', [ErpIntegrationController::class, 'pull'])
            ->middleware('permission:pos_settings.update');

        /*
         * Lectura de maestros (§12 del contrato).
         *
         * Con ERP presente el catálogo y los clientes son suyos y el POS solo
         * los lee (P2). Lo que no case por clave natural queda en la bandeja de
         * conciliación, que avisa y **no bloquea**.
         */
        Route::post('/erp/masters/pull', [ErpIntegrationController::class, 'pullMasters'])
            ->middleware('permission:catalog.import');
        Route::get('/erp/reconciliation', [ErpIntegrationController::class, 'reconciliation'])
            ->middleware('permission:erp_outbox.read');
        Route::post('/erp/reconciliation/{id}/resolve', [ErpIntegrationController::class, 'resolveReconciliation'])
            ->middleware('permission:erp_outbox.resolve');

        Route::get('/erp/outbox', [ErpOutboxController::class, 'index'])
            ->middleware('permission:erp_outbox.read');
        Route::get('/erp/outbox/{id}', [ErpOutboxController::class, 'show'])
            ->middleware('permission:erp_outbox.read');
        Route::post('/erp/outbox/{id}/retry', [ErpOutboxController::class, 'retry'])
            ->middleware('permission:erp_outbox.resolve');
        Route::post('/erp/outbox/{id}/resolve', [ErpOutboxController::class, 'resolve'])
            ->middleware('permission:erp_outbox.resolve');

        /*
         * Reportes: el mínimo operativo de F1 (P-07). El módulo completo es F2.
         */
        Route::get('/reports/daily-sales', [ReportController::class, 'dailySales'])
            ->middleware('permission:report.daily_sales');
        Route::get('/reports/audit-log', [ReportController::class, 'auditLog'])
            ->middleware('permission:report.audit_log');

        Route::get('/supervision/notifications', [SupervisorNotificationController::class, 'index'])
            ->middleware('permission:supervisor.notifications');
        Route::post('/supervision/notifications/{id}/read', [SupervisorNotificationController::class, 'read'])
            ->middleware('permission:supervisor.notifications');
    });
});
