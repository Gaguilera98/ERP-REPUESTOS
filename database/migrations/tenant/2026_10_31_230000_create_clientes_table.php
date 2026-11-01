<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->enum('tipo_cliente', ['persona_natural', 'empresa'])->default('persona_natural');
            $table->string('nombres')->nullable();
            $table->string('apellidos')->nullable();
            $table->string('razon_social')->nullable();
            $table->enum('tipo_precio', ['menor', 'mayor'])->default('menor');
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->text('direccion')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
