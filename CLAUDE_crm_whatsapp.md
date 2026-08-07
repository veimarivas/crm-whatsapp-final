# CRM de WhatsApp — Laravel 13 + MariaDB

Port completo de **wacrm** (CRM de WhatsApp original en Next.js 16 + Supabase, en `C:\xampp_82_12\htdocs\wacrm-main`) a **Laravel 13 + Inertia.js + React 18 + MariaDB 10.11** (XAMPP, PHP 8.3).

## Estado: port completo + mejoras (2026-07-10)

Suite de tests: **82 tests / 418 aserciones en verde** (`php artisan test`).

Ronda 18 — Automatizaciones con lienzo de workflow, plantillas y simulador (2026-08-07):

**El problema no era el motor sino la puerta de entrada.** El builder era una lista de tarjetas con botones de "añadir" al final: para entender qué hacía una automatización había que abrirla, y no había forma de probarla sin activarla y mandarle un WhatsApp real a alguien.

- **Galería de recetas** (`Services\Automations\Recipes.php`): 6 automatizaciones ya armadas (bienvenida, precios, seguimiento 24 h, etiquetar interesados, derivar a asesor, ruta por interés). `GET /automations/new?recipe=<slug>` **precarga el formulario sin guardar nada** — sigue siendo un borrador hasta que el usuario da "Crear". Ninguna receta referencia etiquetas por id (el id es por cuenta y quedaría colgado); los pasos de etiqueta se dejan sin elegir y el nodo se marca incompleto.
- **`/automations` — resumen en lenguaje natural**: el index dejó de ser tabla y pasó a tarjetas con la línea `CUANDO <disparador> → ENTONCES <paso> → <paso>`. Para eso el controller ahora manda `root_steps` (tipo + config de los pasos raíz) además de `steps_count`.
- **Editor como lienzo de workflow vertical** (`Automations/Edit.jsx` reescrito): nodo disparador arriba con chips CUANDO/ENTONCES/FIN, pasos encadenados por conectores con un **`+` entre cada par** que inserta en esa posición exacta (antes solo se podía añadir al final y reordenar con flechas), condiciones abiertas en dos carriles Sí/No, panel lateral pegajoso con nombre/descripción/guardar y un contador de "pasos sin completar". Cada nodo dice qué le falta (`stepProblem`) en vez de fallar en silencio en producción.
- **Simulador — `POST /automations/simulate`** (`Services\Automations\Simulator.php`): recorre el árbol **que está en pantalla** (no lo guardado, así se prueba antes de guardar y antes de activar) y devuelve qué pasaría paso a paso. **No envía WhatsApp, no etiqueta, no llama webhooks, no escribe logs.** Interpola el texto con un contacto real elegido del desplegable, marca la rama no tomada como `skipped`, corta en el `wait` marcando lo posterior como `later`, y señala los pasos que fallarían por config incompleta.
- **`Conditions::evaluate()` extraído del Engine** y `Engine::matchesTrigger()` hecho público/estático: el simulador usa EXACTAMENTE el mismo código que la ejecución real. Si esto se duplicara, la prueba diría una cosa y producción haría otra — que es justo lo que hace inútil a un simulador.
- **Trampa**: `/automations/simulate` vive en el grupo `web`, donde `shouldRenderJsonWhen` (bootstrap/app.php) solo cubre `api/*` — un `$request->validate()` fallido devuelve **302, no 422**, y axios sigue el redirect y recibe HTML. Por eso el controller valida con `Validator::make` y arma el 422 a mano.
- Sin migraciones. Tests nuevos en `AutomationBuilderTest` (9).
- **Dev**: `.claude/launch.json` + `.claude/serve-router.php` (router de `php -S` con la ruta pública fija en vez de `getcwd()`, para levantar este proyecto desde el cwd del Komo).

Mismo tratamiento a **`/flows`** (chatbots), donde el problema era peor porque un flow es un **grafo**, no una lista:

- **4 plantillas de chatbot** (`Services\Flows\Recipes.php`): menú principal, calificar al interesado, capturar datos (demo de variables) y preguntas frecuentes. `FlowController::TEMPLATE_NODES` (la única plantilla hardcodeada que había) se movió acá como `Recipes::DEFAULT`; el modal de creación ahora es un selector de plantillas y `POST /flows` acepta `recipe`.
- **Los nodos se ordenan por recorrido, no por `created_at`**: `orderGraph()` en el front hace un BFS desde `entry_node_id`, así el editor se lee como la conversación. Lo que el BFS no alcanza se agrupa en **«pasos sueltos»** con aviso — el bug clásico de un flow (un nodo que existe pero al que no llega ningún camino) antes era invisible.
- **«+ nuevo» en cada conexión**: crea el nodo destino y lo enchufa en un solo gesto. Antes había que crear el nodo, bajar a buscarlo y recién ahí elegirlo en el `<select>` del nodo origen.
- **Renombrar un nodo reapunta las conexiones que lo señalaban** (`rewireTo()`) — antes cambiarle el nombre a un nodo rompía el flow en silencio y solo se veía al guardar.
- **Cada nodo dice qué le falta** (`nodeProblems`): sin texto, sin variable, opción sin título, conexión a un nodo inexistente. Y el `id` de botones/filas se deriva del título en vez de pedirlo aparte (era un campo que no significaba nada para el usuario).
- **Chat de prueba — `POST /flows/simulate`** (`Services\Flows\Simulator.php`): se **conversa** con el bot en un chat de verdad, con burbujas y botones clicables, sobre el grafo **que está en pantalla**. Es **sin estado**: el front manda el array de respuestas del cliente y el backend reproduce la conversación entera desde la entrada, así no se crean `flow_runs` ni hace falta guardar. Muestra las variables capturadas, los reintentos, los ciclos y las conexiones rotas. `set_tag` y `http_fetch` se anotan como simulados: **no etiqueta ni llama a la URL de verdad**.
- El simulador replica las reglas del Runner (match de botón por id **o** por título exacto, interpolación de variables + contacto, reprompts y `on_exhaust`, tope de saltos). Es duplicación consciente y anotada: el Runner trabaja sobre modelos persistidos y el simulador sobre arrays sueltos. **Si cambia una regla del Runner hay que tocar el simulador** — los tests de `FlowBuilderTest` fijan los casos que importan.
- Sin migraciones. Tests nuevos en `FlowBuilderTest` (14); suite total **351/351 (1234)**.

Ronda 17 — Rebranding ESAM CONECTA y teléfonos visibles (2026-08-06):

- **Logo `conecta.png`** (`public/conecta.png`, trackeado en git): reemplaza a `logo_esam.png`/`esam_pequenio.png` en el Login (`GuestLayout.jsx`) y en el sidebar (`AuthenticatedLayout.jsx`).
- **Login**: la tarjeta ahora muestra el logo `conecta.png` a `h-28` ANTES del título, título nuevo **"Bienvenido a ESAM CONECTA"** (antes "¡Bienvenido al CRM WhatsApp!"), y se quitó el subtexto "Inicia sesión para continuar". Se eliminó el logo del banner fuera del formulario en `GuestLayout` (y su `import { Link }` quedó fuera de uso y se limpió).
- **Sidebar**: logo `conecta.png` a `h-12`, **centrado** (el header del sidebar usa `justify-center` fijo en vez del condicional por `sidebarCollapsed`), y el texto al lado ahora dice **"ESAM CONECTA"** (antes "CRM Whatsapp").
- **Inbox `/inbox` — número debajo del nombre** en la lista de conversaciones (`Inbox/Index.jsx`): nueva línea `<p className="text-[11px] text-gray-400 font-mono truncate">{conv.contact?.phone}</p>` entre el nombre y el último mensaje. `conv.contact.phone` ya venía con el eager load (`contact:id,name,phone,avatar_url`).
- **Pipelines `/pipelines` — número en cada deal** (`Pipelines/Index.jsx`): se agregó el teléfono debajo del nombre/contacto en la tarjeta (`DealCard`) y en la vista de fila (`DealRow`): `<p className="text-[10px] text-gray-400 font-mono truncate">{deal.contact?.phone}</p>`.
- Ninguna migración, solo JS/CSS. El build de producción va en el servidor (`/public/build` está en `.gitignore`).

