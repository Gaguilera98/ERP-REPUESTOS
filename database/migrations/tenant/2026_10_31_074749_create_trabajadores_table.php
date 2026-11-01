<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajadores', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('cedula')->nullable();
            $table->string('celular')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'cedula']);
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trabajadores');
    }
};
