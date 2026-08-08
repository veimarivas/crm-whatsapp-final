# wacrm (ESAM CONECTA) — Reportes legibles y analizables

## Instrucciones de trabajo para el agente

> **Objetivo:** convertir los números existentes en gráficos que se puedan _leer y analizar_: series temporales, comparativas entre agentes, embudos con % y contexto (periodo anterior, SLA). No es rediseño estético: es capacidad de análisis. Ejecuta las tareas **en orden**; el Paso 0 es prerequisito de todo lo demás.

---

## 0. Reglas del proyecto (lectura obligatoria antes de tocar nada)

- Stack: Laravel 13 + Inertia + React 18 + Vite, MariaDB 10.11, PHP 8.3. `npm install` **siempre** con `--legacy-peer-deps`.
- El build de producción se genera en el servidor: `/public/build` está en `.gitignore`. Todo cambio front exige `npm ci && npm run build` en el servidor al desplegar.
- Multi-tenant por `account_id` (trait `BelongsToAccount`), PKs UUID. Toda query pasa por el scope de cuenta.
- Estilo Velzon vigente: cards `rounded-2xl shadow-sm border border-gray-100`; headers `text-2xl sm:text-3xl font-bold` + subtítulo `text-sm text-gray-400`; gradiente de marca `from-[#045474] to-[#1c486c]`.
- **Semántica de color (no inventar otra):** emerald = positivo / dentro de SLA / entregado; amber = advertencia (<5 min, <4 h de ventana); rose = peligro (≥5 min, ventana cerrada); purple = IA; gradiente de marca = serie principal.
- Filtros **server-side** con query params + `router.get` con debounce. Prohibido filtrar en el cliente datos paginados.
- Roles: `agent/viewer` solo ven lo suyo; todo endpoint nuevo corta en el servidor (acá no hay middleware `admin.only`, se hace en el controlador).
- **Sin migraciones** salvo necesidad estricta. Preferir queries agregadas sobre `messages`, `conversations`, `broadcasts`.
- Tests feature por endpoint nuevo; suite completa `php artisan test` en verde (BD de tests MySQL, no sqlite).
- Una tarea = un commit. No mezclar tareas de esta lista con refactors ajenos.

---

## 1. Paso 0 — Capa base de gráficos (prerequisito)

1. `npm install recharts --legacy-peer-deps`.
2. Crear `resources/js/Components/Charts/` con:
    - `chartTheme.js` — paleta según la semántica de color de arriba; estilos de ejes/tooltip/leyenda consistentes.
    - `format.js` — `fmtNumber` (compacto, es-BO), `fmtMoney` (Bs), `fmtDuration` (segundos → `1 m 23 s`), `fmtPct`. Usar en TODOS los gráficos y tooltips.
    - `ChartCard.jsx` — card Velzon con título, subtítulo, slot de acciones y **estado vacío** (`EmptyChart`: "Sin datos en este periodo"; nunca un gráfico roto ni un NaN).
    - `TrendArea.jsx` — AreaChart multi-serie con gradiente de marca.
    - `CompareBars.jsx` — barras horizontales/verticales con `ReferenceLine` de objetivo.
    - `FunnelSteps.jsx` — etapas con count + **% de paso** entre etapas + % de caída.
    - `HeatmapGrid.jsx` — matriz hora × día de semana con escala amber→rose.
    - `WindowPicker.jsx` — selector 7/15/30/90 d que navega con `?days=` (mismo patrón que `/supervision`).
3. Tooltips siempre con valor absoluto **y** %. Leyendas clicables para ocultar series.

**Criterio de aceptación:** la capa se usa en T1 sin regresiones visuales y con la suite en verde.

---

## 2. Tareas por prioridad

### P0 · T1 — `/settings/response-time` → centro de analítica de respuesta

**Hoy:** `AiController@responseTime` (LATERAL JOIN, 30 días fijos) + `Settings/ResponseTime.jsx` (3 KPIs + tabla con medallas y badges).

**Backend:**

- Aceptar `?days=` (7/15/30/90) con el mismo patrón de `SupervisionController`.
- Además de lo actual, devolver: (a) histograma de primera respuesta por baldes ‹1 m / 1-5 / 5-15 / 15-30 / 30 m-1 h / ›1 h; (b) serie diaria de mediana; (c) mediana por agente para el gráfico comparativo.
- Reusar recorridos de `Services\Supervision\ResponseMetrics` donde las definiciones coincidan; **no duplicar la lógica LATERAL** en un tercer lugar.
- ⚠️ Definiciones distintas y ambas válidas: aquí la respuesta de la IA **sí** cuenta como respuesta (la página mide "tiempo hasta alguna respuesta"); en `/supervision` la IA **no** cierra la espera (mide atención humana). La página debe decirlo en una línea de texto visible para que nadie compare peras con manzanas.

