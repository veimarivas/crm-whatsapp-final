<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'meta' => [
        // App Secret de Meta for Developers → App Settings → Basic.
        // Verifica la firma HMAC-SHA256 de cada POST del webhook.
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SSO ligero del ecosistema: secreto HMAC compartido con el Komo Hub
    // (el MISMO valor en el .env de las 4 apps). Lo consume /sso/consume.
    'hub' => [
        'sso_secret' => env('HUB_SSO_SECRET'),
        // Secreto maestro de provisión (POST /api/v1/provision). NUNCA al navegador.
        'provision_secret' => env('HUB_PROVISION_SECRET'),
    ],

    // Komo (el CRM de leads). Se usa para espejar los miembros del equipo:
    // quien se da de alta acá tiene que existir allá, porque allá es donde se
    // asignan los contactos. La API key necesita el scope `team:write`.
    //   KOMO_URL=https://komo.tudominio.com
    //   KOMO_API_KEY=komo_live_...
    // Sin configurar, el espejo se salta en silencio y el miembro solo queda
    // en este proyecto.
    'komo' => [
        'url' => env('KOMO_URL'),
        'api_key' => env('KOMO_API_KEY'),
    ],

    // Whisper.cpp para transcripción de audios entrantes del cliente.
    // Se configura en .env con:
    //   WHISPER_BINARY=/root/whisper.cpp/main
    //   WHISPER_MODEL=/root/whisper.cpp/models/ggml-base.bin
    //   WHISPER_LANGUAGE=es
    // Si WHISPER_BINARY no existe o no es ejecutable, TranscribeAudioJob se
    // abstiene silenciosamente (los audios llegan sin transcripción, no rompe nada).
    'whisper' => [
        'binary' => env('WHISPER_BINARY'),
        'model' => env('WHISPER_MODEL'),
        'language' => env('WHISPER_LANGUAGE', 'es'),
    ],

    /*
    | Ollama: cuánto se espera y cuánto contexto se le manda.
    |
    | Estos números dependen del hardware, no del código: el mismo prompt que
    | en una máquina con GPU tarda 3 s, en un VPS por CPU puede tardar minutos.
    | Por eso son variables de entorno y no constantes — se ajustan con lo que
    | mida `wacrm:ai-benchmark`, sin desplegar nada.
    */
    'ollama' => [
        // Antes 120 s fijos. La primera consulta con el modelo frío se pasaba
        // de ahí y la conversación se quedaba sin respuesta.
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),

        // Cuánto deja Ollama el modelo en memoria después de responder. Por
        // defecto son 5 minutos: cualquier pausa mayor entre clientes obliga a
        // recargarlo, y esa recarga es la que se comía el timeout.
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),

        // Techo del contexto. Se pide el escalón que haga falta según el
        // tamaño real del prompt: un `num_ctx` grande reserva memoria y hace
        // más lenta cada consulta, aunque el prompt sea corto.
        'max_ctx' => (int) env('OLLAMA_MAX_CTX', 16384),
    ],

    /*
    | Cuánto contexto se arma para la IA.
    |
    | Bajarlos hace las respuestas más rápidas y más baratas de calcular; el
    | precio es que la IA ve menos catálogo y menos historial.
    */
    'ai_context' => [
        // Solo tiene que entrar el ÍNDICE (nombres). Fijar el catálogo entero
        // costaba ~80 s de lectura por consulta en un servidor sin GPU.
        'pinned_budget' => (int) env('AI_PINNED_BUDGET', 4000),
        'chunk_budget' => (int) env('AI_CHUNK_BUDGET', 3000),
        'history_messages' => (int) env('AI_HISTORY_MESSAGES', 12),
        'history_chars' => (int) env('AI_HISTORY_CHARS', 800),

        // Tope de la respuesta. Cada token generado son décimas de segundo en
        // CPU: 800 podían ser minutos de espera para un mensaje de WhatsApp
        // que nadie quiere leer tan largo.
        'max_tokens' => (int) env('AI_MAX_TOKENS', 220),

        // Largo maximo de la respuesta que se envia. El modelo se va de largo
        // aunque se le pida brevedad; una parrafada en un chat no se lee.
        // Se corta en la ultima idea completa y se ofrece ampliar.
        'max_chars' => (int) env('AI_MAX_CHARS', 700),

        // Consultar la oferta a la BD académica en el momento, en vez de usar
        // la foto indexada. Es lo que hace que el prompt lleve SOLO lo que la
        // pregunta necesita: la consulta SQL son milisegundos, mientras que
        // hacerle leer al modelo el catálogo entero son decenas de segundos.
        'live_oferta' => (bool) env('AI_LIVE_OFERTA', true),
        'oferta_cache_seconds' => (int) env('AI_OFERTA_CACHE', 300),

        // Techo del prompt completo. Es el tope que evita que una pregunta
        // sobre un programa con muchos módulos mande decenas de miles de
        // caracteres y el request muera en el timeout.
        'total_budget' => (int) env('AI_TOTAL_BUDGET', 12000),
    ],

];