Ronda 16 — Feedback "IA pensando" también en Komo (2026-07-24/25):

- **Nuevo evento `ai.pending_changed`** (`Services/Webhooks/Dispatcher::EVENTS`): dispatch desde `AiAutoReplyJob@broadcastPending()` cada vez que se enciende/apaga `ai_pending`. Payload minimal: `{conversation_id, pending: bool}`. Consumido por Komo para pintar la misma burbuja violeta "Pensando respuesta…" en el chat del lead. Requiere activar el evento en el webhook saliente del wacrm hacia Komo (chip nuevo en Ajustes → Equipo → Webhooks, o vía tinker actualizando el array `events` del `WebhookEndpoint`).
- Best-effort en el dispatch: si el webhook falla, el bot igual sigue (Log::warning y continúa).

Ronda 15 — Horarios de atención + productividad y analytics (2026-07-24):

- **Auto-responder fuera de horario** (`ai_configs.business_hours`+`after_hours_message`+`timezone`, migración `2026_07_24_000001`): `AiConfig::isWithinBusinessHours()` chequea si el momento actual cae en algún rango del array `{mon:[["08:00","19:00"]], tue:[...], ...}`. Sin config = 24/7. Si está fuera de horario y hay `after_hours_message`, `AiAutoReplyJob` envía ese texto (una vez por conv por día, cacheado con key `after_hours_sent:{convId}:{date}`) y NO consume Ollama. Evita respuestas raras de la IA de madrugada. UI en `Settings/Ai.jsx` con grilla 7 días + inputs de hora + textarea.
- **API endpoints para consumo del Komo**:
    - `POST /api/v1/messages/media` (scope `messages:write`): envía archivo por WhatsApp desde base64. Komo lo usa desde el composer del chat para adjuntar files/audios.
    - `GET /api/v1/quick-replies` (scope `messages:write`): lista plantillas compartidas del equipo. Komo carga el dropdown de plantillas desde acá.
    - `GET /api/v1/media/{id}` (scope `conversations:read`): descarga binaria del media por su Meta media_id. Verifica que pertenezca a la cuenta. Komo lo usa detrás del proxy `/leads/media/{id}` para reproducir audios/imágenes/videos sin problemas de CORS.
- **`InboundProcessor` incluye `media_id`** en el payload del webhook `message.received` (además del texto/wamid). Komo lo guarda en el payload del evento y con eso puede reproducir el media.
- **Exportar contactos a CSV** — endpoint `GET /contacts/export?q=&tag_id=` (`ContactController@exportCsv`). Stream chunked (500 por lote) con BOM UTF-8 para que Excel abra bien. Respeta los mismos filtros que el index. Formato: ID/Nombre/Teléfono/Email/Empresa/Tags/Creado con separador `;`.
- **Analytics de tiempo de respuesta** (`AiController@responseTime`, ruta `/settings/response-time`): query con LATERAL JOIN (MariaDB 10.5+) que para cada mensaje de cliente en los últimos 30 días busca el próximo msg de agente/bot en la misma conv y calcula el diff en segundos. Agrupa por agente (o bot), muestra count/promedio/mediana. Página `Settings/ResponseTime.jsx` con 3 KPI cards + tabla con medallas 🥇🥈🥉 y badges verdes <60s / ámbar <5min / rojos ≥5min. Incluye ✨IA como agente virtual.
- **Segmentación avanzada de broadcasts** (`Services/Broadcasts/Creator.php`): además de `audience` + `tag_ids`, ahora acepta `conv_status` (open/pending/closed), `last_message_days` (X → contactos con último msg > X días), `source: 'ad'` (contactos con `entry_ad_id`). Se combinan con AND. Backend + validación listos; la UI de creación aún renderiza solo `audience+tag_ids` (los nuevos campos se aceptan por API pero no hay inputs — pendiente pulido si se usa mucho).
- **Notas de voz internas** (`contact_notes.audio_path`, migración `2026_07_24_000002`): además del texto, una nota puede tener un audio grabado por el agente. `InboxController@addNote` acepta multipart con campo `audio` (mimetypes ogg/mpeg/webm/wav, max 5MB), lo guarda en `storage/app/public/voice-notes/`. El frontend (panel notas del contacto en Inbox) tiene ahora un botón 🎤 al lado de "Guardar nota" que abre el `VoiceRecorder` existente y sube el ogg como nota. Las notas con audio muestran un `<audio controls>` compacto. Requiere `php artisan storage:link`.

Ronda 14 — UX de la IA (feedback + fallback) y productividad del agente (2026-07-23/24):

