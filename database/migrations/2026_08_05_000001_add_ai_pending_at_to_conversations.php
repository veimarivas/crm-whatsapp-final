<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desde cuándo la IA está "pensando" en esta conversación.
 *
 * `ai_pending` es un booleano que enciende el job al arrancar y apaga al
 * terminar. El agujero: si al job lo MATAN (se pasa del timeout del worker,
 * un OOM, un reinicio en pleno despliegue) el `catch` nunca corre y la
 * bandera queda encendida para siempre — la burbuja «Pensando respuesta…»
 * girando eternamente, en el wacrm y en Komo.
 *
 * Con la marca de tiempo se puede barrer lo que quedó colgado: sin ella no hay
 * forma de distinguir un job que sigue trabajando de uno que murió hace horas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_pending_at')->nullable()->after('ai_pending');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_pending_at');
        });
    }
};
