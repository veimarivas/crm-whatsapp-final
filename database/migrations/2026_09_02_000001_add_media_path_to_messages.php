<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1.c — adjuntos de canales que no se pueden servir por proxy.
 *
 * `media_url` guarda el identificador del archivo **en el sistema del canal**:
 * el `media_id` de Meta o el `file_id` de Telegram. Con Meta alcanza, porque su
 * archivo se resuelve en vivo con el token de la cuenta cada vez que alguien lo
 * mira.
 *
 * **Con Telegram no alcanza.** Su link de descarga caduca y —lo importante—
 * **lleva el bot token adentro**, así que no se puede ni guardar ni exponer al
 * navegador: quien lo viera tendría control total del bot. La única salida es
 * bajar el archivo a almacenamiento propio, y para eso hace falta dónde anotar
 * la copia.
 *
 * `media_path` es esa copia; `media_mime` evita tener que adivinar el tipo al
 * servirla. Los mensajes de WhatsApp los dejan en NULL y siguen resolviéndose
 * por proxy exactamente como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('media_url');
            $table->string('media_mime', 100)->nullable()->after('media_path');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime']);
        });
    }
};
