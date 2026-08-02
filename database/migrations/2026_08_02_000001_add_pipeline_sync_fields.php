<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vínculo con el pipeline del Komo (fuente de verdad) + cuál es el
        // default para crear deals nuevos desde WhatsApp.
        Schema::table('pipelines', function (Blueprint $table) {
            $table->uuid('external_id')->nullable()->after('id')->index();
            $table->boolean('is_default')->default(false)->after('name');
        });

        // stage_type replica el tipo del Komo (open/won/lost) y external_id
        // mantiene el vínculo estable con la etapa de allá.
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->uuid('external_id')->nullable()->after('id')->index();
            $table->string('stage_type', 10)->default('open')->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'is_default']);
        });

        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'stage_type']);
        });
    }
};
