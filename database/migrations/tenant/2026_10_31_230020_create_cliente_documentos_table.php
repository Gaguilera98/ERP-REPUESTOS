<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('cliente_id');
            $table->unsignedBigInteger('tipo_documento_identidad_id');

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('valores_json')->default('{}');
            } else {
                $table->json('valores_json')->default('{}');
            }

            $table->string('identidad')->default('');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(
                ['tenant_id', 'tipo_documento_identidad_id', 'identidad'],
                'cliente_documentos_identidad_unique',
            );
            $table->unique(
                ['tenant_id', 'cliente_id', 'tipo_documento_identidad_id'],
                'cliente_documentos_tipo_por_cliente_unique',
            );
            $table->foreign(['tenant_id', 'cliente_id'])
                ->references(['tenant_id', 'id'])
                ->on('clientes')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'tipo_documento_identidad_id'])
                ->references(['tenant_id', 'id'])
                ->on('tipos_documento_identidad')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_documentos');
    }
};