**Frontend (`Settings/ResponseTime.jsx`):**

- Mantener KPIs y tabla (son buenos). Agregar: histograma (`CompareBars`), tendencia diaria de mediana con `ReferenceLine` en 5 min, y comparativa horizontal de agentes coloreada con los umbrales ya existentes (<60 s emerald, <5 min amber, ≥5 min rose).
- KPI cards con **delta vs periodo anterior** (flecha + %).

**Criterio de aceptación:** el selector de ventana mueve todos los gráficos; tests del endpoint con `?days=`; sin migraciones.

### P0 · T2 — `/broadcasts-metrics` → embudo por campaña

**Hoy:** tasas globales + gráfico 30 días con 3 barras/día (sent/delivered/read) + top 10 por reply_rate.

- Por cada campaña del top 10: **embudo horizontal** `sent → delivered → read → replied` con % entre pasos (`FunnelSteps`). El dato ya existe en los counters de `broadcasts`.
- Línea de **tasa de respuesta diaria** global para ver si las campañas mejoran o empeoran.
- Selector `?days=` en el gráfico global; tooltips con absolutos + %.

### P1 · T3 — `/settings/ai/stats` → salud de la IA en el tiempo

- Reemplazar el gráfico de barras de 14 días por un `ComposedChart`: barras diarias de respuestas IA (purple) + línea de fallbacks superpuesta + línea de **tasa de éxito %** en eje derecho.
- KPI cards con delta vs periodo anterior.
- (Opcional, si sobra tiempo): agrupar las últimas 30 preguntas por tema repetido (normalización simple de keywords) con conteo, para alimentar el knowledge base.

### P1 · T4 — Dashboard: de números a historia

- El gráfico de mensajes de 7 días pasa a **área apilada por `sender_type`**: entrante del cliente / saliente de agente / saliente de IA. Ese solo gráfico cuenta cuánto está asumiendo la IA. (`DashboardController` ya tiene la métrica base; agregar el split.)

### P1 · T5 — `/supervision` → comparativas de equipo

**Hoy:** KPIs por agente, histograma y embudo en la ficha (`Supervision/Agent.jsx`), tabla en el index.

- Index: **comparativa horizontal** de mediana de primera respuesta por agente con `ReferenceLine` de SLA (la tabla obliga a leer fila por fila; el gráfico muestra al que falla en 2 segundos).
- Index: **tendencia diaria de cumplimiento SLA** (% atendido dentro del objetivo).
- Index: **heatmap hora × día** de mensajes entrantes (`messages.sender_type=customer`) — responde "cuándo escriben los clientes".
- Index: **antigüedad del backlog**: baldes de horas desde el último `message_in` sin respuesta humana (‹1 h / 1-4 / 4-8 / 8-24 / ›24 h). No meter esto dentro de `ResponseMetrics`: crear `Services\Supervision\BacklogCharts` (o similar) para no tocar el gemelo del Komo.
- Ficha del agente: superponer la **línea de promedio del equipo** sobre sus series, para que su número tenga contexto.

### P2 · T6 — Transversales de análisis

- **Exportar CSV** en `/settings/response-time`, `/broadcasts-metrics` y `/supervision`: mismo patrón de `ContactController@exportCsv` (stream chunked, BOM UTF-8, separador `;`).
- Drill-down: clic en una fila/barra de agente → `/supervision/agents/{user}` (ya existe la ficha).

---

## 3. Advertencias duras

- **GEMELO:** `Services\Supervision\ResponseMetrics` existe idéntico en el Komo con sus propios tests. Si cambias UNA definición o método suyo, hay que replicarlo allá y correr los dos tests. Por eso los gráficos nuevos del index deben vivir en una clase nueva, no en el gemelo.
- No tocar el motor de WhatsApp, webhooks ni la IA: esto es solo lectura/agregación de datos existentes.
- Cuidado con queries N+1 en agregaciones: usar `GROUP BY` y pocas queries; si una agregación pesa, caché corta (60-300 s, patrón del caché de oferta académica) con invalidación por huella.

## 4. Definición de terminado (por tarea)

- [ ] Endpoint con `?days=` y scope de rol correcto en el servidor.
- [ ] Gráficos con la capa del Paso 0, tooltips con absoluto + %, estado vacío.
- [ ] Tests feature nuevos en verde + suite completa `php artisan test`.
- [ ] Sin `confirm()` nativos ni filtrado cliente de datos paginados.
- [ ] Commit único por tarea.

## 5. Despliegue (al cerrar cada ronda)

```bash
cd /var/www/crm-whatsapp && git pull origin main && npm ci && npm run build && php artisan optimize:clear
sudo systemctl restart crm-whatsapp-queue.service   # solo si se tocaron jobs
```

(Sin `migrate` si no hay migración; sin reiniciar worker si no hay jobs.)
