<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausa temporal de la IA al agotar su tope de respuestas.
 *
 * Antes el tope era inalcanzable: `InboundProcessor` reseteaba
 * `ai_reply_count` a 0 con CADA mensaje entrante, así que el "máximo N por
 * conversación" de Ajustes no limitaba nada. Ahora el contador se acumula y,
 * al llegar al tope, la IA queda en pausa hasta `ai_paused_until`; cuando esa
 * hora pasa el contador se reinicia solo y la IA vuelve a responder.
 *
 * La ventana es configurable por cuenta (`auto_reply_cooldown_hours`) porque
 * cuánto tarda un contacto en volver a escribir depende del negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_paused_until')->nullable()->after('ai_limit_notified_at');
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_reply_cooldown_hours')->default(3)->after('auto_reply_max_per_conversation');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_paused_until');
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn('auto_reply_cooldown_hours');
        });
    }
};