- **Feedback visual "IA pensando…"** — nueva columna `conversations.ai_pending` (bool, migración `2026_07_23_000001`). El `AiAutoReplyJob` la enciende al arrancar y la apaga al terminar (o fallar). En el Inbox aparece una burbuja violeta con dots animados y texto "Pensando respuesta…" mientras dure. Se limpia solo con el próximo poll/Reverb.
- **Typing indicator real a WhatsApp** — `MetaApi::sendTypingIndicator($wamid)` piggy-back del markAsRead con `typing_indicator: {type:'text'}`. El cliente ve el "escribiendo…" nativo de WhatsApp ~25s mientras la IA genera. Best-effort: si falla no bloquea al bot (log info y sigue).
- **Fallback automático de IA** — `AiAutoReplyJob@deliverFallback`: si Ollama falla o tarda >120s (timeout del Client), envía al cliente `"Un asesor te atenderá en breve. Gracias por tu paciencia. 🙏"`, apaga `ai_autoreply_disabled=true` en esa conv (evita loops), y crea un `Notification` tipo `ai_fallback` al `assigned_agent_id` (o al owner si no hay). El agente ve la campana con badge y toma la conv manualmente.
- **Plantillas rápidas** (`quick_replies` — migración `2026_07_23_000002`): tabla con `account_id`, `user_id` (null = compartida con equipo), `shortcut`, `content`. Modelo `QuickReply@render(Contact)` sustituye `{name}` `{phone}` `{email}` `{company}`. Controller CRUD + página `Settings/QuickReplies.jsx`. Integrado en el composer del Inbox de 2 formas: (1) botón 📋 abre dropdown con todas; (2) autocomplete `/xxx` al tipear filtra por shortcut. Al insertar se hacen las sustituciones automáticamente.
- **Búsqueda full-text** — migración `2026_07_23_000003` agrega índice FULLTEXT en `messages.content_text` (`ALTER TABLE messages ADD FULLTEXT INDEX messages_content_fulltext`). Endpoint `GET /inbox/search?q=xxx` (`InboxController@search`): modo booleano con comodines, respeta restricción por rol (agent solo ve sus asignadas), snippet resaltado en el content con `«»`, agrupa por conversación. En la sidebar aparece una sección "🔍 Encontrados en el historial" arriba del listado normal cuando el user tipea ≥3 chars (debounce 350ms).
- **Auto-close conversaciones inactivas** — comando `wacrm:auto-close-inactive {--days=7} {--dry-run}`. Cierra convs `status=open` sin `last_message_at` en 7 días. Se reabren solas cuando el cliente vuelve a escribir (InboundProcessor pone status=open). Agendado 03:00 AM cada día en Schedule.
- **Auto-tag por keywords** — tabla `auto_tag_rules` (migración `2026_07_23_000004`): `keyword`, `tag_id`, `first_message_only`, `is_active`. Modelo `AutoTagRule`. `InboundProcessor@applyAutoTags` matchea case-insensitive substring en cada mensaje del cliente y agrega los tags al Contact con `syncWithoutDetaching` (idempotente). Con `first_message_only=true` solo aplica en el 1er mensaje (evita re-taggear). Página `Settings/AutoTags.jsx` con CRUD + toggle activar/pausar. Item nuevo "Auto-tags" en el sidebar.
- **Estadísticas de IA** — nueva página `Settings/AiStats.jsx` (ruta `/settings/ai/stats`): 5 cards con contadores (respuestas 7d/30d/total, fallbacks 30d, tasa de éxito %); gráfico de barras violeta con respuestas por día (últimos 14 días); lista de últimas 30 preguntas de clientes con IA activa (para detectar temas repetidos que agregar al knowledge base).
- **Whisper transcripción de audios** — migración `2026_07_23_000005` agrega `messages.transcript` (text nullable). Nuevo `TranscribeAudioJob` (dispatched desde InboundProcessor cuando llega audio): descarga el archivo desde Meta, convierte OGG→WAV con ffmpeg (necesario, WhatsApp manda opus y whisper.cpp sin decoder falla silencioso), corre whisper.cpp con `-otxt -nt`, guarda transcript. Config en `services.whisper` (env `WHISPER_BINARY`, `WHISPER_MODEL`, `WHISPER_LANGUAGE=es`). En el servidor: whisper.cpp compilado con cmake, `libwhisper.so`/`libggml.so` en `/opt/whisper.cpp/build/bin/`, registradas en `/etc/ld.so.conf.d/whisper.conf` + `ldconfig`. Debajo del reproductor de audio en el Inbox aparece la transcripción con ícono 🎙 y borde izquierdo.
- **Webhook `message.transcribed`** — cuando `TranscribeAudioJob` termina bien, dispara este evento con `{conversation_id, message: {id, wamid, transcript, sender_type}}`. Komo lo usa para actualizar el payload del evento previo (por wamid) y mostrar el texto en su chat.
- **TTS lectura en voz alta** — botón 🔊 al hover de cada burbuja con texto o audio-con-transcript. Usa `window.speechSynthesis` (Web Speech API nativo del navegador) con `lang: 'es-BO', rate: 1.05`. Singleton `ttsState.current` para poder parar el actual con un segundo click al mismo mensaje. Mientras lee, el ícono cambia a ⏸ y pulsa en verde.
- **Bulk actions en el Inbox** — endpoint `POST /inbox/bulk-action` con `{conversation_ids[], action: close|open|pending|mark_read|assign, agent_id?}`. Solo admin/owner (los agents solo ven sus asignadas, no tiene sentido bulk). Frontend: checkbox por conversación en la sidebar (visible solo para admin), fila queda verde soft cuando seleccionada, aparece barra inferior flotante con contador + botones ✓ Leídas / Cerrar / Pend. / Asignar... / Cancelar.
- **Métricas de broadcasts** — `BroadcastController@metrics` (ruta `/broadcasts-metrics`) → suma agregada de counters de todos los broadcasts sent/sending, tasas globales (entrega, lectura, respuesta, fallo), gráfico 30 días con 3 barras/día (sent, delivered, read), top 10 campañas por reply_rate. Página `Broadcasts/Metrics.jsx`.
- **Detección y merge de contactos duplicados** — `ContactMergeController`: detecta grupos por email idéntico O nombre normalizado (LOWER(TRIM)) con `GROUP BY key HAVING COUNT>1`. Endpoint `POST /contacts/merge` con `{primary_id, duplicate_ids[]}`: en transacción atómica, completa campos vacíos del primary con datos de los duplicados, mueve conversations/tags/notes/deals al primary, borra duplicados. Página `Contacts/Duplicates.jsx` con radio buttons para elegir el primary y confirm antes de fusionar.
- **API v1 extendida para consumo de Komo**:
    - `POST /api/v1/messages/media` — envía archivo por WhatsApp (base64 + mime + filename + caption). Scope `messages:write`.
    - `GET /api/v1/quick-replies` — lista plantillas compartidas del equipo. Scope `messages:write`.
    - `GET /api/v1/media/{id}` — descarga binaria del media por Meta media_id (verifica que pertenezca a la cuenta). Scope `conversations:read`. Usado por el proxy de Komo `/leads/media/{id}` para servir audios/imágenes/videos sin problemas de CORS.
- **`InboundProcessor` — orden de jobs reordenado para bajar delay a Komo**: antes `AiAutoReplyJob` se encolaba ANTES que los `Dispatcher::dispatch` de webhooks → Komo veía los mensajes 60s tarde (esperando a Ollama). Ahora: webhooks primero (`contact.created`, `message.received` con `media_id` incluido), luego flows/automations, `AiAutoReplyJob` AL FINAL. Komo ve mensajes en 1-3s. `TranscribeAudioJob` también se despacha justo antes del AiAutoReply si `content_type=audio`.

Ronda 13 — IA sobre BD académica ESAM + performance/UX (2026-07-22/23):

- **Conexión BD secundaria `esam_datos`** (`config/database.php`): apunta al MariaDB `esam_datos` en localhost, credenciales por defecto reutilizan `whatsapp_user` (SOLO SELECT en esa BD para seguridad). Env opcional `ESAM_DB_*` para override. Se registra al lado de la conexión principal `mysql`.
- **Artisan `wacrm:sync-oferta-academica {--account=?} {--dry-run}`** (`app/Console/Commands/SyncOfertaAcademica.php`): lee `programas` con `estado_id=4` (Inscripciones) joinea `tipos`, y por cada programa saca `modulos` con `docentes` y `horarios` `estado='Confirmado'` (limit 50/módulo, orden cronológico). Genera **UN documento por programa** con formato estructurado (metadata + MÓDULOS + horarios listados). Cada corrida borra los docs con prefijo `[OFERTA] ` de esa cuenta y regenera desde cero. IMPORTANTE: si no se pasa `--account`, usa el primer account por `created_at` — esto puede ser una cuenta distinta a la de la conversación productiva (bug real que sufrimos). El cron debe pasar el UUID explícito. Sugerido: `*/30 * * * * cd /var/www/crm-whatsapp && php artisan wacrm:sync-oferta-academica --account=<UUID>`.
- **Prompt IA endurecido** (`Services/Ai/ReplyGenerator@buildSystemPrompt`): 11 reglas estrictas. Solo responde con la info del `system_prompt` + base de conocimiento; rechaza temas ajenos con frase específica; rechaza programas no listados; listar programas SOLO por nombre (sin códigos ni precios); nombres de docente sin correo; horarios TODOS literales sin inventar días de la semana; sin markdown; nunca menciona que es IA ni datos técnicos.
- **RAG afinado** — 3 cambios acoplados: `Chunker::MAX_CHUNK` 800→**3000** (un programa entero cabe en 1-2 chunks para que Qwen tenga toda la info del programa junta); `ReplyGenerator` trae 6→**15 chunks**; historial 12→**20 mensajes**, truncado 1000→**2000 chars**/msg; `Client::chat` maxTokens 500→**800**.
- **Ollama parámetros críticos** (`Services/Ai/Client@ollama`): agregado `num_ctx: 16384` (default de Ollama es 4096 — se quedaba cortísimo con nuestro RAG y truncaba silenciosamente) y `temperature: 0.2` (más determinístico, menos alucinaciones de fechas/precios).
- **Fix delay Komo — reordenación de jobs en `InboundProcessor`**: la cola es FIFO y `AiAutoReplyJob` puede tardar 30-60s con Qwen2.5:7b. Antes se encolaba ANTES que los `Dispatcher::dispatch(message.received)` → los webhooks a Komo esperaban 60s. Ahora: webhooks a integraciones se encolan PRIMERO (`contact.created`, `message.received`), luego flows/automatizaciones, AI al final. Impacto: Komo ve el mensaje del cliente en 1-3s en vez de 60s. La respuesta de la IA sigue tardando lo que tarda Ollama (limitación del modelo).
- **Cola `--sleep=3` → `--sleep=1`** (systemd `crm-whatsapp-queue.service`): el worker chequea la cola cada 1s en lugar de 3s. Combinado con el reorder de arriba, los webhooks salen en 1-2s.
- **Sidebar**: texto `"CRM Whatsapp"` al lado del logo ESAM (oculto cuando `sidebarCollapsed=true`).
- **Login**: título `"Bienvenido al CRM WhatsApp"`, quitadas las redes sociales y el link "Regístrate", footer minimalista `© 2026 Derechos reservados` (sin "Crafted with ♥" ni nombre de app).
- **Inbox — toggle lista de conversaciones**: chevron ◀/▶ en el header de "Conversaciones" y hamburguesa ☰ en el header del chat. Al colapsar, la lista se reemplaza por una barra angosta de 40px con badge de no leídos. Chat + panel del contacto ocupan el ancho restante — vista tipo WhatsApp para conversaciones largas.
- **Trampa conocida**: si un doc del knowledge base tiene el account_id mal (ej. importer se corrió sin `--account` y agarró otra cuenta), la IA no encuentra info y "inventa" respuestas. Verificar con: `AiKnowledgeDocument::where('account_id', <UUID>)->count()`. Si es 0, re-ejecutar el importer con `--account=<UUID>`.

