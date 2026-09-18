<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inmutabilidad de la venta cerrada, impuesta por la base de datos (A-05, D-17).
 *
 * OSPOS permite **editar y borrar** una venta ya cerrada. Eso rompe la
 * auditoría y es ilegal en la mayoría de los regímenes fiscales: una vez
 * emitido el comprobante no puede modificarse, solo anularse con un documento
 * de reverso.
 *
 * El candado vive aquí y no solo en el servicio porque una regla que únicamente
 * vive en el código se salta con un `tinker`, con una consola SQL o con el
 * próximo servicio que alguien escriba sin leer esta decisión. Al tocar dinero,
 * el sistema tiene que ser claro, estricto y legal.
 *
 * Qué **sí** puede cambiar después del cierre: el estado de sincronización con
 * el ERP, los campos del andamio fiscal y la transición a `voided`. Son
 * anotaciones sobre el documento, no el documento.
 *
 * Mientras `closed_at` es nulo la venta es un carrito o una cuenta abierta y se
 * edita con total libertad: la inmutabilidad empieza al cerrar, no al crear.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pos_sales_immutable_once_closed()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.closed_at IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Una venta cerrada no se borra: se anula con un documento de reverso (venta %)',
                            OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;

                -- Carrito o cuenta abierta: todavía se edita.
                IF OLD.closed_at IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.branch_id           IS DISTINCT FROM OLD.branch_id
                OR NEW.terminal_id         IS DISTINCT FROM OLD.terminal_id
                OR NEW.shift_id            IS DISTINCT FROM OLD.shift_id
                OR NEW.employee_id         IS DISTINCT FROM OLD.employee_id
                OR NEW.customer_id         IS DISTINCT FROM OLD.customer_id
                OR NEW.sale_type           IS DISTINCT FROM OLD.sale_type
                OR NEW.series_id           IS DISTINCT FROM OLD.series_id
                OR NEW.number              IS DISTINCT FROM OLD.number
                OR NEW.currency_code       IS DISTINCT FROM OLD.currency_code
                OR NEW.exchange_rate       IS DISTINCT FROM OLD.exchange_rate
                OR NEW.gross               IS DISTINCT FROM OLD.gross
                OR NEW.line_discount_total IS DISTINCT FROM OLD.line_discount_total
                OR NEW.sale_discount       IS DISTINCT FROM OLD.sale_discount
                OR NEW.discount_total      IS DISTINCT FROM OLD.discount_total
                OR NEW.subtotal            IS DISTINCT FROM OLD.subtotal
                OR NEW.taxable_base        IS DISTINCT FROM OLD.taxable_base
                OR NEW.exempt_total        IS DISTINCT FROM OLD.exempt_total
                OR NEW.tax_total           IS DISTINCT FROM OLD.tax_total
                OR NEW.total               IS DISTINCT FROM OLD.total
                OR NEW.cash_rounding       IS DISTINCT FROM OLD.cash_rounding
                OR NEW.paid                IS DISTINCT FROM OLD.paid
                OR NEW.balance             IS DISTINCT FROM OLD.balance
                OR NEW.opened_at           IS DISTINCT FROM OLD.opened_at
                OR NEW.closed_at           IS DISTINCT FROM OLD.closed_at
                THEN
                    RAISE EXCEPTION
                        'Una venta cerrada no se edita: se corrige con un documento de reverso (venta %)',
                        OLD.id;
                END IF;

                IF OLD.status = 'voided' AND NEW.status <> 'voided' THEN
                    RAISE EXCEPTION 'Una venta anulada no vuelve atrás (venta %)', OLD.id;
                END IF;

                IF OLD.status = 'completed' AND NEW.status NOT IN ('completed', 'voided') THEN
                    RAISE EXCEPTION
                        'Una venta completada solo puede pasar a anulada (venta %)', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER pos_sales_immutability
            BEFORE UPDATE OR DELETE ON pos_sales
            FOR EACH ROW EXECUTE FUNCTION pos_sales_immutable_once_closed();
        SQL);

        /*
         * Las líneas, sus impuestos y los pagos siguen la suerte del encabezado:
         * congelar el total y dejar editable el detalle sería dejar la puerta
         * abierta y cerrar la ventana.
         *
         * `cascadeOnDelete` del encabezado sigue funcionando porque el borrado
         * del padre solo ocurre mientras la venta está abierta — y si está
         * cerrada, el disparador del padre lo impide antes.
         */
        foreach ([
            'pos_sale_lines' => 'sale_id',
            'pos_payments' => 'sale_id',
        ] as $table => $column) {
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$table}_frozen_when_sale_closed()
                RETURNS TRIGGER AS \$\$
                DECLARE
                    closed TIMESTAMPTZ;
                    sale UUID;
                BEGIN
                    sale := CASE WHEN TG_OP = 'DELETE' THEN OLD.{$column} ELSE NEW.{$column} END;
                    SELECT closed_at INTO closed FROM pos_sales WHERE id = sale;

                    IF closed IS NOT NULL THEN
                        RAISE EXCEPTION
                            'La venta % ya está cerrada: su detalle no se modifica (%)', sale, TG_OP;
                    END IF;

                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                \$\$ LANGUAGE plpgsql;
            SQL);

            DB::statement(<<<SQL
                CREATE TRIGGER {$table}_immutability
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$table}_frozen_when_sale_closed();
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['pos_sale_lines', 'pos_payments'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutability ON {$table}");
            DB::statement("DROP FUNCTION IF EXISTS {$table}_frozen_when_sale_closed()");
        }

        DB::statement('DROP TRIGGER IF EXISTS pos_sales_immutability ON pos_sales');
        DB::statement('DROP FUNCTION IF EXISTS pos_sales_immutable_once_closed()');
    }
};
