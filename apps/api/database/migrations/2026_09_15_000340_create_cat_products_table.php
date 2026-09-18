<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de ítems: productos y servicios en la misma tabla (D-07).
 *
 * La bandera `tracks_stock` es toda la diferencia. Un gimnasio vende planes y
 * toallas desde la misma pantalla; sin esto cada rubro de servicios necesitaría
 * su propio modelo. Costo casi nulo.
 *
 * **Atributos dinámicos (B-08 / D-24): híbrido.** `attributes` es una columna
 * JSON validada contra el `attribute_schema` del perfil de negocio. Cuando un
 * campo resulta muy consultado se **promueve a columna real** con su migración;
 * el EAV de OSPOS —tres tablas genéricas y un modelo de 1.235 líneas— se
 * descartó por indexable y consultable solo a fuerza de dolor.
 *
 * `cost` se recalcula según el método de costeo **configurable** (Q-02): manda
 * el sistema que se instaló primero. No está cableado a promedio ponderado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 50)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('cat_categories')->nullOnDelete();
            $table->foreignUuid('uom_id')->constrained('cat_uoms')->restrictOnDelete();
            $table->foreignUuid('tax_code_id')->nullable()->constrained('cat_tax_codes')->nullOnDelete();

            $table->decimal('price', 18, 4)->default(0);
            $table->decimal('cost', 18, 4)->default(0);
            // Producto exento por naturaleza del bien (Q-07). Sin esto el
            // vertical farmacia no opera.
            $table->boolean('is_exempt')->default(false);

            // Servicios: no descuentan existencias (D-07).
            $table->boolean('tracks_stock')->default(true);
            $table->boolean('tracks_lots')->default(false);     // G-07
            $table->boolean('allow_negative_stock')->default(false);
            $table->decimal('min_stock', 18, 4)->nullable();    // alerta, no reporte (D-20)

            // Producto compuesto: kit, combo o paquete (D-14). El detalle vive
            // en `cat_product_components`.
            $table->boolean('is_composite')->default(false);
            // Código de barras propio del combo: en caja se lee como pack,
            // muestra su detalle y su precio (G-09).
            $table->boolean('sells_as_pack')->default(false);

            $table->jsonb('attributes')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);

            // Referencia opaca al `Product` del ERP tras integrar (Q-03): el POS
            // no crea productos una vez integrado, los recibe como copia de
            // lectura.
            $table->unsignedInteger('erp_product_id')->nullable();

            $table->timestampsTz(6);

            $table->index('name');
            $table->index(['is_active', 'category_id']);
            $table->index('erp_product_id');
        });

        /**
         * Presentaciones alternativas del producto: `1 caja = 100 unidades`.
         * Mismo modelo de conversión que `ProductUom` del ERP (Q-02).
         */
        Schema::create('cat_product_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('cat_products')->cascadeOnDelete();
            $table->foreignUuid('uom_id')->constrained('cat_uoms')->restrictOnDelete();
            $table->unsignedInteger('conv_num')->default(1);
            $table->unsignedInteger('conv_den')->default(1);
            $table->boolean('is_sales_default')->default(false);
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->unique(['product_id', 'uom_id']);
        });

        /** Componentes de un kit, combo o paquete (D-14). */
        Schema::create('cat_product_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->constrained('cat_products')->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('cat_products')->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            // De dónde sale el stock: del kit como ítem propio o de sus partes.
            $table->boolean('deducts_component_stock')->default(true);
            // Si la factura lo muestra desglosado o solo como conjunto.
            $table->boolean('print_expanded')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);

            $table->unique(['parent_id', 'component_id']);
        });

        /**
         * Códigos de barras (B-04).
         *
         * Varios por producto, y con soporte para los códigos de balanza que
         * traen **peso o precio embebidos** — la venta a granel de cualquier
         * abarrotería. `embedded` declara qué parte del código es el dato.
         */
        Schema::create('cat_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('cat_products')->cascadeOnDelete();
            $table->foreignUuid('product_uom_id')->nullable()->constrained('cat_product_uoms')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->enum('embedded', ['none', 'weight', 'price'])->default('none');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz(6);

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_barcodes');
        Schema::dropIfExists('cat_product_components');
        Schema::dropIfExists('cat_product_uoms');
        Schema::dropIfExists('cat_products');
    }
};