Ronda 12 — Autor del mensaje visible en el chat (2026-07-22):

- **`Message::sender()`** (`app/Models/Message.php`): nueva relación `belongsTo(User::class, 'sender_id')` — apunta al agente autor del mensaje cuando `sender_type=agent`. Los mensajes con `sender_type=bot` (IA) no tienen sender_id.
- **`InboxController@messages`**: eager-load `sender:id,name,account_role` junto con reactions/replyTo.
- **`Inbox/Index.jsx` — `senderLabel(msg)` + label sobre la burbuja**: sobre cada burbuja saliente aparece el autor:
    - `✨ IA` (violeta) para `sender_type=bot`.
    - `{Nombre}` para agents, con sufijo `· Admin` cuando el rol es owner/admin.
    - Los mensajes del cliente no muestran label (avatar VR/etc. a la izquierda ya identifica al contacto).
    - Layout: envolví el bubble en un `flex flex-col ${isCustomer ? 'items-start' : 'items-end'}` para que la etiqueta se alinee con el borde de la burbuja.
- **Payload `message.sent` enriquecido** (`Messenger::dispatchOutbound` + `InboxController@send`): agrega `sender_name` y `sender_role` al webhook. Para IA/bot el sender es null; para agent viene del `User` con id `message.sender_id` (o `$request->user()` en el path del Inbox). Cambio COMPATIBLE — receptores viejos que ignoren los campos nuevos siguen funcionando.

Ronda 11 — Integración con Komo (asignación centralizada) (2026-07-21/22):

- **Nuevos scopes** (`TeamController@storeApiKey` + `Settings/Team.jsx` ALL_SCOPES): `team:write` (crear users) y `conversations:write` (reasignar/toggle IA). Las API keys viejas NO se actualizan solas — hay que **crear una nueva key con todos los scopes y rotarla** en el Komo.
- **`Api\V1\TeamApiController`** (nuevo, `routes/api.php`):
    - `POST /api/v1/team/provision` (scope `team:write`): idempotente por email. Si el user existe en la MISMA cuenta actualiza el rol; en OTRA cuenta devuelve 409. Sin password genera una random. Lo consume el Komo cuando alguien acepta una invitación allá para crear el mismo user en el wacrm.
    - `PATCH /api/v1/conversations/{id}/assign` (scope `conversations:write`): asigna por email (busca el user en la misma cuenta) o desasigna con null. Lo llama Komo al cambiar el responsable del lead.
    - `PATCH /api/v1/conversations/{id}/ai-mode` (scope `conversations:write`): body `{ai_enabled: bool}`, actualiza `ai_autoreply_disabled` y resetea `ai_reply_count`. Espeja el toggle IA/Humano del Komo.
- **Webhook `message.sent`** (`Services/Webhooks/Dispatcher::EVENTS`): nuevo evento que dispara `Messenger::sendText/sendMedia/sendInteractive` (helper privado `dispatchOutbound()`) + también `InboxController@send` (que no pasa por Messenger). Payload: `{conversation_id, contact, message:{id,type,text,wamid,sender_type}}` — `sender_type` distingue `agent` vs `bot` (IA). Komo lo usa para registrar los mensajes salientes en el timeline del lead.
- **Restricción por rol en Inbox** (`InboxController@conversations` + `authorizeConversation`): agent/viewer solo ven las conversaciones asignadas a ellos (`assigned_agent_id = user.id`). admin/owner ven todo. El chequeo en `authorizeConversation` cubre todos los endpoints (send, sendMedia, react, notes, aiDraft, status, assign, ai-mode).
- **Dropdown asignar → badge readonly** (`Inbox/Index.jsx`): el select "Sin asignar" del header del chat es ahora un badge no-editable con tooltip "La asignación se cambia desde el lead en Komo". Un solo lugar para asignar (Komo) → cero duplicación.
- **Editor de webhooks salientes** (`TeamController@updateWebhook` + `Settings/Team.jsx`): botón ✏️ ámbar al lado del toggle Activo/Inactivo. Click → la fila se expande a form inline (URL + chips de eventos). El secreto NO se cambia. Ruta `PATCH /settings/team/webhooks/{webhook}` (`team.webhooks.update`).
- **Rediseño página aceptar invitación** (`Invitations/Accept.jsx`): reemplazo de los componentes Breeze viejos (labels invisibles sobre fondo azul) por card blanca estilo Login con ícono amarillo, gradiente marca, chip verde del rol.
- **Fix reset contador IA** (`InboundProcessor`): cada mensaje NUEVO del cliente resetea `ai_reply_count = 0`. Antes, si el cliente escribía después de que la IA ya había respondido 3 veces (default de `auto_reply_max_per_conversation`), la IA no respondía nunca más hasta togglear IA off/on. Ahora el máximo protege contra ráfagas (loops del cliente), no contra la conversación completa.

Ronda 10 — Producción, Ollama y rediseño Inbox (2026-07-20/21):

