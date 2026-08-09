<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posición de cada paso en el lienzo.
 *
 * `position` ya existía pero es el **orden de ejecución** dentro de su rama,
 * no una coordenada: son dos cosas distintas y mezclarlas haría que mover una
 * tarjeta cambie el orden en que corre el workflow. `flow_nodes` ya tenía
 * `position_x`/`position_y`; se usan los mismos nombres acá para que el lienzo
 * compartido lea lo mismo en los dos editores.
 *
 * Nullable a propósito: un paso sin coordenadas cae en el **layout automático**
 * del árbol, así que las automatizaciones que ya existen siguen viéndose bien
 * sin migrar datos ni amontonarse en el origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_steps', function (Blueprint $table) {
            $table->integer('position_x')->nullable()->after('position');
            $table->integer('position_y')->nullable()->after('position_x');
        });
    }

    public function down(): void
    {
        Schema::table('automation_steps', function (Blueprint $table) {
            $table->dropColumn(['position_x', 'position_y']);
        });
    }
};
