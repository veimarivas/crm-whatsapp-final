<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\AutoTagRule;
use App\Models\Contact;
use App\Models\ContactCustomValue;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D2a — la taxonomía (etiquetas y campos personalizados) pasa a tener un solo
 * dueño: Komo. Hasta acá los dos proyectos tenían catálogos separados que NO
 * se sincronizaban, a diferencia de los pipelines.
 *
 * Lo que fijan estos tests, en orden de importancia:
 *
 *  1. **Una etiqueta en uso NO se borra.** `auto_tag_rules.tag_id` es
 *     `cascadeOnDelete`: borrarla se lleva puesta la regla de auto-etiquetado
 *     sin un solo aviso, y el equipo descubriría meses después que dejó de
 *     funcionar. Se desvincula y sigue siendo local.
 *  2. Los catálogos que ya existían de los dos lados se **enlazan**, no se
 *     duplican ni se pisan: las asociaciones a contactos sobreviven.
 *  3. `dry_run` dice qué haría sin tocar nada — es lo que hace segura la
 *     primera pasada en producción.
 */
class TaxonomySyncTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
        $this->account = Account::create(['name' => 'Empresa', 'owner_user_id' => $owner->id]);
        $owner->update(['account_id' => $this->account->id, 'account_role' => User::ROLE_OWNER]);

        [, $this->token] = ApiKey::issue($this->account->id, $owner->id, 'komo', ['conversations:write']);
    }

    private function sync(array $tags = [], array $fields = [], bool $dryRun = false)
    {
        return $this->withToken($this->token)->postJson('/api/v1/taxonomy/sync', [
            'tags' => $tags,
            'custom_fields' => $fields,
            'dry_run' => $dryRun,
        ]);
    }

    public function test_crea_las_etiquetas_que_no_existen(): void
    {
        $id = (string) Str::uuid();

        $this->sync(tags: [['id' => $id, 'name' => 'Interesado', 'color' => '#ff0000']])
            ->assertOk()
            ->assertJsonPath('tags.created', ['Interesado']);

        $tag = Tag::forAccount($this->account->id)->firstOrFail();

        $this->assertSame('Interesado', $tag->name);
        $this->assertSame($id, $tag->external_id);
        $this->assertTrue($tag->isManagedByKomo());
    }

    public function test_enlaza_por_nombre_normalizado_en_vez_de_duplicar(): void
    {
        // La etiqueta ya existía acá, con otra caja y espacios de más, y ya
        // etiqueta a alguien. Ese vínculo es lo que no se puede perder.
        $local = Tag::create(['account_id' => $this->account->id, 'name' => '  interesado ']);
        $contacto = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001', 'name' => 'Ana']);
        $local->contacts()->attach($contacto->id);

        $id = (string) Str::uuid();

        $this->sync(tags: [['id' => $id, 'name' => 'Interesado', 'color' => '#00ff00']])
            ->assertOk()
            ->assertJsonPath('tags.linked.0.external_id', $id)
            ->assertJsonPath('tags.created', []);

        // Una sola etiqueta, la de siempre, ahora con el nombre de Komo.
        $this->assertSame(1, Tag::forAccount($this->account->id)->count());

        $local->refresh();
        $this->assertSame($id, $local->external_id);
        $this->assertSame('Interesado', $local->name);
        $this->assertSame(1, $local->contacts()->count());
    }

    public function test_una_etiqueta_con_regla_de_auto_tag_no_se_borra_se_desvincula(): void
    {
        $id = (string) Str::uuid();

        $tag = Tag::create(['account_id' => $this->account->id, 'external_id' => $id, 'name' => 'Precio']);
        $regla = AutoTagRule::create([
            'account_id' => $this->account->id,
            'tag_id' => $tag->id,
            'keyword' => 'cuanto cuesta',
        ]);

        // Komo ya no la reporta: la borraron allá.
        $this->sync(tags: [])
            ->assertOk()
            ->assertJsonPath('tags.deleted', [])
            ->assertJsonPath('tags.kept_in_use.0.name', 'Precio')
            ->assertJsonPath('tags.kept_in_use.0.auto_tag_rules', 1);

        // Sigue existiendo, ahora como local, y la regla sigue viva. Si se
        // hubiera borrado, la cascada se habría llevado la regla y el
        // auto-etiquetado habría dejado de funcionar en silencio.
        $this->assertNotNull($tag->fresh());
        $this->assertNull($tag->fresh()->external_id);
        $this->assertNotNull($regla->fresh());
    }

    public function test_una_etiqueta_con_contactos_tampoco_se_borra(): void
    {
        $id = (string) Str::uuid();

        $tag = Tag::create(['account_id' => $this->account->id, 'external_id' => $id, 'name' => 'VIP']);
        $contacto = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001', 'name' => 'Ana']);
        $tag->contacts()->attach($contacto->id);

        $this->sync(tags: [])
            ->assertOk()
            ->assertJsonPath('tags.kept_in_use.0.contacts', 1);

        $this->assertNotNull($tag->fresh());
        $this->assertSame(1, $contacto->tags()->count());
    }

    public function test_una_etiqueta_sincronizada_y_sin_uso_si_se_borra(): void
    {
        $tag = Tag::create(['account_id' => $this->account->id, 'external_id' => (string) Str::uuid(), 'name' => 'Sobrante']);

        $this->sync(tags: [])
            ->assertOk()
            ->assertJsonPath('tags.deleted', ['Sobrante']);

        $this->assertNull($tag->fresh());
    }

    public function test_una_etiqueta_local_nunca_la_toca_el_sync(): void
    {
        // Sin `external_id`: la creó este proyecto. Komo no la conoce y no
        // puede borrar lo que no es suyo.
        $local = Tag::create(['account_id' => $this->account->id, 'name' => 'Solo de acá']);

        $this->sync(tags: [['id' => (string) Str::uuid(), 'name' => 'Otra']])->assertOk();

        $this->assertNotNull($local->fresh());
        $this->assertNull($local->fresh()->external_id);
    }

    public function test_dry_run_informa_y_no_toca_nada(): void
    {
        $tag = Tag::create(['account_id' => $this->account->id, 'external_id' => (string) Str::uuid(), 'name' => 'Sobrante']);
        Tag::create(['account_id' => $this->account->id, 'name' => 'Interesado']);

        $nuevo = (string) Str::uuid();

        $this->sync(
            tags: [['id' => $nuevo, 'name' => 'Interesado'], ['id' => (string) Str::uuid(), 'name' => 'Nueva']],
            dryRun: true,
        )
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('tags.created', ['Nueva'])
            ->assertJsonPath('tags.deleted', ['Sobrante'])
            ->assertJsonPath('tags.linked.0.name', 'Interesado');

        // Nada cambió: es lo único que hace segura la primera pasada.
        $this->assertSame(2, Tag::forAccount($this->account->id)->count());
        $this->assertNotNull($tag->fresh());
        $this->assertNull(Tag::forAccount($this->account->id)->where('name', 'Interesado')->value('external_id'));
    }

    public function test_los_campos_personalizados_se_sincronizan_igual(): void
    {
        $id = (string) Str::uuid();

        $this->sync(fields: [[
            'id' => $id,
            'name' => 'Carrera',
            'field_type' => 'select',
            'options' => ['MBA', 'Derecho'],
        ]])->assertOk()->assertJsonPath('custom_fields.created', ['Carrera']);

        $field = CustomField::forAccount($this->account->id)->firstOrFail();

        $this->assertSame('Carrera', $field->field_name);
        $this->assertSame('select', $field->field_type);
        $this->assertSame(['MBA', 'Derecho'], $field->field_options);
        $this->assertSame($id, $field->external_id);
    }

    public function test_un_campo_con_valores_cargados_no_se_borra(): void
    {
        $field = CustomField::create([
            'account_id' => $this->account->id,
            'external_id' => (string) Str::uuid(),
            'field_name' => 'Carrera',
        ]);

        $contacto = Contact::create(['account_id' => $this->account->id, 'phone' => '584125550001', 'name' => 'Ana']);

        ContactCustomValue::create([
            'contact_id' => $contacto->id,
            'custom_field_id' => $field->id,
            'value' => 'MBA',
        ]);

        $this->sync(fields: [])
            ->assertOk()
            ->assertJsonPath('custom_fields.kept_in_use.0.values', 1);

        // El dato cargado no lo tiene nadie más: borrar el campo lo tiraría.
        $this->assertNotNull($field->fresh());
        $this->assertNull($field->fresh()->external_id);
    }

    public function test_una_etiqueta_de_komo_no_se_edita_ni_se_borra_desde_aca(): void
    {
        $owner = User::where('account_id', $this->account->id)->firstOrFail();
        $tag = Tag::create(['account_id' => $this->account->id, 'external_id' => (string) Str::uuid(), 'name' => 'Interesado']);

        // Un cambio hecho acá lo pisaría el próximo sync: la pantalla estaría
        // prometiendo algo que no sobrevive. Mejor decir dónde se hace.
        $this->actingAs($owner)->from('/contacts')
            ->patch("/tags/{$tag->id}", ['name' => 'Otro nombre'])
            ->assertSessionHasErrors('name');

        $this->actingAs($owner)->from('/contacts')
            ->delete("/tags/{$tag->id}")
            ->assertSessionHasErrors('name');

        $this->assertSame('Interesado', $tag->fresh()->name);
    }

    public function test_una_etiqueta_local_si_se_edita(): void
    {
        $owner = User::where('account_id', $this->account->id)->firstOrFail();
        $local = Tag::create(['account_id' => $this->account->id, 'name' => 'Solo de acá']);

        // Crear y corregir una etiqueta propia sigue permitido: un agente que
        // necesita marcar algo en el momento no puede quedar bloqueado.
        $this->actingAs($owner)->from('/contacts')
            ->patch("/tags/{$local->id}", ['name' => 'Corregida'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Corregida', $local->fresh()->name);
    }

    public function test_exige_el_scope(): void
    {
        $owner = User::where('account_id', $this->account->id)->firstOrFail();
        [, $sinScope] = ApiKey::issue($this->account->id, $owner->id, 'limitada', ['contacts:read']);

        $this->withToken($sinScope)
            ->postJson('/api/v1/taxonomy/sync', ['tags' => [], 'custom_fields' => []])
            ->assertForbidden();
    }
}
