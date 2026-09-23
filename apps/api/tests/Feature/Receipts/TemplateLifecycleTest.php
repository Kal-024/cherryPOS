<?php

namespace Tests\Feature\Receipts;

use App\Models\ReceiptTemplate;
use Database\Seeders\ReceiptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PosWorld;
use Tests\TestCase;

/**
 * Qué plantilla se usa, y cómo se deshace una copia (D-11).
 *
 * Duplicar era una trampa silenciosa: la copia heredaba el ser predeterminada y
 * `ReceiptTemplate::resolve()` prefiere la de la sucursal sobre la del sistema,
 * así que duplicar para experimentar **cambiaba lo que se imprimía** sin que
 * nada lo dijera. Y no había forma de borrar la copia ni de decir cuál se usa.
 */
class TemplateLifecycleTest extends TestCase
{
    use PosWorld, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPosWorld();
        $this->seed(ReceiptTemplateSeeder::class);
        $this->token = $this->signedIn($this->employee('ENC01', '4321', 'manager'), '4321');
    }

    private function systemTemplate(): ReceiptTemplate
    {
        return ReceiptTemplate::where('is_system', true)->whereNull('branch_id')->firstOrFail();
    }

    private function duplicateOfSystem(): ReceiptTemplate
    {
        $id = $this->actingAsTerminal($this->token)
            ->postJson("/api/receipt-templates/{$this->systemTemplate()->id}/duplicate")
            ->assertCreated()
            ->json('data.id');

        return ReceiptTemplate::findOrFail($id);
    }

    public function test_la_copia_no_nace_siendo_la_predeterminada(): void
    {
        $original = $this->systemTemplate();
        $copy = $this->duplicateOfSystem();

        $this->assertFalse($copy->is_default);
        $this->assertSame($this->branch->id, $copy->branch_id);

        // Lo que importa de verdad: lo que se imprime no cambió por duplicar.
        $this->assertSame(
            $original->id,
            ReceiptTemplate::resolve($original->document_type, $this->branch->id)->id
        );
    }

    public function test_marcar_una_predeterminada_desmarca_las_demas(): void
    {
        $copy = $this->duplicateOfSystem();
        $other = $this->duplicateOfSystem();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/receipt-templates/{$copy->id}", ['is_default' => true])
            ->assertOk();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/receipt-templates/{$other->id}", ['is_default' => true])
            ->assertOk();

        // Predeterminada hay una sola por tipo y sucursal, o el desempate queda
        // en el orden que devuelva la base — es decir, en cualquiera.
        $this->assertFalse($copy->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_default);
        $this->assertSame(
            $other->id,
            ReceiptTemplate::resolve($other->document_type, $this->branch->id)->id
        );
    }

    public function test_una_copia_se_puede_borrar(): void
    {
        $copy = $this->duplicateOfSystem();

        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/receipt-templates/{$copy->id}")
            ->assertOk();

        $this->assertNull(ReceiptTemplate::find($copy->id));
    }

    public function test_las_del_sistema_no_se_borran(): void
    {
        // Son el punto de retorno cuando alguien deja la suya irreconocible.
        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/receipt-templates/{$this->systemTemplate()->id}")
            ->assertStatus(422);

        $this->assertNotNull(ReceiptTemplate::find($this->systemTemplate()->id));
    }

    public function test_no_se_borra_la_que_esta_en_uso(): void
    {
        $copy = $this->duplicateOfSystem();

        $this->actingAsTerminal($this->token)
            ->putJson("/api/receipt-templates/{$copy->id}", ['is_default' => true])
            ->assertOk();

        // Quedarse sin predeterminada deja la caja sin poder emitir, y eso se
        // descubre con un cliente delante.
        $this->actingAsTerminal($this->token)
            ->deleteJson("/api/receipt-templates/{$copy->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('receipt.default_template_kept'));
    }
}
