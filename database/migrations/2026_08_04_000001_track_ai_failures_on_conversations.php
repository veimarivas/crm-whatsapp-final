<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué se apagó la IA en una conversación, y desde cuándo.
 *
 * `ai_autoreply_disabled` era un booleano pelado: se veía que la IA estaba
 * apagada pero no por qué, y como el job se apaga solo al fallar, el agente
 * encontraba una conversación muda sin ninguna explicación.
 *
 * `ai_failure_count` existe para no matar la IA por un tropiezo: la primera
 * consulta a Ollama carga el modelo en memoria y puede pasarse del timeout,
 * así que una falla aislada es lo más esperable del mundo — y hasta ahora
 * dejaba la conversación sin bot para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_failure_count')->default(0)->after('ai_reply_count');
            $table->string('ai_disabled_reason', 300)->nullable()->after('ai_autoreply_disabled');
            $table->timestamp('ai_disabled_at')->nullable()->after('ai_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['ai_failure_count', 'ai_disabled_reason', 'ai_disabled_at']);
        });
    }
};
