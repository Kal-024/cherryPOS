<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las cinco precondiciones no negociables del modelo de datos.
 *
 * No prueban una funcionalidad: prueban que nadie las rompa por descuido. Son
 * baratas ahora y carísimas después — agregar `branch_id` o cambiar una clave
 * autoincremental por UUID en cuarenta tablas de producción es exactamente la
 * clase de trabajo que nadie quiere hacer con cajas facturando.
 *
 * Esta prueba es la razón por la que `phpunit.xml` apunta a PostgreSQL: sobre
 * SQLite no hay `information_schema` que interrogar.
 */
class PreconditionsTest extends TestCase
{
    use RefreshDatabase;

    /** Prefijos de dominio de cherryPOS. Lo de fuera es andamiaje de Laravel. */
    private const PREFIXES = ['cmn_', 'sec_', 'cat_', 'inv_', 'crm_', 'pos_'];

    /**
     * Entidades transaccionales: toda fila que nace en una sucursal y viaja
     * hacia casa matriz.
     */
    private const TRANSACTIONAL = [
        'pos_sales',
        'pos_sale_lines',
        'pos_payments',
        'pos_shifts',
        'pos_cash_movements',
        'inv_movements',
        'sec_audit_log',
    ];

    /** @return array<int,string> */
    private function domainTables(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name
        SQL);

        return array_values(array_filter(
            array_map(static fn ($row) => $row->table_name, $rows),
            fn (string $table) => $this->isDomainTable($table)
        ));
    }

    private function isDomainTable(string $table): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function test_encuentra_las_tablas_de_dominio(): void
    {
        // Si esto falla es que los prefijos cambiaron y la prueba dejó de mirar
        // nada, que es peor que fallar.
        $this->assertGreaterThan(20, count($this->domainTables()));
    }

    /**
     * Precondición 1 — identificadores UUID v7, nunca autoincrementales.
     *
     * Dos sucursales generando el mismo identificador rompen la consolidación,
     * y el terminal tiene que poder crear una venta sin consultar al servidor.
     */
    public function test_ninguna_tabla_de_dominio_usa_clave_autoincremental(): void
    {
        $offenders = DB::select(<<<'SQL'
            SELECT c.table_name, c.column_name
            FROM information_schema.columns c
            WHERE c.table_schema = 'public'
              AND (c.column_default LIKE 'nextval%' OR c.is_identity = 'YES')
        SQL);

        $found = array_values(array_filter(
            array_map(
                static fn ($row) => "{$row->table_name}.{$row->column_name}",
                $offenders
            ),
            fn (string $column) => $this->isDomainTable($column)
        ));

        $this->assertSame([], $found, implode("\n", [
            'Hay columnas autoincrementales en tablas de dominio:',
            ...$found,
            'Precondición 1: identificadores UUID v7 o ULID, nunca autoincrementales.',
        ]));
    }

    /** Precondición 1 — y además la clave primaria debe ser `uuid`. */
    public function test_las_claves_primarias_de_dominio_son_uuid(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tc.table_name, kcu.column_name, c.data_type
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.table_schema = tc.table_schema
            JOIN information_schema.columns c
              ON c.table_name = tc.table_name
             AND c.column_name = kcu.column_name
             AND c.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'PRIMARY KEY'
              AND tc.table_schema = 'public'
        SQL);

        // Tablas puente y catálogos con clave natural: su primaria son claves
        // foráneas o un código estable, no un identificador propio.
        $naturalKeys = [
            'sec_role_permission',
            'sec_employee_role',
            'sec_employee_permission',
            'cmn_currencies',      // código ISO de tres letras
            'cmn_schema_version',  // una sola fila
        ];

        foreach ($rows as $row) {
            if (! $this->isDomainTable($row->table_name) || in_array($row->table_name, $naturalKeys, true)) {
                continue;
            }

            $this->assertSame(
                'uuid',
                $row->data_type,
                "La clave primaria de {$row->table_name} es {$row->data_type}, no uuid."
            );
        }
    }

    /**
     * Precondición 2 — `branch_id` en toda entidad transaccional.
     *
     * Sin esto, el día que un cliente abra su segunda sucursal hay que migrar
     * claves foráneas en producción.
     */
    public function test_toda_entidad_transaccional_lleva_sucursal(): void
    {
        foreach (self::TRANSACTIONAL as $table) {
            $columns = array_map(
                static fn ($row) => $row->column_name,
                DB::select(
                    'SELECT column_name FROM information_schema.columns
                     WHERE table_schema = ? AND table_name = ?',
                    ['public', $table]
                )
            );

            $this->assertContains(
                'branch_id',
                $columns,
                "{$table} no lleva `branch_id` y es una entidad transaccional."
            );
        }
    }

    /**
     * Precondición 4 — marcas de tiempo **con zona horaria**.
     *
     * Casa matriz consolida sucursales que pueden no compartir zona, y el
     * consolidado no puede depender de que los relojes coincidan.
     */
    public function test_todas_las_marcas_de_tiempo_llevan_zona_horaria(): void
    {
        $offenders = DB::select(<<<'SQL'
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND data_type = 'timestamp without time zone'
        SQL);

        $found = array_values(array_filter(
            array_map(
                static fn ($row) => "{$row->table_name}.{$row->column_name}",
                $offenders
            ),
            fn (string $column) => $this->isDomainTable($column)
        ));

        $this->assertSame([], $found, implode("\n", [
            'Hay marcas de tiempo sin zona horaria en tablas de dominio:',
            ...$found,
            'Precondición 4: `timestampTz`, nunca `timestamp`.',
        ]));
    }

    /** Precondición 5 — la versión de esquema existe y es consultable. */
    public function test_la_version_de_esquema_esta_registrada(): void
    {
        $version = DB::table('cmn_schema_version')->where('id', 1)->first();

        $this->assertNotNull($version, 'La instalación no declara versión de esquema.');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version->version);
    }
}
