<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre');
            $table->string('simbolo');
            $table->enum('magnitud', ['longitud', 'electrico', 'presion', 'conteo', 'masa', 'volumen']);
            $table->string('uso')->default('atributo');
            $table->decimal('factor_a_referencia', 18, 6)->default(1);
            $table->unsignedTinyInteger('decimales')->default(0);
            $table->boolean('es_referencia')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nombre']);
            $table->unique(['tenant_id', 'simbolo']);
        });

        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nombre']);
        });

        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombre');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        // "Toyota" y "toyota" son la misma marca. El nombre se muestra como lo escribieron.
        Schema::getConnection()->statement(
            'create unique index marcas_tenant_nombre_unique on marcas (tenant_id, lower(nombre))'
        );

        Schema::create('categoria_atributos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('categoria_id');
            $table->string('clave');
            $table->string('etiqueta');
            $table->enum('tipo', ['decimal', 'texto', 'lista']);
            $table->boolean('obligatorio')->default(false);
            $table->unsignedInteger('orden')->default(0);
            $table->foreignId('unidad_medida_id')->nullable();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->jsonb('opciones')->nullable();
            } else {
                $table->json('opciones')->nullable();
            }

            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'categoria_id', 'clave']);
            $table->foreign(['tenant_id', 'categoria_id'])
                ->references(['tenant_id', 'id'])
                ->on('categorias')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'unidad_medida_id'])
                ->references(['tenant_id', 'id'])
                ->on('unidades_medida');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                alter table categoria_atributos add constraint categoria_atributos_unidad_check check (
                    (tipo in ('texto', 'lista') and unidad_medida_id is null)
                    or tipo = 'decimal'
                )
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                alter table categoria_atributos add constraint categoria_atributos_opciones_check check (
                    (tipo = 'lista' and opciones is not null)
                    or (tipo <> 'lista' and opciones is null)
                )
            SQL);
        }

        if ($driver === 'sqlite') {
            Schema::getConnection()->statement(<<<'SQL'
                create trigger categoria_atributos_reglas_insert
                before insert on categoria_atributos
                for each row
                when (new.tipo in ('texto', 'lista') and new.unidad_medida_id is not null)
                    or (new.tipo = 'lista' and new.opciones is null)
                    or (new.tipo <> 'lista' and new.opciones is not null)
                begin
                    select raise(abort, 'atributo de categoría inválido');
                end
            SQL);
            Schema::getConnection()->statement(<<<'SQL'
                create trigger categoria_atributos_reglas_update
                before update on categoria_atributos
                for each row
                when (new.tipo in ('texto', 'lista') and new.unidad_medida_id is not null)
                    or (new.tipo = 'lista' and new.opciones is null)
                    or (new.tipo <> 'lista' and new.opciones is not null)
                begin
                    select raise(abort, 'atributo de categoría inválido');
                end
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_atributos');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('marcas');
        Schema::dropIfExists('unidades_medida');
    }
};
