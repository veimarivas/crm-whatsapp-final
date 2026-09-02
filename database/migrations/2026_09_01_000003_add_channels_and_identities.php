<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F0/T0.1 — el esquema deja de asumir que todo es WhatsApp.
 *
 * Nada cambia de comportamiento: todo lo existente queda marcado como
 * `channel = 'whatsapp'` y el default hace que siga siéndolo. Lo que se abre
 * es la posibilidad de que exista otra cosa.
 *
 * **Las cuatro decisiones que importan:**
 *
 * 1. **`contacts.phone` pasa a nullable.** Un contacto de Telegram no tiene
 *    teléfono, y hoy la columna es NOT NULL: `Contact::create` reventaría con
 *    un error de SQL. El `unique(account_id, phone_normalized)` tolera varios
 *    NULL en MariaDB, así que **no hay que inventar teléfonos sintéticos** — y
 *    no hay que inventarlos: uno falso rompería el merge de duplicados y los
 *    envíos masivos.
 *
 * 2. **`conversations` pasa a ser única por `(cuenta, contacto, canal)`.** Sin
 *    el canal en la clave, el mismo humano escribiendo por WhatsApp y por
 *    Telegram caería en un solo hilo y el `channel` de la conversación
 *    mentiría.
 *
 * 3. **`channel_conversation_id` es el id del hilo EN EL SISTEMA DEL CANAL**
 *    (chat_id de Telegram, PSID de Meta), no un uuid nuestro. Rellenarlo con
 *    el id propio lo volvería inútil justo para lo que sirve: encontrar la
 *    conversación cuando llega un webhook. Para WhatsApp el valor correcto es
 *    el teléfono normalizado.
 *
 * 4. **El backfill de identidades va ACÁ y no solo en un comando.** Si el
 *    deploy corre migraciones y nadie corre el comando, el primer mensaje de
 *    un contacto existente le crearía una identidad duplicada.
 *
 * ⚠️ **Los dos índices únicos pueden chocar contra datos históricos** y fallan
 * en producción, no en local. Por eso esta migración se **niega a correr** si
 * detecta duplicados, y dice qué comando los arregla.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertSinDuplicados();

        // ── contacts ───────────────────────────────────────────────────────
        DB::statement('ALTER TABLE contacts MODIFY phone VARCHAR(32) NULL');

        // ── conversations ──────────────────────────────────────────────────
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('channel', 20)->default('whatsapp')->after('status');
            $table->string('channel_conversation_id')->nullable()->after('channel');
        });

        // Backfill ANTES de crear los índices: si no, el unique se evalúa
        // contra filas a medio llenar.
        DB::statement("UPDATE conversations SET channel = 'whatsapp' WHERE channel IS NULL OR channel = ''");
        DB::statement('UPDATE conversations c
            JOIN contacts ct ON ct.id = c.contact_id
            SET c.channel_conversation_id = COALESCE(ct.phone_normalized, ct.phone)
            WHERE c.channel_conversation_id IS NULL');

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['account_id', 'channel', 'status']);
            $table->unique(['account_id', 'contact_id', 'channel']);
        });

        // ── messages ───────────────────────────────────────────────────────
        Schema::table('messages', function (Blueprint $table) {
            $table->string('channel', 20)->default('whatsapp')->after('conversation_id');
            $table->string('external_message_id')->nullable()->after('channel');
        });

        // La columna se llama `message_id`, no `wamid`: el id externo de Meta
        // ya estaba guardado ahí desde el primer día.
        DB::statement("UPDATE messages SET channel = 'whatsapp' WHERE channel IS NULL OR channel = ''");
        DB::statement('UPDATE messages SET external_message_id = message_id WHERE external_message_id IS NULL');

        Schema::table('messages', function (Blueprint $table) {
            $table->unique(['channel', 'external_message_id']);
        });

        // ── contact_identities ─────────────────────────────────────────────
        Schema::create('contact_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('external_id');
            $table->string('display_name')->nullable();
            $table->json('profile_data')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'channel', 'external_id']);
            $table->index(['contact_id', 'channel']);
        });

        // Identidad de WhatsApp para todo contacto que tenga teléfono. Va acá
        // y no solo en un comando: si nadie corre el comando, el primer mensaje
        // de un contacto existente le crearía una identidad duplicada.
        DB::statement("INSERT INTO contact_identities
                (id, account_id, contact_id, channel, external_id, display_name, is_primary, created_at, updated_at)
            SELECT UUID(), account_id, id, 'whatsapp', phone_normalized, name, 1, NOW(), NOW()
            FROM contacts
            WHERE phone_normalized IS NOT NULL AND phone_normalized <> ''");

        // ── channel_configs ────────────────────────────────────────────────
        Schema::create('channel_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->boolean('is_enabled')->default(false);
            // ⚠️ `text` y NO `json`: MariaDB le pone a las columnas `json` un
            // CHECK de «esto es JSON válido», y el cast `encrypted:array` guarda
            // un blob cifrado que obviamente no lo es. Con `json` la fila se
            // rechaza con «CONSTRAINT `channel_configs.credentials` failed»,
            // que no menciona el cifrado por ningún lado.
            $table->text('credentials')->nullable();   // cast `encrypted:array` en el modelo
            $table->json('settings')->nullable();      // este sí es JSON de verdad
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'channel']);
        });
    }

    /**
     * Una migración que revienta a la mitad deja la base en un estado que nadie
     * pidió. Es preferible no arrancar, diciendo exactamente qué hacer.
     */
    private function assertSinDuplicados(): void
    {
        $conversaciones = DB::table('conversations')
            ->select('account_id', 'contact_id')
            ->groupBy('account_id', 'contact_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $mensajes = DB::table('messages')
            ->select('message_id')
            ->whereNotNull('message_id')
            ->groupBy('message_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($conversaciones === 0 && $mensajes === 0) {
            return;
        }

        throw new RuntimeException(
            "No se puede migrar: hay datos que chocarían con los índices únicos nuevos.\n"
            ."  - contactos con más de una conversación: {$conversaciones}\n"
            ."  - message_id duplicados: {$mensajes}\n"
            ."Corré primero:  php artisan wacrm:channel-precheck        (para ver qué hay)\n"
            .'                php artisan wacrm:channel-precheck --fix  (para arreglarlo)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_configs');
        Schema::dropIfExists('contact_identities');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['channel', 'external_message_id']);
            $table->dropColumn(['channel', 'external_message_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'contact_id', 'channel']);
            $table->dropIndex(['account_id', 'channel', 'status']);
            $table->dropColumn(['channel', 'channel_conversation_id']);
        });
    }
};