- **Despliegue en producción**: `https://crm-whatsapp.posgradosinnovaciencia.com` en VPS Ubuntu. Servicio systemd `crm-whatsapp-queue.service` (User=www-data, `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`) + cron root `* * * * * cd /var/www/crm-whatsapp && php artisan schedule:run`. Reverb NO corre en el servidor → `BROADCAST_CONNECTION=log` en `.env` (sin eso, el observer `InboxUpdated` truena la transacción de InboundProcessor porque intenta conectar a Reverb en 127.0.0.1:8080). Cookie: `SESSION_COOKIE=wacrm_session`.
- **Ollama como tercer proveedor de IA** (`app/Services/Ai/Client.php`): método `ollama()` que llama a `POST {base_url}/api/chat` con `stream:false` y `options.num_predict = maxTokens`; timeout 120s (el 1er request carga el modelo y tarda). Migración `2026_07_20_000001` añade `ai_configs.base_url` (nullable). Migración `2026_07_20_000002` cambia `ai_configs.api_key` a NULL (sin dbal, `DB::statement('ALTER TABLE ai_configs MODIFY api_key TEXT NULL')`) — Ollama corre local y no requiere clave. `AiController@update` acepta provider `ollama` y omite la validación de api_key para él. `Settings/Ai.jsx`: opción "Ollama (local)", campo `base_url` con default `http://127.0.0.1:11434`, oculta el input de API key cuando provider=ollama. Modelo probado en prod: `qwen2.5:7b`.
- **Mensajes de voz** (`opus-recorder` en `package.json`): componente `VoiceRecorder` en `Inbox/Index.jsx` que graba con `new Recorder({encoderPath, encoderApplication: 2049, encoderSampleRate: 48000, numberOfChannels: 1, streamPages: false})`. El `ondataavailable` entrega Uint8Array que envuelvo en `Blob([data], {type:'audio/ogg'})` — Meta solo acepta ogg/opus. Sube al endpoint existente `inbox.send-media` (el `Messenger::sendMedia` deduce el tipo por MIME y devuelve `audio`). Estados: idle → recording (contador rojo) → preview (playback + enviar/descartar) → sending. Import del worker con `import encoderPath from 'opus-recorder/dist/encoderWorker.min.js?url'` para que Vite lo empaquete y sirva.
- **Rediseño Inbox estilo Velzon** (`Inbox/Index.jsx` completo): layout 3 columnas — (1) sidebar de conversaciones con avatares generados por hash del nombre (8 gradientes AVATAR_COLORS), buscador, tabs de filtro por estado en grid 4-col (label arriba, contador abajo para que no se desnivele), badges de no-leídos con gradiente emerald→teal; (2) chat central con burbujas rounded-2xl agrupadas por día vía `dayLabel()` (Hoy/Ayer/nombre), separadores `DateSeparator`, header con avatar grande + `StatusBadge` + selects de asignar/estado + toggle IA/Humano, composer con adjuntar/voz/emoji-picker/IA/enviar, Enter=enviar Shift+Enter=nueva línea; (3) panel derecho colapsable con datos del contacto (avatar XL, nombre/tel/email truncados), notas internas, metadatos de la conversación. Paleta marca `from-[#045474] to-[#1c486c]` mantenida.
- **Toggle IA/Humano por conversación** (`inbox.ai-mode`, PATCH `/inbox/conversations/{c}/ai-mode` con `{ai_enabled:bool}`): cambia `ai_autoreply_disabled` y resetea `ai_reply_count` al reactivar IA. **Cambio de comportamiento importante**: `InboxController@send` **YA NO** apaga automáticamente la IA cuando el agente responde manualmente — el control es explícito con el toggle del header. Botón violeta "IA activa" con dot pulsante emerald / blanco "Humano". Solo se muestra si `hasAi=true`. Si querés restaurar el handoff automático, hay que volver a añadir el `update(['ai_autoreply_disabled' => true])` después del sendText en `InboxController@send`.
- **Dashboard — bug de keys arreglado** (`DashboardController`): las stats se llamaban `broadcastsSent`, `activeAutomations`, `activeFlows`, pero el frontend leía `broadcasts`, `automations`, `flows`, `pending` — la sección "Actividad" mostraba 0. Renombré las claves en el controller para que coincidan. Nueva métrica `aiReplies` = mensajes con `sender_type='bot'` en los últimos 7 días → card violeta "Respuestas IA (7 días)" en el Dashboard.
- **Auto-creación de deals para leads nuevos** (`InboundProcessor::createLeadDeal()`): cuando `$isNewContact === true`, busca el primer pipeline de la cuenta (`orderBy('created_at')`) y su primera etapa (`orderBy('position')`) y crea un `Deal` con `status='open'`, título = `contact->name ?: contact->phone`. Idempotente: no crea si ya hay un deal `open` para ese contacto. Si la cuenta no tiene pipeline, se omite silenciosamente.

Ronda 10 — Pipelines: filtros server-side + espejo de etapa Komo→wacrm (2026-07-31):

- **Filtros de `/pipelines` pasaron de cliente a servidor** (`PipelineController@index`), igual que `/leads` del Komo: query params `responsible` (con `none` = sin asignar), `status` y `q` que persisten en la URL; `Pipelines/Index.jsx` usa `applyFilter()`/debounce con `router.get` en vez de filtrar en memoria. El select de responsable solo se muestra a admins (`isAdmin`, servido por el controller). Tests: `PipelineServerFilteringTest`.
- **Espejo de etapa Komo→wacrm**: el Komo es la fuente de verdad del pipeline. `Lead::moveToStage` (Komo) dispara `SyncLeadStageToWacrmJob` → `PATCH /api/v1/conversations/{id}/stage` (wacrm, scope `conversations:write`, `TeamApiController@setConversationStage`). El wacrm mapea la etapa por nombre dentro del pipeline del deal; si no existe (Komo siembra "Ganado"/"Perdido" que acá no se crean), los estados terminales caen a la última etapa y `status` se actualiza a `won`/`lost`. Sin esto los deals quedaban clavados en la primera etapa y la columna no coincidía con Komo. Tests: `ApiConversationStageTest` (wacrm) y `LeadStageSyncTest` (Komo).

Ronda 9 — Equipo centralizado (2026-07-19, Fase 7 del Komo Hub) — suite 90/90 (474):

- **`ProvisionController` extendido**: acepta `account_id` (existente) + `account_role` (`owner|admin|agent|viewer`). Si llegan, el user se une a la cuenta remota con ese rol (patrón MemberProvisioner del hub); si no, comportamiento original (owner de cuenta nueva). Test `ProvisionMemberTest`.

Ronda 8 — Notificaciones consolidadas (2026-07-19, Fase 5 del Komo Hub) — suite 89/89 (468):

- **`GET /api/v1/notifications`** con nuevo scope `notifications:read` (agregado a `ApiKey::SCOPES` — TeamController y ProvisionController validan el scope nuevo). Devuelve `{data:[{id,type,title,body,link_path,created_at,read_at}]}` filtrado por el `created_by` de la key (el user "hub"), con `?since=` y `?limit=`. `link_path` = `/inbox?conversation={id}` o `/notifications`. Test en `NotificationsApiTest`.
- **`SsoController@consume` acepta `?next=`** (path relativo, misma-host): tras el login redirige a la ruta destino en vez del dashboard. El hub encadena el salto con la ruta del deep-link — un solo clic desde la campana consolidada aterriza al usuario en la conversación exacta ya logueado.

Ronda 7 — Provisión del ecosistema (2026-07-16, Fase 3 del Komo Hub) — suite 88/88 (463):

- **`POST /api/v1/provision`** (`Api\V1\ProvisionController`, sin api.key): firmado HMAC del body con `HUB_PROVISION_SECRET` (`.env` + `services.hub.provision_secret`, mismo valor en las 4 apps). Crea user+account (idempotente por email; password aleatoria si no llega — acceso vía SSO), emite API key con los scopes pedidos y registra/actualiza el webhook saliente hacia el komo (URL+secret que manda el hub). Tests en `ProvisionTest`.
- ✅ e2e verificado (2026-07-19): el 404 intermitente que aparecía en pruebas locales anteriores era por procesos `php artisan serve` huérfanos escuchando el mismo puerto — matarlos con `Stop-Process` antes de levantar. Aprovisionamiento completo 7/7 pasos OK.

Ronda 6 — SSO del ecosistema (2026-07-16, Fase 2 del Komo Hub en `C:\xampp_82_12\htdocs\laravel_nuevo_proyecto`):

- **`SsoController@consume`** (ruta pública `GET /sso/consume`, `APP_ID='wacrm'`): acepta tokens de un solo uso del hub — firma HMAC (`HUB_SSO_SECRET` en `.env` y `services.hub.sso_secret`, mismo valor en las 4 apps), expiración 60s, nonce anti-replay en cache. Login por email + regenerate → dashboard.
- **`SESSION_COOKIE=wacrm_session`** en `.env` (las cookies no se aíslan por puerto en localhost). Tests en `SsoConsumeTest` — suite total **85/85 (442)**.

Ronda 5 — atribución de anuncios Click-to-WhatsApp (2026-07-15):

- **`/api/v1/contacts` acepta `?tag_id=`** (filtro server-side por tag) — lo usa meta_ads para armar Custom Audiences sin paginar el catálogo completo. Test en `ApiContactsTagFilterTest`.
- **Referral de Meta capturado**: migración 2026_07_15 añade `messages.referral` (json, cast array) y `conversations.entry_ad_id` (indexado). `InboundProcessor` extrae `$msg['referral']` ({source_id: AD_ID, headline, source_url…}) y lo guarda en el mensaje; `entry_ad_id` solo se escribe la PRIMERA vez (preserva la atribución original — referrals posteriores no lo pisan).
- **Webhook saliente enriquecido**: el evento `message.received` ahora incluye `message.referral` en el payload — el komo lo guarda como `leads.source_ref` y meta_ads calcula ROAS con eso. Sin cambios de contrato para receptores que lo ignoren.
- Tests en `MessageReferralTest` (guardado, preservación de atribución, payload del webhook).

Ronda 3 (2026-07-11):

