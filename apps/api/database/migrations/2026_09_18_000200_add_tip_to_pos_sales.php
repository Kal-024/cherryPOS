<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La propina (G-16).
 *
 * Vive en la venta y **fuera de todos sus importes fiscales**: no entra en
 * `subtotal`, ni en `taxable_base`, ni en `tax_total`, ni en `total`. La razón
 * no es de presentación sino de contabilidad — la propina no es ingreso del
 * negocio, no lleva IVA y no viaja al ERP. Sumarla al total la convertiría en
 * base gravada y `tax_difference` dejaría de ser cero en cada cuenta de
 * restaurante, que es justo la alarma que no debe sonar por diseño.
 *
 * Sí entra en lo que hay que cobrar (`due`), y por eso el dinero aparece solo en
 * el arqueo: el cajón cuenta billetes, no facturas. Lo que hace falta al cerrar
 * el turno es poder **separar** cuánto de ese efectivo es propina para pagarla,
 * y eso se responde sumando esta columna.
 *
 * `tip_employee_id` existe porque la propina no siempre es del mesero de la
 * cuenta: en un local con bote común se reparte, y en uno sin meseros la cobra
 * la caja. Nulo significa "la del `waiter_employee_id` de la venta, si lo hay".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->decimal('tip_amount', 18, 2)->default(0)->after('cash_rounding');
            $table->foreignUuid('tip_employee_id')->nullable()->after('tip_amount')
                ->constrained('sec_employees')->nullOnDelete();
        });

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.2.0',
            'applied_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tip_employee_id');
            $table->dropColumn('tip_amount');
        });

        DB::table('cmn_schema_version')->where('id', 1)->update([
            'version' => '1.1.0',
            'applied_at' => now(),
        ]);
    }
};
