<?php

namespace Tests\Feature\Credit;

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Credit\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Crédito de clientes (P-02, G-10, Q-10, H8).
 *
 * *"Lo básico para tener control e información sobre las cuentas de clientes que
 * deciden esta forma de pago."* Intereses, planes de pago y cobranza son del
 * módulo de crédito del ERP; esto es lo que hace falta para operar sin él.
 */
class CreditTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    private Customer $customer;

    private CreditAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();

        // Abrir cuentas y cobrar abonos es del supervisor (Q-03).
        $this->token = $this->signedIn($this->employee('SUP01', '4321', 'supervisor', '9876'), '4321');

        $this->customer = $this->customer('Don Julio Martínez', 'account', '001-010180-0001A');
        $this->account = app(CreditService::class)
            ->openAccount($this->customer, '5000.00', 30, $this->branch->id);
    }

    private function chargeSale(string $price): Sale
    {
        $product = Product::where('sku', 'P-001')->first() ?? $this->product('P-001', $price);

        $sale = $this->actingAsTerminal($this->token)->postJson('/api/sales', [
            'customer_id' => $this->customer->id,
        ])->json('data.id');

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/lines", [
            'kind' => 'product', 'product_id' => $product->id, 'qty' => '1',
        ])->assertCreated();

        $total = Sale::find($sale)->total;

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/payments", [
            'method' => 'credit', 'amount' => (string) $total,
        ])->assertCreated();

        $this->actingAsTerminal($this->token)->postJson("/api/sales/{$sale}/close")->assertOk();

        return Sale::find($sale);
    }

    public function test_una_cuenta_exige_cedula(): void
    {
        $sinCedula = $this->customer('Sin cédula', 'account');

        // La cuenta es personal: sin cédula no se sabe de quién es (G-10).
        $this->expectExceptionMessage(__('credit.account_needs_national_id'));

        app(CreditService::class)->openAccount($sinCedula, '1000.00', 30, $this->branch->id);
    }

    public function test_un_cliente_de_efectivo_no_lleva_cuenta(): void
    {
        $efectivo = $this->customer('Cliente de mostrador');

        // Paga y se va: abrirle cuenta sería inventarle un vínculo que no pidió.
        $this->expectExceptionMessage(__('credit.customer_is_cash'));

        app(CreditService::class)->openAccount($efectivo, '1000.00', 30, $this->branch->id);
    }

    public function test_la_cuenta_admite_hasta_tres_autorizados(): void
    {
        foreach (['Ana', 'Beto', 'Carla'] as $index => $name) {
            $this->actingAsTerminal($this->token)
                ->postJson("/api/customers/{$this->customer->id}/credit/authorized", [
                    'name' => $name,
                    'national_id' => '001-01018'.$index.'-000'.$index.'X',
                    'relationship' => 'Familiar',
                ])
                ->assertCreated();
        }

        // G-10, literal: hasta 3 personas autorizadas por cuenta.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/authorized", [
                'name' => 'Cuarto',
                'national_id' => '001-010184-0004X',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.authorized.0', __('credit.too_many_authorized', ['max' => 3]));
    }

    public function test_el_autorizado_exige_datos_completos(): void
    {
        // Es quien firma el consumo: el día que haya que reclamarlo hace falta
        // saber quién es.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/authorized", ['name' => 'Ana'])
            ->assertStatus(422);
    }

    public function test_dar_de_baja_un_autorizado_no_borra_el_historico(): void
    {
        $authorized = $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/authorized", [
                'name' => 'Ana', 'national_id' => '001-010181-0001X',
            ])->json('data');

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/customers/{$this->customer->id}/credit/authorized/{$authorized['id']}")
            ->assertOk();

        // Baja lógica: los consumos viejos apuntan a esta fila y borrarla
        // dejaría el histórico sin decir quién se llevó la mercadería.
        $this->assertDatabaseHas('crm_customer_authorized', [
            'id' => $authorized['id'],
            'is_active' => false,
        ]);
    }

    public function test_un_abono_parcial_baja_el_saldo_y_libera_credito(): void
    {
        $this->chargeSale('1000.00');
        $this->assertSame('1000.00', (string) $this->account->fresh()->balance);

        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/payments", ['amount' => '400.00'])
            ->assertCreated();

        $account = $this->account->fresh();

        // Q-10: se permiten pagos parciales, y el crédito se libera a medida que
        // se paga.
        $this->assertSame('600.00', (string) $account->balance);
        $this->assertSame('4400.00', $account->available());
    }

    public function test_un_abono_en_efectivo_entra_al_cajon(): void
    {
        $this->chargeSale('1000.00');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/payments", ['amount' => '400.00'])
            ->assertCreated();

        $summary = $this->actingAsTerminal($this->token)
            ->getJson('/api/shifts/current')->assertOk()->json('data');

        // El arqueo tiene que poder explicar esa plata que apareció y que no
        // viene de una venta. Fondo 1.000 más 400 de abono.
        $this->assertSame(
            '1400.00',
            collect($summary['by_currency'])->firstWhere('currency_code', 'NIO')['expected']
        );
        $this->assertDatabaseHas('pos_cash_movements', ['direction' => 'in', 'amount' => '400.00']);
    }

    public function test_no_se_cobra_mas_que_el_saldo(): void
    {
        $this->chargeSale('1000.00');

        // Cobrar de más deja la cuenta a favor y a nadie le queda claro por qué.
        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/payments", ['amount' => '1500.00'])
            ->assertStatus(422);

        $this->assertSame('1000.00', (string) $this->account->fresh()->balance);
    }

    public function test_el_supervisor_bloquea_la_cuenta_por_mora(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/block", ['reason' => 'Mora de dos meses'])
            ->assertOk()
            ->assertJsonPath('data.is_blocked', true);

        // El bloqueo se suma al límite, no lo reemplaza: la cuenta está dentro
        // del límite y aun así no admite consumo (Q-10).
        $this->assertTrue($this->account->fresh()->is_blocked);
        $this->assertFalse($this->account->fresh()->canCharge('100.00'));
        $this->assertDatabaseHas('sec_audit_log', ['event' => 'credit.account_blocked']);
    }

    public function test_desbloquear_es_otra_decision_y_tambien_queda_registrada(): void
    {
        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/block", ['reason' => 'Mora'])->assertOk();

        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/unblock")
            ->assertOk()
            ->assertJsonPath('data.is_blocked', false);

        $this->assertDatabaseHas('sec_audit_log', ['event' => 'credit.account_unblocked']);
    }

    public function test_el_supervisor_ve_quien_debe_y_cuanto(): void
    {
        $this->chargeSale('1000.00');

        $accounts = $this->actingAsTerminal($this->token)
            ->getJson('/api/credit/accounts?with_balance_only=1')
            ->assertOk()->json('data');

        // Es la vista que necesita para decidir a quién llamar (G-10, P-07).
        $this->assertCount(1, $accounts);
        $this->assertSame('Don Julio Martínez', $accounts[0]['customer_name']);
        $this->assertSame('1000.00', $accounts[0]['balance']);
        $this->assertSame('4000.00', $accounts[0]['available']);
    }

    public function test_el_estado_de_cuenta_cubre_el_periodo_de_corte(): void
    {
        $this->chargeSale('1000.00');

        $this->actingAsTerminal($this->token)
            ->postJson("/api/customers/{$this->customer->id}/credit/payments", ['amount' => '300.00'])
            ->assertCreated();

        $statement = $this->actingAsTerminal($this->token)
            ->getJson("/api/customers/{$this->customer->id}/credit/statement")
            ->assertOk()->json('data');

        $this->assertSame('1000.00', $statement['charges']);
        $this->assertSame('300.00', $statement['payments']);
        $this->assertSame('700.00', $statement['closing_balance']);
        $this->assertCount(2, $statement['entries']);
        $this->assertSame(30, $statement['period']['cut_off_day']);
    }

    public function test_la_fecha_de_corte_es_configurable_por_cliente(): void
    {
        $credit = app(CreditService::class);

        // Q-10: no todos cobran el 30. El estado de cuenta cubre el período
        // **en curso** —el que contiene la fecha pedida— con lo anterior como
        // saldo inicial. Es lo que el cliente quiere ver cuando viene a pagar:
        // todo lo que debe hoy, no lo que debía el mes pasado.
        [$from, $to] = $credit->period(Carbon::parse('2026-09-16'), 15);

        $this->assertSame('2026-09-16', $from->toDateString());
        $this->assertSame('2026-10-15', $to->toDateString());

        // El período ya cerrado se pide indicando una fecha dentro de él.
        [$from, $to] = $credit->period(Carbon::parse('2026-09-10'), 15);

        $this->assertSame('2026-08-16', $from->toDateString());
        $this->assertSame('2026-09-15', $to->toDateString());
    }

    public function test_un_corte_el_treinta_cae_el_ultimo_dia_de_febrero(): void
    {
        $credit = app(CreditService::class);

        // Pedirle a Carbon el 30 de febrero lo mandaría a marzo. El día se acota
        // al último disponible.
        [, $to] = $credit->period(Carbon::parse('2026-02-10'), 30);

        $this->assertSame('2026-02-28', $to->toDateString());
    }

    public function test_el_saldo_anterior_no_se_mezcla_con_el_periodo(): void
    {
        $this->chargeSale('1000.00');

        // Un consumo del período pasado no vuelve a aparecer como cargo: entra
        // como saldo anterior, que es lo que el cliente espera leer.
        CreditEntry::where('account_id', $this->account->id)
            ->update(['occurred_at' => now()->subMonths(2)]);

        $statement = $this->actingAsTerminal($this->token)
            ->getJson("/api/customers/{$this->customer->id}/credit/statement")
            ->assertOk()->json('data');

        $this->assertSame('1000.00', $statement['opening_balance']);
        $this->assertSame('0.00', $statement['charges']);
        $this->assertSame('1000.00', $statement['closing_balance']);
    }

    public function test_el_cajero_no_abre_cuentas_ni_bloquea(): void
    {
        $token = $this->signedIn();

        // Q-03: en caja solo se usan los clientes ya registrados.
        $this->actingAsTerminal($token)
            ->postJson("/api/customers/{$this->customer->id}/credit", ['credit_limit' => '9999.00'])
            ->assertStatus(403);

        $this->actingAsTerminal($token)
            ->postJson("/api/customers/{$this->customer->id}/credit/block", ['reason' => 'Porque sí'])
            ->assertStatus(403);
    }

    public function test_el_cliente_se_busca_por_nombre_o_cedula(): void
    {
        $byName = $this->actingAsTerminal($this->token)
            ->getJson('/api/customers?search='.urlencode('Martínez'))->assertOk()->json('data');

        $byId = $this->actingAsTerminal($this->token)
            ->getJson('/api/customers?search=010180')->assertOk()->json('data');

        // Es como busca un cajero: escribe lo que el cliente le dice.
        $this->assertCount(1, $byName);
        $this->assertSame($byName[0]['id'], $byId[0]['id']);
    }

    public function test_el_consentimiento_se_fecha_cuando_se_otorga(): void
    {
        $customer = $this->actingAsTerminal($this->token)
            ->postJson('/api/customers', [
                'name' => 'Cliente con consentimiento',
                'kind' => 'cash',
                'whatsapp' => '+50588881111',
                'consent_whatsapp' => true,
            ])
            ->assertCreated()
            ->json('data');

        // "Dijo que sí alguna vez" no sirve como constancia (D-09).
        $this->assertNotNull(Customer::find($customer['id'])->consent_given_at);
    }
}