- **Encabezados multimedia en broadcasts**: `broadcasts.header_media_url` (migración 2026_07_11) + componente header en `SendBroadcastJob::buildComponents` (link que Meta descarga; tipo tomado del `header_type` de la plantilla, cacheado por job). El creador de broadcasts pide la URL cuando la plantilla elegida tiene header image/video/document.
- **Creación de broadcasts extraída** a `Services/Broadcasts/Creator.php` (lanza InvalidArgumentException con mensaje de usuario) — compartida por BroadcastController y la API.
- **API pública broadcasts**: GET/POST `/api/v1/broadcasts` + GET `/{id}` (con `recipients_by_status`), scopes nuevos `broadcasts:read` / `broadcasts:write`.
- **Presencia**: heartbeat `POST /presence/ping` desde el layout cada 60s (online = visto <2 min); puntito verde/gris en Settings/Team.
- El usuario también rediseñó `AuthenticatedLayout.jsx` (sidebar colapsable) — el heartbeat de presencia vive ahí; cuidado al editarlo.

Ronda 4 — rediseño Velzon (2026-07-11):

- Estilo aplicado a todas las páginas nuevas: cards `rounded-2xl shadow-sm border border-gray-100`, iconos en cajas de gradiente con `shadow-lg`, header con `text-2xl sm:text-3xl font-bold` + subtítulo `text-sm text-gray-400`, botones primarios con gradiente + `shadow-lg shadow-{color}-500/20`, tablas con `bg-gray-50/80` header y filas `hover:bg-gray-50` con acciones que aparecen en hover (`opacity-0 group-hover:opacity-100`).
- Paleta: marca `from-[#045474] to-[#1c486c]`, emerald (positive), blue (info), purple (data), amber (warning), rose/red (danger). Cada tipo de entidad tiene su gradiente característico consistente entre páginas.
- Badges de estado con formato `inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ring-1` + dot de color.
- Rediseñadas: Templates/Index, Broadcasts/{Index,Show,Create}, Automations/{Index,Edit,Logs}, Flows/{Index,Edit,Runs}, Pipelines/Index (Kanban con drag&drop + gradientes por etapa), Notifications/Index, Settings/{WhatsApp,Ai,Team}. Los rediseñados por el usuario (Dashboard, Contacts/Index) no se tocaron.

Ronda 2 de mejoras (2026-07-11):

- **Webhooks salientes**: `Services/Webhooks/Dispatcher.php` (eventos `message.received`, `contact.created`, `broadcast.completed`) + `Jobs/DeliverWebhookJob` (POST JSON firmado HMAC-SHA256 en `X-Webhook-Signature`; 10 fallos consecutivos → autodesactivación). Disparo desde InboundProcessor, ContactController y SendBroadcastJob. CRUD en Settings/Team (secreto `whsec_…` mostrado una vez). Ojo en tests: los stubs de `Http::fake` se acumulan y el primero que coincide gana.
- **Rate limiting**: limiters en AppServiceProvider — `whatsapp-webhook` 600/min por IP, `public-api` 120/min por API key (aplicados en rutas).
- **Inbox**: responder citando (`reply_to_message_id` + context wamid a Meta), reacciones del agente (endpoint `inbox.react`, emojis rápidos al hover), panel de notas internas del contacto (`contact_notes` por fin con UI, endpoints `inbox.notes`).
- El usuario rediseñó Dashboard.jsx y Contacts/Index.jsx con su propio estilo (gradientes/iconos SVG) — respetar ese estilo al tocar esas páginas.

Mejoras aplicadas sobre el port base:

- **Tiempo real (Reverb)**: evento `App\Events\InboxUpdated` (ShouldBroadcastNow, canal privado `account.{accountId}` en routes/channels.php) emitido desde observers en AppServiceProvider (Message::created, Conversation::updated) envueltos en `rescue()` — si Reverb está caído no rompe nada. Cliente: `resources/js/echo.js` (Echo+pusher-js), el inbox escucha y refetchea; polling de respaldo a 30s. Arrancar con `php artisan reverb:start` (4º proceso).
- **Media desde inbox**: `MetaApi::uploadMedia/sendMedia`, `Messenger::sendMedia` (tipo deducido del MIME), endpoint `inbox.send-media`, botón 📎.
- **Embeddings semánticos**: `Services/Ai/Embeddings.php` (OpenAI text-embedding-3-small), Chunker vectoriza al indexar si hay `embeddings_api_key`, ReplyGenerator hace ranking coseno en PHP (límite 500 chunks, umbral 0.2) con fallback léxico. Botón "Reindexar" en Settings/Ai.
- **Kanban**: reordenar etapas (↑↓, `stages.move` intercambia posiciones). **Ownership**: `team.members.transfer` (solo owner; el anterior pasa a admin).

## Entorno

- BD: `laravel_crm_whatsapp` (root sin contraseña). Tests contra `laravel_crm_whatsapp_test` (MySQL, **no** sqlite — falta el driver pdo_sqlite; configurado en `phpunit.xml`).
- Usuario de pruebas: `admin@gmail.com` / `admin123` (owner de su cuenta).
- Operación local: `php artisan serve` + `php artisan queue:work` (cola database) + `php artisan schedule:work` (broadcasts programados, waits de automatizaciones, timeouts de flows).
- Frontend: `npm run build` (Vite). Si `npm install` falla, usar `--legacy-peer-deps`.
- Env WhatsApp: `META_APP_SECRET` (firma HMAC del webhook) y `META_GRAPH_VERSION` en `.env`; credenciales del número se guardan por cuenta en Ajustes → WhatsApp.

## Arquitectura

**Multi-tenant por `account_id`** (reemplazo del RLS de Supabase): todas las tablas de datos llevan `account_id`; el trait `App\Models\Concerns\BelongsToAccount` da `forAccount()`. **Toda query debe pasar por ese scope** — los controladores validan pertenencia con `abort_if($model->account_id !== $request->user()->account_id, 403)`.

- UUIDs en todas las PK (`HasUuids`). `users` fusiona el antiguo `profiles` (account_id, account_role: owner/admin/agent/viewer con jerarquía en `User::hasRoleAtLeast()`).
- Laravel 13 usa atributos PHP `#[Fillable]` / `#[Hidden]` en los modelos.
- Secretos (token WhatsApp, API key IA, secretos de webhooks) con cast `encrypted` (usa APP_KEY; rotarla invalida los tokens guardados).
- El pivot `contact_tags` usa PK compuesta (attach() no rellena uuid id).
- Campos nullable validados: leer con `?? null` del array validado.

### Módulos y piezas clave

