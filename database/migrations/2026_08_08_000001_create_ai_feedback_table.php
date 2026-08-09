<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correcciones del equipo a lo que contestó la IA (T5 de mejoras2.md del Komo).
 *
 * El agente que ve la respuesta mala es el único que tiene el contexto para
 * arreglarla, y ese agente está mirando el chat en el **Komo**, no acá. Por eso
 * el feedback entra por la API y se acumula en esta tabla.
 *
 * **`status` existe porque la cola de revisión es obligatoria.** Enchufar las
 * correcciones directo a la base de conocimiento la envenena: un agente apurado
 * escribe algo mal y la IA se lo repite a todos los clientes. Nada pasa a ser
 * conocimiento sin que un humano lo apruebe.
 *
 * `ai_text` y `question` se copian en vez de referenciar el mensaje: el
 * revisor necesita ver qué dijo la IA y a qué respondía, y para cuando revise
 * la conversación puede estar archivada o el mensaje borrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->uuid('conversation_id')->nullable()->index();

            // Referencia opaca al evento del sistema que lo reportó (en Komo,
            // el `lead_event_id`). Sirve para no duplicar el mismo voto.
            $table->string('external_ref', 64)->nullable();
            $table->string('source', 20)->default('komo');
            $table->string('reporter', 120)->nullable();

            $table->string('rating', 4); // up | down
            $table->text('ai_text')->nullable();
            $table->text('question')->nullable();
            $table->text('correction')->nullable();

            $table->string('status', 12)->default('pending'); // pending | applied | dismissed
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('document_id')->nullable();

            $table->timestamps();

            // Un mismo mensaje se vota una vez por cuenta: si el agente cambia
            // de opinión se actualiza la fila, no se acumulan votos.
            $table->unique(['account_id', 'external_ref']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
