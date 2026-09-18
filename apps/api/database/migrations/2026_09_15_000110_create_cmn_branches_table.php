<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sucursales.
 *
 * "Tenant" no existe en cherryPOS (P-05): una instalación pertenece a un solo
 * negocio y la jerarquía de configuración es **negocio → sucursal → terminal**.
 * La tabla existe igualmente porque `sucursal_id` va en toda entidad
 * transaccional desde la primera migración, aunque el primer cliente tenga un
 * solo local: agregarlo después obliga a migrar todas las claves foráneas.
 *
 * `erp_company_id` es una **referencia opaca** a la `Company` del ERP (Q-02).
 * El POS no la interpreta ni se vuelve multi-empresa por tenerla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmn_branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->string('legal_name', 160)->nullable();
            // RUC del negocio. Proviene de la licencia firmada y se imprime en
            // cada comprobante (Q-08): no es editable sin romper la firma.
            $table->string('tax_id', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 40)->nullable();
            // Zona horaria propia: casa matriz consolida sucursales que pueden
            // no compartirla.
            $table->string('timezone', 64)->default('America/Managua');
            // Casa matriz aloja el ERP; las demás sucursales solo el POS. Son
            // dos perfiles distintos de empaquetado y despliegue (P-05).
            $table->boolean('is_headquarters')->default(false);
            $table->unsignedInteger('erp_company_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmn_branches');
    }
};
