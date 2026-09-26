<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Catálogo de permisos y roles del sistema (B-13, D-13).
 *
 * OSPOS asigna cada permiso persona por persona: con 40 empleados es
 * inmanejable. Aquí el grueso lo llevan los roles, y los overrides individuales
 * —que pueden **conceder o revocar**— quedan para la excepción.
 *
 * El vocabulario `recurso.accion` es el mismo de cherryB a propósito: el día
 * que se integre, los dos sistemas hablan de lo mismo con las mismas palabras.
 * `pos_sale.create` y `pos_settings.*` existen ya en el ERP (§3 del contrato).
 */
class PermissionSeeder extends Seeder
{
    /** @var array<string,array<string,string>> */
    private const PERMISSIONS = [
        'venta' => [
            'pos_sale.create' => 'Registrar y cerrar ventas',
            'pos_sale.suspend' => 'Suspender una venta o abrir cuenta',
            'pos_sale.void' => 'Anular una venta con documento de reverso',
            'pos_sale.refund' => 'Registrar devoluciones',
            'pos_sale.discount' => 'Aplicar descuentos dentro del tope propio',
            'pos_sale.discount_authorize' => 'Autorizar descuentos sobre el tope ajeno',
            'pos_sale.temporary_item' => 'Vender ítem temporal o por monto',
            'pos_sale.price_override' => 'Modificar el precio de una línea',
        ],
        /*
         * Salón y cocina (F1-B).
         *
         * `dining.serve` es del mesero —abrir una cuenta en una mesa, unirlas—
         * y `dining.manage` de quien configura el local: mover una mesa en el
         * mapa no es una operación de turno.
         */
        'salon' => [
            'dining.read' => 'Ver el mapa del salón',
            'dining.serve' => 'Abrir cuentas en mesa, unir y separar mesas',
            'dining.manage' => 'Configurar el salón: zonas, mesas y su disposición',
            // La lista 86 (F1-B) no es editar el catálogo: es decir que hoy no
            // hay. Por eso tiene permiso propio — se le puede dar a la cocina
            // sin darle de paso la edición de precios.
            'catalog.availability' => 'Marcar productos agotados del día',
            'kitchen.display' => 'Ver la pantalla de cocina',
            'kitchen.update' => 'Marcar comandas como en preparación, listas o servidas',
        ],
        'caja' => [
            'pos_shift.open' => 'Abrir turno de caja',
            'pos_shift.close' => 'Cerrar turno y hacer el arqueo',
            // Cuadrar no reescribe el arqueo: asienta el faltante o el sobrante
            // como movimiento de caja, con motivo y autorización.
            'pos_shift.settle' => 'Cuadrar una caja cerrada con diferencia',
            'pos_shift.read' => 'Consultar turnos',
            'pos_cash.movement' => 'Registrar entradas y salidas de caja',
            'pos_exchange_rate.update' => 'Cargar el tipo de cambio del día',
        ],
        'catalogo' => [
            'catalog.product.read' => 'Consultar el catálogo',
            'catalog.product.create' => 'Crear productos y servicios',
            'catalog.product.update' => 'Editar productos y servicios',
            'catalog.product.delete' => 'Dar de baja productos',
            'catalog.import' => 'Importar y exportar desde Excel',
        ],
        'inventario' => [
            'inventory.read' => 'Consultar existencias y movimientos',
            'inventory.adjust' => 'Ajustar existencias',
            'inventory.transfer' => 'Transferir entre ubicaciones',
            'inventory.receive' => 'Recibir mercadería',
        ],
        'clientes' => [
            'customer.read' => 'Consultar clientes',
            'customer.create' => 'Registrar clientes',
            'customer.update' => 'Editar clientes',
            'credit.read' => 'Consultar cuentas de crédito y morosidad',
            'credit.charge' => 'Cargar consumo a una cuenta',
            'credit.payment' => 'Recibir pagos de cuenta en caja',
            'credit.block' => 'Bloquear y desbloquear una cuenta',
        ],
        // Mínimo operativo de F1 (P-07): no es el módulo de reportes, son las
        // vistas sin las cuales el negocio no cierra el día.
        // B-14: quedó confirmado en la Parte I y sin hito asignado en el plan.
        // Entra junto al turno de caja, porque un gasto pagado del cajón es un
        // movimiento de caja.
        'gastos' => [
            'expense.read' => 'Consultar gastos',
            'expense.create' => 'Registrar gastos',
            'expense.void' => 'Anular un gasto',
            'expense.category.manage' => 'Administrar categorías de gasto',
        ],
        'reportes' => [
            'report.shift_cut' => 'Corte de turno',
            'report.daily_sales' => 'Ventas del día por cajero y terminal',
            'report.credit_balances' => 'Créditos y saldos',
            'report.inventory_movements' => 'Movimientos de inventario',
            'report.audit_log' => 'Bitácora de auditoría',
        ],
        'administracion' => [
            'pos_settings.read' => 'Consultar la configuración del POS',
            'pos_settings.update' => 'Editar la configuración del POS',
            'employee.read' => 'Consultar empleados',
            'employee.manage' => 'Crear y editar empleados, PIN y topes',
            'role.manage' => 'Administrar roles y permisos',
            'terminal.manage' => 'Administrar terminales',
            'erp_outbox.read' => 'Consultar la bandeja de envíos al ERP',
            'erp_outbox.resolve' => 'Resolver excepciones de envío al ERP',
            'supervisor.notifications' => 'Recibir avisos de supervisión',
        ],
    ];

