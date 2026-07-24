<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas de voz internas: además del texto, una nota puede tener un audio
 * grabado por el agente para dejar contexto entre turnos. El archivo se
 * guarda localmente en storage/app/public/voice-notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->string('audio_path', 500)->nullable()->after('note_text');
        });
    }

    public function down(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->dropColumn('audio_path');
        });
    }
};
