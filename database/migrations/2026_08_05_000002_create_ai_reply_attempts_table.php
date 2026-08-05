<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué decidió el bot con cada mensaje.
 *
 * Todas las formas en que la IA no contesta se ven IGUAL desde afuera: no
 * llega nada. Se calló porque estaba en pausa, porque había un flow activo,
 * porque el modelo estaba ocupado, porque descartó una respuesta vieja, porque
 * falló… y en todos los casos el síntoma es el mismo silencio. Sin registro,
 * cada diagnóstico es una conjetura nueva.
 *
 * Una fila por intento, con el motivo. Es la diferencia entre "no responde" y
 * "no respondió porque X a las 07:42".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reply_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->uuid('conversation_id')->index();
            $table->string('decision', 40);
            $table->string('detail', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reply_attempts');
    }
};
