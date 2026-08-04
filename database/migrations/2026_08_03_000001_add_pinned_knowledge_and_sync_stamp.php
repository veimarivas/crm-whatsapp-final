<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            // Documento FIJO: entra completo en cada prompt, sin pasar por la
            // búsqueda. Es lo que arregla la alucinación de fondo — si el
            // catálogo vigente depende de que el retrieval lo encuentre, un
            // día no lo encuentra y el modelo se inventa el programa.
            $table->boolean('is_pinned')->default(false)->after('content');
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            // Cuándo se refrescó la oferta académica por última vez. Sin esto
            // no hay forma de saber si la IA está contestando con datos de
            // hoy o de hace tres semanas.
            $table->timestamp('knowledge_synced_at')->nullable()->after('after_hours_message');
        });
    }

    public function down(): void
    {
        Schema::table('ai_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn('knowledge_synced_at');
        });
    }
};
