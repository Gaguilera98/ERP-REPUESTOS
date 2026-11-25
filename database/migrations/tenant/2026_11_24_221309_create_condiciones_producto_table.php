<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condiciones_producto', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'codigo']);
            $table->unique(['tenant_id', 'nombre']);
        });

        Schema::table('productos_maestros', function (Blueprint $table) {
            $table->string('codigo_repuesto')->nullable();
            $table->index(['tenant_id', 'codigo_repuesto'], 'producto_codigo_repuesto_index');
        });

        Schema::table('productos_variantes', function (Blueprint $table) {
            $table->foreignId('condicion_producto_id')->nullable();
            $table->index(['tenant_id', 'condicion_producto_id'], 'producto_condicion_index');
            $table->foreign(['tenant_id', 'condicion_producto_id'], 'producto_condicion_tenant_foreign')
                ->references(['tenant_id', 'id'])->on('condiciones_producto')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('productos_variantes', function (Blueprint $table) {
            $table->dropForeign('producto_condicion_tenant_foreign');
            $table->dropIndex('producto_condicion_index');
            $table->dropColumn('condicion_producto_id');
        });
        Schema::table('productos_maestros', function (Blueprint $table) {
            $table->dropIndex('producto_codigo_repuesto_index');
            $table->dropColumn('codigo_repuesto');
        });
        Schema::dropIfExists('condiciones_producto');
    }
};
