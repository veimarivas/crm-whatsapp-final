<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2a — etiquetas y campos personalizados dejan de tener catálogo propio.
 *
 * Komo pasa a ser el dueño de la taxonomía, igual que ya lo es de los
 * pipelines, y `external_id` (el uuid de allá) es la correspondencia. Hasta
 * acá los dos proyectos tenían catálogos separados que NO se sincronizaban:
 * una etiqueta puesta en el inbox no existía en Komo y viceversa.
 *
 * **`external_id` nullable no es un detalle**: una fila con `external_id` en
 * NULL es una etiqueta LOCAL, que este proyecto crea y el sync no puede
 * borrar. Es lo que permite que una etiqueta en uso sobreviva a que la borren
 * del otro lado (ver `TaxonomySyncController`).
 *
 * El unique es por cuenta y tolera múltiples NULL (MariaDB), así que no hace
 * falta un índice parcial —que MariaDB no tiene— para permitir varias
 * etiquetas locales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->uuid('external_id')->nullable()->after('account_id');
            $table->unique(['account_id', 'external_id']);
        });

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->uuid('external_id')->nullable()->after('account_id');
            $table->unique(['account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'external_id']);
            $table->dropColumn('external_id');
        });

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