| Módulo           | Backend                                                                                                                                                                                                                                                                                                                                                                      | UI (resources/js/Pages)                                                           |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| WhatsApp core    | `Services/WhatsApp/{MetaApi,InboundProcessor,Messenger}.php`; webhook `/webhooks/whatsapp` (GET verify + POST HMAC, sin CSRF en bootstrap/app.php); proxy media `/whatsapp/media/{id}`                                                                                                                                                                                       | Settings/WhatsApp.jsx                                                             |
| Inbox            | `InboxController` (polling JSON cada 4s, enviar, asignar→notifica, estado, borrador IA)                                                                                                                                                                                                                                                                                      | Inbox/Index.jsx                                                                   |
| Contactos        | `ContactController` (dedup por `phone_normalized` único por cuenta, import CSV), tags, custom fields                                                                                                                                                                                                                                                                         | Contacts/Index.jsx                                                                |
| Plantillas       | `TemplateController` (sync/submit con Meta vía WABA ID)                                                                                                                                                                                                                                                                                                                      | Templates/Index.jsx                                                               |
| Broadcasts       | `BroadcastController` + `Jobs/SendBroadcastJob` (variables {name},{phone},{email},{company}); `broadcasts:process-scheduled` cada minuto                                                                                                                                                                                                                                     | Broadcasts/\*.jsx                                                                 |
| Pipelines        | `Pipeline/Stage/DealController` (crear siembra 5 etapas; drag&drop = PATCH solo stage_id)                                                                                                                                                                                                                                                                                    | Pipelines/Index.jsx (DnD HTML5 nativo)                                            |
| Automatizaciones | `Services/Automations/Engine.php` — triggers inbound_message/new_contact/keyword; pasos send_message/add_tag/remove_tag/condition/wait/webhook; waits → `automation_pending_executions` reanudadas por `automations:process-pending`; eventos vía `ProcessAutomationEventJob` (post-commit)                                                                                  | Automations/{Index,Edit,Logs}.jsx (árbol children_yes/children_no, máx 3 niveles) |
| Flows (chatbot)  | `Services/Flows/Runner.php` — nodos send_message/send_buttons/send_list/collect_input/condition/set_tag/http_fetch/handoff/end; edges por node_key en config; **un run activo por contacto** (columna única `active_contact_key`, mantenida en `FlowRun::booted()`); reprompts→on_exhaust; idempotencia por wamid; `flows:process-timeouts`; agente que escribe pausa el run | Flows/{Index,Edit,Runs}.jsx (editor de cards con validación de edges en servidor) |
| IA               | `Services/Ai/{Client,ReplyGenerator,Chunker}.php` (OpenAI/Anthropic BYO key; retrieval FULLTEXT + fallback LIKE — el FULLTEXT de InnoDB no ve filas de transacciones no confirmadas, importa en tests); `Jobs/AiAutoReplyJob` (tope por conversación, respeta flows, agente apaga bot vía `ai_autoreply_disabled`)                                                           | Settings/Ai.jsx                                                                   |
| API pública      | `routes/api.php` `/api/v1` + middleware `api.key` (Bearer, hash SHA-256, scopes contacts:read/write, conversations:read, messages:write)                                                                                                                                                                                                                                     | claves en Settings/Team.jsx                                                       |
| Equipos          | `TeamController` — invitaciones por link (token hasheado, 7 días, single-use, registro exprés en `/invite/{token}`); expulsar crea cuenta propia vacía al expulsado                                                                                                                                                                                                          | Settings/Team.jsx, Invitations/Accept.jsx                                         |
| Notificaciones   | `NotificationController`; contador compartido en `HandleInertiaRequests` (prop `unreadNotifications`)                                                                                                                                                                                                                                                                        | Notifications/Index.jsx + campana en el layout                                    |
| Dashboard        | `DashboardController` (métricas + mensajes 7 días)                                                                                                                                                                                                                                                                                                                           | Dashboard.jsx                                                                     |

### Orden de procesamiento de un mensaje entrante

`InboundProcessor` (transacción: contacto→conversación→mensaje→broadcast replied) y después del commit despacha en cola: `ProcessFlowMessageJob` (el flow activo consume el mensaje o se evalúan triggers) → `ProcessAutomationEventJob` (new_contact / inbound_message / keyword) → `AiAutoReplyJob` (se abstiene si hay flow activo).

## Ventana de servicio de WhatsApp (2026-07-26) — control de gasto

`Services\WhatsApp\ServiceWindow` calcula cuánto queda para escribirle a un contacto **sin que Meta cobre**. Existe el gemelo `Services\WhatsApp\ServiceWindow` en el Komo, que hace el mismo cálculo sobre `lead_events` — **si cambia una regla hay que tocar los dos** (y sus dos `ServiceWindowTest`).

Las reglas de Meta. **Las dos ventanas NO se comportan igual — confundirlas cuesta plata:**

- **24 h de servicio — SE REINICIA con cada mensaje.** Cada entrante del cliente abre/renueva 24 h de texto libre gratis contadas desde ese mensaje. Vencidas, solo se puede escribir con plantilla aprobada, y eso se factura.
- **72 h de free entry point — NO se reinicia.** Corren desde el clic en el anuncio Click-to-WhatsApp y punto: que el cliente siga escribiendo no las estira. Dentro de esas 72 h **todo es gratis, incluidas las plantillas**. Meta lo marca con `messages.referral`, así que solo un clic NUEVO en un anuncio abre otras 72 h — por eso se toma `MAX(created_at)` de los entrantes con referral, no el primero.
- **Corren en paralelo, vale la que venza más tarde.** El caso que hay que tener claro: el cliente toca el anuncio y escribe recién en la **hora 71**; al vencer las 72 h la conversación NO se corta, quedan las 24 h estándar desde su último mensaje — o sea hasta la **hora 95**. Por eso no alcanza con mirar el último mensaje, ni con mirar solo el anuncio: se toma el máximo de las dos.
- Los cuatro casos límite están fijados en `ServiceWindowTest`: la hora 71, que las 72 h no se reinician al escribir, que un clic nuevo sí abre otras 72 h, y el cruce inverso.

Implementación y UI:

- Sale de `messages.referral`, que `InboundProcessor` ya guardaba: no se consulta a Meta. `forMany()` (por conversación) y `forContacts()` (por contacto, con join) resuelven un listado entero en dos queries.
- `Components/ServiceWindowBadge.jsx` en `/inbox` (header del chat + lista), `/dashboard`, `/contacts`, `/pipelines` y `/notifications`. Verde / ámbar (< 4 h) / rojo (cerrada). El rojo es el que importa: ahí escribir cuesta.
- `GET /api/v1/ai/status` no tiene que ver con esto; la ventana no se expone por API porque Komo la calcula sola.

## Tope de la IA con pausa y reactivación automática (2026-07-27)

**El tope era inalcanzable.** `InboundProcessor` reseteaba `ai_reply_count` a 0 con CADA mensaje entrante, así que el "máximo N respuestas por conversación" de Ajustes no limitaba nada: el contador nunca llegaba al máximo. Ese reset se eliminó.

Ahora el contador se acumula y, al llegar al tope, la conversación queda con `ai_paused_until = now + N horas`:

- Durante la pausa la IA se calla y **no vuelve a avisar** — el aviso ya salió al alcanzar el tope; repetirlo en cada mensaje sería ruido.
- Cuando la pausa vence, el propio `AiAutoReplyJob` reinicia `ai_reply_count` a 0 y limpia `ai_paused_until`: **la IA retoma sola**, sin cron ni intervención.
- Reactivar a mano (toggle IA/Humano → IA) también levanta la pausa, en el Inbox y por la API que usa Komo.
- El Inbox muestra un aviso dentro del hilo (`AiPausedNotice`) con la hora de reactivación y un enlace para reactivar ya. Va en el hilo y no en el header porque es información de _esta_ conversación, justo donde el agente está leyendo.

**Cooldown configurable** por cuenta: `ai_configs.auto_reply_cooldown_hours`, default **3 h**. El criterio: suficiente para cortar el ida y vuelta con un bot que ya no ayuda (que es para lo que existe el tope), corto como para que quien vuelve el mismo día encuentre respuesta, y holgado dentro de la ventana de servicio de 24 h — reactivarse no cuesta plata. Tope de 24 h en la validación: más que eso equivale a apagar la IA, y para eso está el toggle.

Migración `2026_07_27_000001`. Tests en `AiCooldownTest` (6), incluido el que fija que el contador ya no se reinicia con cada mensaje.

## Espejo de miembros wacrm → Komo (2026-07-27)

**El puente de usuarios era de ida solamente.** Komo creaba el user acá al aceptar una invitación (`TeamController@provisionInWacrm` allá), pero un miembro dado de alta EN este proyecto no existía en Komo — y Komo es donde se asignan los contactos, así que no aparecía en ningún desplegable de responsable. Ese era el bug.

