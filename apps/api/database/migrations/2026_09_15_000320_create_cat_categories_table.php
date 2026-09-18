<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Categorías de producto. Jerárquicas y con color, que el perfil táctil usa para la cuadrícula. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // La clave foránea a sí misma se agrega después de crear la tabla:
            // dentro del mismo `CREATE TABLE` la clave primaria todavía no
            // existe para PostgreSQL.
            $table->uuid('parent_id')->nullable();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });

        Schema::table('cat_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('cat_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_categories');
    }
};