    /** @var array<string,array{name:string,permissions:array<int,string>|string}> */
    private const ROLES = [
        'cashier' => [
            'name' => 'Cajero',
            'permissions' => [
                'pos_sale.create', 'pos_sale.suspend', 'pos_sale.discount',
                'pos_shift.open', 'pos_shift.close',
                'catalog.product.read', 'customer.read', 'credit.charge',
                'report.shift_cut',
                // El mesero es un cajero con mesas: abre cuentas y manda a
                // cocina, pero no configura el salón.
                'dining.read', 'dining.serve', 'kitchen.display',
            ],
        ],
        'supervisor' => [
            'name' => 'Supervisor',
            'permissions' => [
                'pos_sale.create', 'pos_sale.suspend', 'pos_sale.void', 'pos_sale.refund',
                'pos_sale.discount', 'pos_sale.discount_authorize', 'pos_sale.temporary_item',
                'pos_sale.price_override',
                'pos_shift.open', 'pos_shift.close', 'pos_shift.read', 'pos_shift.settle',
                'pos_cash.movement',
                'pos_exchange_rate.update',
                'catalog.product.read', 'catalog.product.create', 'catalog.product.update',
                'catalog.availability',
                'inventory.read', 'inventory.adjust',
                'customer.read', 'customer.create', 'customer.update',
                'credit.read', 'credit.charge', 'credit.payment', 'credit.block',
                'report.shift_cut', 'report.daily_sales', 'report.credit_balances',
                'report.inventory_movements',
                'expense.read', 'expense.create', 'expense.void',
                'dining.read', 'dining.serve', 'dining.manage',
                'kitchen.display', 'kitchen.update',
                'supervisor.notifications', 'erp_outbox.read',
            ],
        ],
        // El encargado se compone en `run()` a partir del supervisor: hereda
        // todo lo suyo y suma lo del día a día del local. Listarlo dos veces
        // sería garantizar que se desincronicen.
        'manager' => [
            'name' => 'Encargado',
            'permissions' => [],
        ],
        'admin' => [
            'name' => 'Administrador',
            'permissions' => '*',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $module => $permissions) {
            foreach ($permissions as $code => $description) {
                Permission::updateOrCreate(
                    ['code' => $code],
                    ['module' => $module, 'description' => $description]
                );
            }
        }

        $all = Permission::pluck('id', 'code');

        foreach (self::ROLES as $code => $definition) {
            $role = Role::updateOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'is_system' => true]
            );

            $codes = $definition['permissions'] === '*'
                ? $all->keys()->all()
                : $definition['permissions'];

            // El encargado hereda todo lo del supervisor salvo la
            // administración del sistema: es quien lleva el local, no quien lo
            // configura.
            if ($code === 'manager') {
                $codes = array_merge(
                    self::ROLES['supervisor']['permissions'],
                    ['catalog.import', 'inventory.transfer', 'inventory.receive',
                        'employee.read', 'report.audit_log', 'erp_outbox.resolve',
                        'expense.category.manage',
                        // Ajustar el pie del ticket o el ancho del papel es
                        // trabajo de quien lleva el local, no del dueño.
                        'pos_settings.read', 'pos_settings.update']
                );
            }

            $role->permissions()->sync(
                $all->only(array_filter($codes, fn ($c) => $all->has($c)))->values()->all()
            );
        }
    }
}
