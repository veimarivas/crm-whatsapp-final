<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D1a — el motor de broadcasts deja de ser solo de plantillas.
 *
 * Komo tenía su propio motor de envíos que mandaba texto libre por
 * `/api/v1/messages`, sin plantilla y sin mirar la ventana de servicio. Para
 * que ese motor pueda morir, ESTE tiene que saber hacer las dos cosas:
 *
 *  - `body_type=template` — lo de siempre, plantilla aprobada de Meta. Sirve
 *    dentro y fuera de la ventana de 24 h, y se factura.
 *  - `body_type=text` — mensaje de sesión: texto libre (con imagen opcional),
 *    válido SOLO para quien está dentro de la ventana. Gratis.
 *
 * `template_name`/`template_language` pasan a nullable porque un broadcast de
 * texto no tiene plantilla. El default `'template'` deja a los broadcasts
 * existentes exactamente como estaban.
 *
 * `broadcast_recipients.phone` existe porque Komo direcciona por teléfono y su
 * audiencia puede incluir gente que este proyecto todavía no conoce (leads de
 * formulario web, de correo, importados). Antes esos destinatarios no se podían
 * ni representar: la fila exigía un `contact_id`.
 *
 * `external_ref` guarda el id del lead en Komo, para que el otro lado pueda
 * correlacionar la fila de vuelta sin adivinar por teléfono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('body_type', 10)->default('template')->after('name');
            $table->text('body_text')->nullable()->after('header_media_url');
            $table->string('body_media_path')->nullable()->after('body_text');
        });

        // Un broadcast de texto no tiene plantilla. `->change()` sobre MariaDB
        // se escribe a mano: es una sola sentencia y no depende de que el
        // driver infiera bien el tipo original.
        DB::statement('ALTER TABLE broadcasts MODIFY template_name VARCHAR(255) NULL');
        DB::statement("ALTER TABLE broadcasts MODIFY template_language VARCHAR(10) NULL DEFAULT 'en_US'");

        Schema::table('broadcast_recipients', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('contact_id');
            $table->string('external_ref')->nullable()->after('phone');
        });

        // Los destinatarios existentes se direccionaban por contacto; se les
        // copia el teléfono para que el job tenga una sola forma de leerlo y no
        // haya que preguntar «¿esta fila es vieja o nueva?» en cada envío.
        DB::statement('UPDATE broadcast_recipients r
            JOIN contacts c ON c.id = r.contact_id
            SET r.phone = COALESCE(c.phone_normalized, c.phone)
            WHERE r.phone IS NULL');
    }

    public function down(): void
    {
        Schema::table('broadcast_recipients', function (Blueprint $table) {
            $table->dropColumn(['phone', 'external_ref']);
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['body_type', 'body_text', 'body_media_path']);
        });
    }
};