- `Services\Komo\Client` + config `services.komo` (**`KOMO_URL` y `KOMO_API_KEY` en `.env`; la key necesita scope `team:write`**). Sin configurar, el espejo se salta y **se avisa en pantalla** en vez de dejar creer que quedó sincronizado.
- `TeamController@redeem` espeja al aceptar el link. `TeamController@storeMember` (`POST /settings/team/members`, admin-only) es el alta **directa sin link**: el admin carga nombre/email/contraseña/rol en un modal y el miembro ya puede entrar acá **y en Komo** con las mismas credenciales.
- Traducción de roles: `admin→admin`, `agent/viewer→agent` (Komo no tiene equivalente de «solo lectura»).
- Del otro lado: `POST /api/v1/team/provision` en Komo, idempotente por email. **No pisa el password** si el miembro ya entró y lo cambió; 409 si el email es de otra cuenta.
- Un fallo del espejo **no cancela el alta local** — se loguea y el flash lo dice. Tests en `TeamMemberMirrorTest` (acá) y `TeamProvisionApiTest` (allá).
- **Red de seguridad**: `php artisan wacrm:sync-team-to-komo` empuja a Komo los miembros que ya existían (el espejo automático solo actúa al CREAR). Con `--dry-run` lista sin tocar nada y con `--password=` fija una clave temporal — si no, los que se creen allá quedan con una aleatoria y entran por «olvidé mi contraseña». **Si la integración no está configurada, el comando imprime exactamente qué falta**: es el primer sitio donde mirar cuando un miembro no aparece en Komo (403 = falta el scope `team:write`; 409 = ese email ya es de otra cuenta allá).

## Seguimiento del admin (2026-07-26) — `/supervision`

**Drill-down por agente (2026-08-06).** La ficha individual del agente (KPIs, histograma, embudo y pendientes operativos) ya NO vive solo en el Komo: está construida acá. Ruta `GET /supervision/agents/{user}` (`SupervisionController@show`, admin-only, valida que el agente pertenezca a la misma cuenta) + página `Supervision/Agent.jsx`. El nombre de cada agente en la tabla principal (`Supervision/Index.jsx`) ahora es clicable.
- Backend: nuevo `ResponseMetrics::forAgent($userId)` (y helper privado `histogram()`) que reusa los MISMOS recorridos `measure()`/`row()`/`aggregateByAgent()` de `build()` — para que un número signifique lo mismo a nivel equipo y a nivel agente. Devuelve: perfil del agente, KPIs (el bucket de ese agente del agregador), histograma de tiempos de primera respuesta, serie diaria y las filas de sus conversaciones.
- Frontend: KPIs (atendidas %, esperando/SLA, 1ª respuesta, respuesta más lenta, ventana cerrada), histograma por baldes (‹1 m, 1-5, 5-15, 15-30, 30 m-1 h, ›1 h), embudo de sus negocios abiertos (acumulado por etapa, `by_stage`), gráficas de volumen diario y respuesta/SLA, y tabla de **pendientes operativos** (contactos esperando respuesta o con la ventana de servicio cerrada). Selector de ventana `7/15/30/90d` y link de vuelta a `/supervision`.
- No requiere migraciones. Tests de `ResponseMetrics` (10) siguen en verde — `forAgent` reusa los recorridos cubiertos.

`SupervisionController@index` + el resto de la sección: `Services\Supervision\ResponseMetrics` + `Pages/Supervision/{Index,Charts}.jsx`. Ventanas de 7/15/30/90 días por `?days=`. **Admin-only, cortado en el controlador**: este proyecto no tiene middleware `admin.only` como el Komo.

`SupervisionController` + `Services\Supervision\ResponseMetrics` + `Pages/Supervision/{Index,Charts}.jsx`. Ventanas de 7/15/30/90 días por `?days=`. **Admin-only, cortado en el controlador**: este proyecto no tiene middleware `admin.only` como el Komo.

Gemelo del `ResponseMetrics` del Komo, pero acá se calcula sobre `messages` (la fuente real, no el espejo de eventos) y la atribución es **exacta** porque `messages.sender_id` dice qué usuario mandó cada saliente. **Si cambia una definición hay que tocar los dos** (y sus dos tests).

Definiciones — si cambian, los números cambian de sentido:

- **La respuesta de la IA NO cierra la espera.** Lo relevante es si atendió un humano; la IA solo gana tiempo.
- **El reloj arranca en el PRIMER mensaje de la ráfaga**, no en el último.
- **Un saliente humano sin espera abierta es seguimiento proactivo, no respuesta** — no entra en los promedios.
- **"Quién contestó 1º"** separa `asignado` de `otro_agente`: deja ver si el dueño de la conversación la trabaja o se la están cubriendo.

Además del proceso, mide la **carga**: `assigned_contacts` cuenta TODO lo asignado a cada agente (con o sin actividad en el periodo, incluidas las cerradas) — es la carga real que tiene encima, no solo lo que se movió. Va con una barra abierta/pendiente/cerrada. También trae `window_closed` por agente: conversaciones a las que ya no se puede escribir sin costo, que el admin necesita ver **antes** de reclamar una respuesta.

## Plantillas rápidas sugeridas

`Services\WhatsApp\SuggestedQuickReplies` — pack de 17 plantillas para un instituto que inscribe por WhatsApp, en 4 grupos (Información / Promoción / Cierre de inscripciones / Seguimiento). Botón admin-only en `/settings/quick-replies` (`quick-replies.load-suggested`), **idempotente por shortcut**: no duplica ni pisa lo que el equipo ya escribió.

**Son texto libre, no plantillas aprobadas de Meta**: solo salen dentro de la ventana de servicio. Con la ventana cerrada Meta las rechaza — no es que cuesten más, es que no se entregan. Eso está explicado en la propia pantalla para que nadie asuma que sirven para reactivar contactos fríos; para eso hace falta una plantilla aprobada (`/templates`), que sí se factura.

**Bug arreglado de paso**: `QuickReply` no tenía la relación `user` que el listado carga con `with('user:id,name')`. Con cero plantillas Eloquent ni la resuelve, pero con una sola la pantalla devolvía 500 — o sea que Ajustes → Plantillas rápidas estaba roto en cuanto alguien creaba la primera. Hay test de regresión.

## Despliegue en producción

**Los dos proyectos viven juntos en el mismo VPS Ubuntu y casi siempre se despliegan de a pares** — comparten integración por API y webhooks, así que un cambio en uno suele necesitar el otro.

| Proyecto     | Ruta en el servidor     | Dominio                                   |
| ------------ | ----------------------- | ----------------------------------------- |
| wacrm (este) | `/var/www/crm-whatsapp` | `crm-whatsapp.posgradosinnovaciencia.com` |
| Komo         | `/var/www/crm-komo`     | `komo.posgradosinnovaciencia.com`         |

Secuencia completa (omitir `migrate` si el cambio no trae migración, y `npm ci && npm run build` si no tocó `resources/js`):

```bash
cd /var/www/crm-whatsapp && git pull origin main && npm ci && npm run build && php artisan migrate --force && php artisan optimize:clear
cd /var/www/crm-komo && git pull origin main && npm ci && npm run build && php artisan migrate --force && php artisan optimize:clear
```

Y reiniciar los workers si el cambio toca jobs (IA, espejo de asignaciones, Telegram, broadcasts):

```bash
sudo systemctl restart crm-whatsapp-queue.service
sudo systemctl restart crm-komo-queue.service
```

**Trampas conocidas al desplegar:**

- **`npm run build` no es opcional** cuando cambió el front: `git pull` trae el fuente, pero el bundle se genera en el build. Sin él el navegador sigue sirviendo el JS viejo y "no pasó nada".
- **`/public/build` está en `.gitignore`** en los dos proyectos — por eso el build va en el servidor.
- **`npm ci` exige que `package-lock.json` esté al día**: ya reventó con ERESOLVE por `vite ^8` + `@vitejs/plugin-react ^4` (resuelto subiendo el plugin a `^5.2`).
- **Los jobs en cola no salen sin worker.** Si un aviso no llega, lo primero es `systemctl status` del worker de esa app, no revisar el código.
- **`php artisan config:clear`** después de tocar el `.env`.
- Al pasar comandos con credenciales, **no dejar marcadores tipo `PEGA_AQUI`** en una línea ejecutable: ya pasó que se copiaron literales al `.env` y la integración con Komo fallaba con "Invalid API key".

## Pendiente menor

i18n (la UI está en español fijo; el original usaba next-intl), grabación de audio en el inbox (requiere opus-recorder — Meta solo acepta ogg/opus y MediaRecorder de Chrome produce webm; hoy se puede adjuntar el audio como archivo), y para producción: SMTP real (hoy driver log), HTTPS, cron `schedule:run` y supervisión de `queue:work`/`reverb:start`.
