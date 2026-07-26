<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca para avisar UNA sola vez que la IA agotó su tope de respuestas en
 * una conversación. Sin esto, cada mensaje nuevo del cliente volvería a
 * notificar al responsable: el tope se sigue superando en cada entrante.
 *
 * Se resetea junto con ai_reply_count cuando se reactiva la IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_limit_notified_at')->nullable()->after('ai_reply_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_limit_notified_at');
        });
    }
};
