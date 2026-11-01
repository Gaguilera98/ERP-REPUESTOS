<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento_identidad', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('codigo');
            $table->string('nombre');
            $table->boolean('para_persona_natural')->default(true);
            $table->boolean('para_empresa')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'codigo']);
        });

        Schema::create('tipo_documento_atributos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('tipo_documento_identidad_id');
            $table->string('clave');
            $table->string('etiqueta');
            $table->enum('tipo', ['decimal', 'texto', 'lista']);
            $table->boolean('obligatorio')->default(false);
            $table->boolean('identifica')->default(false);
            $table->unsignedInteger('orden')->default(0);

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('opciones')->nullable();
            } else {
                $table->json('opciones')->nullable();
            }

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'tipo_documento_identidad_id', 'clave'], 'tipo_doc_atributos_clave_unique');
            $table->foreign(['tenant_id', 'tipo_documento_identidad_id'])
                ->references(['tenant_id', 'id'])
                ->on('tipos_documento_identidad')
                ->cascadeOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table tipo_documento_atributos add constraint tipo_documento_atributos_opciones_check check (
                    (tipo = 'lista' and opciones is not null)
                    or (tipo <> 'lista' and opciones is null)
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_documento_atributos');
        Schema::dropIfExists('tipos_documento_identidad');
    }
};
