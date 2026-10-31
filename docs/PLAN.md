# Plan de implementación — Gestión de Incidentes (Psicol)

Oct 31, 2026 · @Juan Pablo Manotas

## 0. Cómo usar este plan

Claude Code ejecuta este plan fase por fase (sección 10), en orden, y no avanza a la siguiente fase hasta cumplir sus criterios de aceptación y hacer su commit.

1. Lee el documento completo antes de escribir código: las secciones 1 a 9 son el contrato, la 10 es el orden de trabajo y la 11 es el checklist final.
2. Todo corre en Docker. No instales PHP, Composer ni Node en el host; usa contenedores efímeros (`docker run --rm …`) o `docker compose exec`.
3. Si algo no está cubierto, elige la opción más simple que respete las reglas de dominio (sección 4) y regístrala en `docs/decisions.md` con contexto, decisión y razón.
4. Los contratos de las secciones 7 y 8 no cambian sin actualizar, en el mismo commit, `docs/api-contract.md`, los API Resources y `frontend/src/types/api.ts`.
5. Las reglas de dominio viven en el backend (modelo, enums, actions, policies). Controladores y componentes Vue no deciden reglas: el frontend solo muestra lo que el backend permite (`allowed_transitions`, `can`).
6. Idioma: código, tablas, rutas y eventos en inglés; interfaz, mensajes de validación, README y documentación en español.
7. Commits con Conventional Commits (`feat:`, `fix:`, `build:`, `test:`, `docs:`), al menos uno por fase.
8. Fuera de alcance: todo lo de la pestaña "Anexo — Propuestas". No instales nada de esa lista.

Entrega a Claude Code solo esta pestaña exportada a Markdown; en la Fase 0 se guarda como `docs/PLAN.md`.

## 1. Contexto, alcance y supuestos

Se construye un monolito modular en Laravel 13 (API) y una SPA en Vue 3 + Quasar que gestionan incidentes y solicitudes como un único agregado, y todo arranca con `docker compose up -d`.

**Alcance.** Se implementa todo lo obligatorio del PRD (autenticación, CRUD, listado avanzado, dashboard, rendimiento, 5 vistas de frontend, Docker, README, seeders) y también todo lo marcado como "idealmente": arranque con `docker compose up -d`, tiempo real por WebSockets, tests con Pest, roles y permisos, exportación CSV/XLSX y auditoría.

**Supuestos de interpretación.** El PRD permite asumir decisiones razonables si se justifican; estas son las que toma el plan.

| ID | Supuesto | Por qué |
| --- | --- | --- |
| S-01 | Solicitud e incidente son el mismo agregado `Incident`, con un campo `type` (`incident` o `request`). | Comparten ciclo de vida, campos y filtros; el PRD los nombra juntos ("CRUD de Incidentes/Solicitudes"). Separarlos duplicaría código sin regla que lo justifique. |
| S-02 | El filtro "rango de fechas" aplica sobre la fecha de creación por defecto y permite elegir la fecha de vencimiento (`date_field`). | El PRD no dice qué fecha; ambas son útiles para operar. |
| S-03 | "Vencido" es una condición derivada, no un estado guardado: estado activo y `due_at` anterior a ahora. | No requiere tareas programadas ni puede quedar desincronizado; se consulta con índice. |
| S-04 | La fecha de vencimiento es obligatoria en BD; si el usuario no la envía, se calcula por el SLA de la prioridad. | La métrica de vencidos cubre el 100 % de los casos y se reduce fricción al crear. |
| S-05 | Tres roles: `admin`, `agent` y `requester`. | Cubre "usuario creador" y "usuario asignado" y el ideal de roles y permisos sin sobrediseñar. |
| S-06 | La eliminación es lógica (soft delete) y solo la hace un admin. | Preserva auditoría e indicadores históricos. |
| S-07 | El dashboard aplica la misma regla de visibilidad que el listado: un requester ve las cifras de sus propios casos. | Una sola regla de visibilidad evita fugas de datos y cifras contradictorias. |
| S-08 | BD y API en UTC; la interfaz muestra hora de America/Bogota y los filtros por día se interpretan en esa zona. | Evita errores de vencimiento por desfase horario. |
| S-09 | El seeder carga 5.000 incidentes por defecto (`SEED_INCIDENTS`) y un comando carga 100.000 para demostrar rendimiento. | El PRD pide mínimo 500 y operar con grandes volúmenes; el arranque sigue siendo rápido. |
| S-10 | El dashboard suma a las cuatro cifras del PRD: activos, sin asignar, vencidos por prioridad y la tendencia de creados vs. resueltos de los últimos 30 días. | El PRD pide indicadores para el "seguimiento y análisis de la operación": las cuatro cifras dicen cómo estamos hoy y la tendencia dice hacia dónde vamos. Todo sale de los mismos datos e índices. |

## 2. Event Storming Lite

El dominio gira en torno a un solo agregado, Incidente: 6 comandos producen 7 tipos de evento de dominio, y 3 políticas reaccionan a ellos. Nomenclatura clásica: comando (azul), evento (naranja), agregado (amarillo), política (lila), read model (verde), hotspot (rosa).

**Actores**

- **Solicitante** (`requester`): registra casos y sigue los suyos; confirma el cierre.
- **Agente** (`agent`): se autoasigna, atiende y resuelve.
- **Administrador** (`admin`): asigna, reprioriza, elimina, exporta; puede hacer todo lo anterior.
- **El tiempo**: el vencimiento ocurre porque pasa el tiempo, no por un comando.

**Comandos y eventos**

| # | Actor | Comando | Evento de dominio | Políticas que reaccionan |
| --- | --- | --- | --- | --- |
| 1 | Cualquier rol | `RegisterIncident` | `IncidentRegistered` | P1, P3 |
| 2 | Creador (si `open`), agente asignado, admin | `UpdateIncidentDetails` | `IncidentDetailsUpdated` | P1, P3 |
| 3 | Agente asignado, admin | `ReprioritizeIncident` | `IncidentReprioritized` | P1, P3 |
| 4 | Admin; agente solo para autoasignarse | `AssignIncident` | `IncidentAssigned` (o `IncidentUnassigned`) | P1, P2, P3 |
| 5 | Según la tabla de transiciones (sección 4) | `TransitionIncidentStatus` (iniciar, pausar, reanudar, resolver, cerrar, reabrir, cancelar) | `IncidentStatusChanged` {from, to} | P1, P3 |
| 6 | Admin | `DeleteIncident` | `IncidentDeleted` | P1, P3 |
| 7 | El tiempo | — (no hay comando) | "Incidente vencido": no se materializa; se calcula al consultar (S-03) | — |

Las siete transiciones de estado se modelan como un solo comando con estado destino, para que la regla viva en una única tabla de transiciones; en la interfaz se presentan como acciones con nombre propio.

**Políticas (reacciones)**

- **P1 — Auditoría:** cada evento registra una actividad (quién, qué, antes y después). Corre sincrónica dentro de la misma transacción: si falla la auditoría, falla el comando.
- **P2 — Aviso al asignado:** al asignar, se notifica en tiempo real al nuevo responsable. Corre sincrónica después del commit; si Reverb falla, solo se registra en el log.
- **P3 — Difusión:** cualquier cambio emite un evento WebSocket para que listado, detalle y dashboard se refresquen. Igual que P2: después del commit y sin romper el comando si Reverb falla.

**Read models**

- Listado paginado y filtrable de incidentes.
- Detalle con acciones permitidas para el usuario actual (`allowed_transitions`, `can`).
- Línea de tiempo (actividades) por incidente.
- Estadísticas del dashboard (total, activos, vencidos, sin asignar, por estado, por prioridad y tendencia de 30 días).
- Catálogos (prioridades, estados, tipos) con etiquetas en español.
- Exportación CSV/XLSX con los mismos filtros del listado.

**Hotspots (preguntas abiertas y decisión tomada)**

| ID | Pregunta | Decisión | Razón |
| --- | --- | --- | --- |
| H-1 | ¿Un agente puede cambiar el estado de un caso que no tiene asignado? | No; primero se autoasigna. El admin sí puede. | Todo cambio tiene un responsable visible. |
| H-2 | ¿Se puede reabrir un caso cerrado? | No. Cerrado y cancelado son terminales; se reabre solo desde resuelto. | Cerrar es la conformidad del solicitante; el histórico no se reescribe. |
| H-3 | ¿El solicitante edita el caso cuando ya se está atendiendo? | No; solo mientras está `open`. | No se cambia el problema mientras alguien trabaja en él. |
| H-4 | ¿El vencimiento es editable? | Sí, por el agente asignado o el admin, y nunca a una fecha pasada. | Renegociar plazos es normal; crear casos ya vencidos no. |
| H-5 | ¿El reloj se detiene en "En espera"? | No en esta versión. | Simplicidad; pausar el SLA queda en el anexo. |
| H-6 | ¿Quién cierra un caso resuelto? | El creador o un admin. | El cierre representa la conformidad de quien pidió. |

## 3. Lenguaje ubicuo

La interfaz habla en español y el código en inglés; esta tabla es la única traducción válida entre ambos.

| Término (UI) | Código | Definición |
| --- | --- | --- |
| Incidente / Solicitud | `Incident`, `type` = `incident` \| `request` | Caso registrado que requiere atención. |
| Código | `code` (`INC-000123`) | Identificador legible derivado del `id`; no se guarda. |
| Solicitante | `requester`, `created_by`, `creator` | Usuario que registró el caso. |
| Responsable | `assignee`, `assigned_to` | Agente o admin que atiende el caso. |
| Prioridad | `Priority`: `low`, `medium`, `high`, `critical` | Criticidad del caso; define el SLA por defecto. UI: Baja, Media, Alta, Crítica. |
| Estado | `IncidentStatus` | Etapa del ciclo de vida. |
| Abierto | `open` | Registrado, sin iniciar. |
| En progreso | `in_progress` | El responsable lo está atendiendo. |
| En espera | `on_hold` | Bloqueado por un tercero o por falta de información. |
| Resuelto | `resolved` | Se aplicó una solución; espera conformidad. |
| Cerrado | `closed` | Conformidad confirmada. Terminal. |
| Cancelado | `cancelled` | Retirado sin resolverse. Terminal. |
| Estado activo | `IncidentStatus::active()` = `open`, `in_progress`, `on_hold` | Estados en los que corre el vencimiento. |
| Fecha de vencimiento | `due_at` | Momento límite para resolver. |
| Vencido | `is_overdue`, scope `overdue()` | Estado activo y `due_at` anterior a ahora. Derivado. |
| SLA | `DueDatePolicy` | Plazo por defecto según prioridad. |
| Transición | `transitionTo()` | Cambio de estado permitido por la máquina de estados. |
| Actividad | `IncidentActivity` | Entrada de la línea de tiempo y de la auditoría. |

## 4. Modelo de dominio (DDD Lite)

Un agregado raíz, `Incident`, protege todas las invariantes; los value objects son enums de PHP y los casos de uso son Actions. Ninguna regla de negocio vive en controladores ni en componentes Vue.

**Qué significa "Lite" en este proyecto**

- **Se aplica:** lenguaje ubicuo, agregado con invariantes, value objects (enums), eventos de dominio, casos de uso como Actions y lecturas separadas en Query objects (CQRS ligero).
- **Se omite a propósito:** repositorios sobre Eloquent, entidades puras con mappers, bus de comandos y contextos como servicios separados. Eloquent ya es Active Record; envolverlo duplica código sin proteger mejor las reglas a este tamaño.

**Contextos delimitados (módulos del monolito)**

| Contexto | Tipo | Responsabilidad | Relación |
| --- | --- | --- | --- |
| Incidents | Núcleo | Agregado, ciclo de vida, reglas de negocio | Conoce a los usuarios solo por id |
| Identity & Access | Genérico | Usuarios, login, tokens, roles y permisos | Lo usan todos los contextos |
| Audit | Soporte | Línea de tiempo y auditoría | Escucha los eventos de Incidents |
| Reporting | Soporte | Dashboard, catálogos y exportación (solo lectura) | Lee Incidents aplicando la regla de visibilidad |

**Agregado `Incident`**

- **Métodos de dominio en el modelo:** `register()`, `updateDetails()`, `reprioritize()`, `assignTo()`, `transitionTo()`. Cada uno valida sus invariantes, lanza una subclase de `DomainRuleViolation` si se rompen y acumula su evento; la Action guarda en una transacción y despacha los eventos.
- **Value objects:** `Priority` (enum int 1–4 con `code()`, `label()`, `slaDays()`), `IncidentStatus` (enum string con `canTransitionTo()`, `isActive()`, `isTerminal()`, `label()`) e `IncidentType`.
- **Servicio de dominio:** `DueDatePolicy` calcula `due_at` cuando no se envía: crítica 1 día, alta 3, media 5, baja 10 (configurable en `config/incidents.php`).

**Máquina de estados**

Solo existen estas 8 transiciones; cualquier otra responde 409 `INVALID_STATUS_TRANSITION`.

&#91;embedded content: máquina de estados del incidente · 6 estados, 8 transiciones\]

El camino feliz va de izquierda a derecha; pausar, reabrir y cancelar son desvíos, y solo el admin cancela un caso que ya se está atendiendo.

| Desde | Hacia | Acción en la UI | Quién | Condición | Efecto |
| --- | --- | --- | --- | --- | --- |
| `open` | `in_progress` | Iniciar atención | Asignado o admin | Tener responsable (R-08) | — |
| `in_progress` | `on_hold` | Poner en espera | Asignado o admin | — | — |
| `on_hold` | `in_progress` | Reanudar | Asignado o admin | — | — |
| `in_progress` | `resolved` | Resolver | Asignado o admin | — | `resolved_at` = ahora |
| `resolved` | `closed` | Cerrar | Creador o admin | — | `closed_at` = ahora |
| `resolved` | `in_progress` | Reabrir | Creador o admin | Nota de motivo (R-12) | `resolved_at` = null |
| `open` | `cancelled` | Cancelar | Creador o admin | — | — |
| `in_progress`, `on_hold` | `cancelled` | Cancelar | Admin | — | — |

**Reglas de dominio**

| ID | Regla | Por qué | Dónde se aplica | Error |
| --- | --- | --- | --- | --- |
| R-01 | Todo incidente nace en `open`; al crear no se acepta `status`. | El estado lo gobierna el ciclo de vida, no un formulario. | FormRequest (`prohibited`) + `Incident::register()` | 422 |
| R-02 | El creador es siempre el usuario autenticado. | Evita registrar casos a nombre de otro. | Action | — |
| R-03 | Título de 5 a 150 caracteres; descripción de 10 a 5.000. | Títulos escaneables en el listado; descripciones útiles. | FormRequest + reglas Quasar | 422 |
| R-04 | La prioridad se fija al crear; luego solo la cambian el asignado o un admin. | La criticidad la valida quien atiende, no quien reporta. | `IncidentPolicy::reprioritize` | 403 |
| R-05 | `due_at` siempre existe; si no llega, lo calcula `DueDatePolicy`. | Toda métrica de vencidos cubre todos los casos. | `Incident::register()` | — |
| R-06 | `due_at` no puede quedar en el pasado al crear ni al modificarlo. | Un caso no nace vencido; el vencimiento lo produce el tiempo. Los seeders sí crean históricos. | FormRequest `after:now` | 422 |
| R-07 | Solo se permiten las transiciones de la tabla anterior. | Ciclo de vida predecible y auditable. | `IncidentStatus::canTransitionTo()` | 409 `INVALID_STATUS_TRANSITION` |
| R-08 | Para pasar a `in_progress` debe haber responsable. | Nada se atiende sin dueño. | `Incident::transitionTo()` | 409 `ASSIGNEE_REQUIRED` |
| R-09 | El responsable es un usuario activo con rol `agent` o `admin`. | Un solicitante no atiende casos. | FormRequest + `Incident::assignTo()` | 422 |
| R-10 | Un caso `closed` o `cancelled` es de solo lectura. | Historia inmutable para auditoría e indicadores. | `Incident::assertMutable()` | 409 `INCIDENT_LOCKED` |
| R-11 | El solicitante solo edita título, descripción y tipo mientras el caso está `open`. | No se cambia el problema mientras alguien trabaja en él. | `IncidentPolicy::update` | 403 |
| R-12 | Reabrir exige una nota de motivo (10 a 500 caracteres). | Trazabilidad del retrabajo. | FormRequest condicional | 422 |
| R-13 | Vencido = estado activo y `due_at` anterior a ahora. | Sin tareas programadas ni desincronización; consultable con índice. | Scope `overdue()` + accessor `is_overdue` | — |
| R-14 | La eliminación es lógica y solo la hace un admin. | Preserva auditoría e histórico. | `SoftDeletes` + `IncidentPolicy::delete` | 403 |
| R-15 | Cada comando exitoso deja una actividad (actor, acción, antes y después). | Auditoría del PRD y línea de tiempo del detalle. | Listener sincrónico en la transacción | — |
| R-16 | Visibilidad: el requester ve solo lo que creó; agent y admin ven todo. | Privacidad del solicitante y colaboración del equipo. | Scope `visibleTo($user)` en listado, detalle, dashboard y exportación | 404 |

R-16 responde 404 y no 403 para no revelar que el incidente existe.

**Roles y permisos**

Los permisos se siembran con spatie/laravel-permission; `IncidentPolicy` combina permiso + propiedad + estado del caso.

| Permiso | admin | agent | requester |
| --- | --- | --- | --- |
| `incidents.view_all` | Sí | Sí | No: solo los propios |
| `incidents.create` | Sí | Sí | Sí |
| `incidents.update` | Todos | Asignados a él | Propios en `open` |
| `incidents.reprioritize` | Todos | Asignados a él | No |
| `incidents.assign` | A cualquier agente o admin | Solo autoasignarse un caso sin responsable | No |
| `incidents.transition` | Todas | Las de "Asignado" en sus casos | Cerrar, reabrir y cancelar en sus casos |
| `incidents.delete` | Sí | No | No |
| `incidents.export` | Sí | Sí | No |
| `dashboard.view` | Global | Global | Solo sus casos |

## 5. Decisiones de arquitectura (ADR lite)

El stack queda en Laravel 13 sobre PHP 8.4, MySQL 8.4 LTS, Reverb y Vue 3 + Quasar 2 con TypeScript; cada decisión indica la alternativa descartada y por qué.

| ID | Decisión | Alternativa descartada | Razón |
| --- | --- | --- | --- |
| D-01 | Monorepo con `backend/`, `frontend/`, `docker/` y `docs/`. | Dos repositorios | Un solo `git clone` y un solo `docker compose up -d`; el PRD pide un repositorio. |
| D-02 | Laravel 13 sobre PHP 8.4 (FPM). | Laravel 12 / PHP 8.5 | Laravel 13 salió el 17 de marzo de 2026, soporta PHP 8.3 a 8.5 y tiene parches de seguridad hasta Q1 2028 ([Laravel News](https://laravel-news.com/laravel-13-released)). PHP 8.4 maximiza la compatibilidad de paquetes. |
| D-03 | MySQL 8.4 LTS con cotejamiento `utf8mb4_0900_ai_ci`. | MariaDB 11.4 | FULLTEXT en InnoDB maduro, CTE y funciones de ventana; el cotejamiento insensible a tildes hace que "impresion" encuentre "impresión". |
| D-04 | Monolito modular por contextos (sección 4). | Microservicios | Tamaño de la prueba; los límites en carpetas permiten extraer un módulo más adelante. |
| D-05 | DDD Lite: modelo rico + Actions + Query objects, sin repositorios. | Repositorios + entidades puras | Menos capas con la misma protección de reglas (sección 4). |
| D-06 | Sanctum con tokens Bearer (personal access tokens) que expiran a las 8 horas y se revocan en logout. | Sanctum en modo cookie / JWT | El PRD pide manejo de token e interceptores; Sanctum es oficial y revocable (JWT no se revoca sin lista negra). |
| D-07 | Endpoints por intención para estado y asignación (`/transition`, `/assign`). | `PATCH` genérico del campo `status` | Cada comando produce un evento; la regla vive en un solo lugar y la auditoría es semántica. |
| D-08 | API versionada en `/api/v1`, JSON en snake\_case y fechas ISO-8601 en UTC. | camelCase con capa de conversión | Es la convención de Laravel; los tipos TypeScript reflejan la respuesta 1:1. |
| D-09 | En la API los enums viajan como códigos de texto; en BD la prioridad es `TINYINT` (1–4) y el estado `VARCHAR(20)`. | Tipo `ENUM` de MySQL | Ordenar por prioridad equivale a ordenar por criticidad con un índice pequeño; `ENUM` obliga a migrar para agregar valores. |
| D-10 | Búsqueda con índice FULLTEXT sobre título y descripción, modo booleano con prefijo (`term*`). | `LIKE '%term%'` | `LIKE` con comodín inicial recorre toda la tabla; FULLTEXT escala a cientos de miles de filas. Un número o `INC-123` busca por id exacto. |
| D-11 | Paginación por offset (`page`, `per_page` hasta 100). | Paginación por cursor | QTable necesita el total y saltar a la página N; con índices compuestos el conteo es aceptable. La exportación sí recorre por id (`lazyById`). |
| D-12 | Dashboard servido por un endpoint de agregados (`GROUP BY`) sin caché; su tiempo se mide en la Fase 6. | Caché en Redis / tablas resumen | Con los índices, tres `GROUP BY` sobre 100.000 filas responden en milisegundos; cachear antes de medir es optimizar a ciegas. Caché y tablas resumen quedan en el anexo. |
| D-13 | Tiempo real con Laravel Reverb + Laravel Echo. El evento se emite de forma sincrónica (`ShouldBroadcastNow`) después del commit, dentro de un try/catch que solo registra el error; lleva un payload mínimo y el cliente vuelve a consultar. | Pusher / polling / broadcast en cola | Oficial y corre en Docker sin servicios externos. Sincrónico porque dentro de la red de Docker la llamada a Reverb tarda milisegundos; si Reverb falla, el comando igual se guarda. El payload mínimo no filtra datos fuera de la visibilidad del usuario. |
| D-14 | Sin Redis ni worker de colas: `QUEUE_CONNECTION=sync`, y caché y rate limiting con el driver `database` que Laravel trae por defecto. | Redis + contenedor `queue` | El PRD no pide colas ni Redis, y se ahorran dos contenedores. Ninguna clase implementa `ShouldQueue`: sin worker quedaría encolada para siempre. En producción el broadcast pasaría a una cola o a un outbox (anexo, fila 19). |
| D-15 | Auditoría propia (`incident_activities`) alimentada por eventos de dominio. | spatie/laravel-activitylog | Línea de tiempo semántica ("cambió de Abierto a En progreso") en vez de diffs genéricos del modelo. |
| D-16 | spatie/laravel-permission + Policies. | Columna `role` + gates | Permisos granulares que el frontend recibe para mostrar u ocultar acciones. |
| D-17 | Exportación CSV/XLSX en streaming con spatie/simple-excel, con los mismos filtros y visibilidad del listado. | maatwebsite/excel | Escritura en streaming con memoria constante y API mínima. |
| D-18 | Vue 3 + Quasar 2 con Vite (`@quasar/vite-plugin`), TypeScript, Pinia y Axios. | Vuetify / Quasar CLI | QTable trae paginación en servidor y modo grid responsive; Notify, Dialog y Loading resuelven la UX de errores. El plugin de Vite se instala sin asistentes interactivos, ideal para automatizar. |
| D-19 | Gráficas con Apache ECharts (`vue-echarts`). | Chart.js | Modular, responsive y con eventos de clic para navegar del gráfico al listado filtrado. |
| D-20 | Un gateway Nginx publica todo en `localhost:8080`: SPA, `/api` y WebSocket `/app`. | Puertos separados con CORS | Mismo origen: sin CORS y una sola URL para quien evalúa. |
| D-21 | Tests con Pest contra MySQL real (BD `incidents_test`). | SQLite en memoria | SQLite no soporta `MATCH … AGAINST` ni se comporta igual con índices. |
| D-22 | `Model::shouldBeStrict()` fuera de producción. | Revisión manual | Un lazy loading lanza excepción, así los N+1 se detectan en desarrollo y en tests. |
| D-23 | El backend es la fuente de verdad de la validación (FormRequest con mensajes en español); el frontend replica reglas básicas y muestra los 422 en cada campo. | Validar solo en el frontend | Defensa en profundidad con respuesta inmediata al usuario. |
| D-24 | El backend calcula las acciones permitidas (`allowed_transitions`, `can`). | Duplicar reglas en Vue | El frontend no reimplementa el dominio; solo muestra u oculta. |

## 6. Modelo de datos e índices

Dos tablas de dominio (`incidents`, `incident_activities`) más `users` y las tablas de Sanctum y spatie/laravel-permission; cada índice existe porque atiende una consulta concreta del listado o del dashboard.

**`users`**: `id`, `name`, `email` (único), `password`, `is_active` (bool, por defecto true), `timestamps`. Los roles van en las tablas de spatie; los tokens en `personal_access_tokens` con `expires_at`.

**`incidents`**

| Columna | Tipo | Nulo | Nota |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED PK AI | No | Requerido por el PRD. |
| `type` | VARCHAR(20) | No | `incident` por defecto. |
| `title` | VARCHAR(150) | No | R-03. |
| `description` | TEXT | No | R-03; no se carga en el listado. |
| `priority` | TINYINT UNSIGNED | No | 1 baja, 2 media, 3 alta, 4 crítica (D-09). |
| `status` | VARCHAR(20) | No | `open` por defecto. |
| `created_by` | BIGINT UNSIGNED FK `users` | No | `restrictOnDelete`. |
| `assigned_to` | BIGINT UNSIGNED FK `users` | Sí | `nullOnDelete`. |
| `due_at` | DATETIME (UTC) | No | R-05, R-06. |
| `resolved_at` | DATETIME | Sí | Se fija al resolver. |
| `closed_at` | DATETIME | Sí | Se fija al cerrar. |
| `created_at`, `updated_at` | TIMESTAMP | No | Timestamps del PRD. |
| `deleted_at` | TIMESTAMP | Sí | Soft delete (R-14). |

**`incident_activities`**: `id`, `incident_id` (FK, `cascadeOnDelete`), `user_id` (FK nullable, `nullOnDelete`), `action` VARCHAR(40) (`created`, `updated`, `assigned`, `unassigned`, `status_changed`, `reprioritized`, `deleted`), `changes` JSON (`{campo: {from, to}}`), `note` VARCHAR(500) nullable, `created_at`. Índice `(incident_id, id)` para la línea de tiempo.

**Índices de `incidents`**

| Índice | Columnas | Consulta que atiende |
| --- | --- | --- |
| `idx_incidents_status_due` | (`status`, `due_at`) | Conteo de vencidos (estado activo y `due_at` < ahora); filtro por estado; agrupación por estado del dashboard. |
| `idx_incidents_priority_status` | (`priority`, `status`) | Filtro por prioridad; agrupación por prioridad del dashboard. |
| `idx_incidents_assignee_status` | (`assigned_to`, `status`) | Filtro por responsable, "mis casos" y "sin asignar" del dashboard; cubre la FK. |
| `idx_incidents_creator_status` | (`created_by`, `status`) | Visibilidad del requester (R-16); cubre la FK. |
| `idx_incidents_created_at` | (`created_at`) | Rango por fecha de creación, orden por defecto y creados por día de la tendencia. |
| `idx_incidents_due_at` | (`due_at`) | Rango y orden por vencimiento. |
| `idx_incidents_resolved_at` | (`resolved_at`) | Resueltos por día de la tendencia (últimos 30 días). |
| `ft_incidents_search` | FULLTEXT (`title`, `description`) | Búsqueda por texto (D-10). |

`deleted_at` no se indexa: casi todas las filas son NULL y el filtro no es selectivo. Criterio de aceptación de la Fase 5: el `EXPLAIN` de las cinco consultas principales, con 100.000 filas, no muestra `type = ALL` y queda documentado en `docs/performance.md`.

## 7. Contratos API backend ↔ frontend

Toda la comunicación pasa por `/api/v1` en el mismo origen, con un único formato de error; los tipos TypeScript de esta sección se copian tal cual a `frontend/src/types/api.ts` y los API Resources de Laravel deben producir exactamente esas formas.

**Convenciones**

- Headers: `Accept: application/json`, `Content-Type: application/json`, `Authorization: Bearer <token>` y `X-Request-Id` (si el cliente no lo envía, el backend lo genera y lo devuelve en la respuesta).
- JSON en snake\_case; fechas ISO-8601 en UTC (`2026-10-31T20:15:00Z`); enums como códigos de texto.
- Un recurso: `{ "data": {…} }`. Una colección paginada: `{ "data": […], "meta": {…}, "links": {…} }`.
- Límites: login 5 intentos por minuto (email + IP); resto de la API 120 peticiones por minuto por usuario.

**Formato único de error**

```json
{
  "message": "Los datos enviados no son válidos.",
  "code": "VALIDATION_ERROR",
  "errors": { "title": ["El título debe tener al menos 5 caracteres."] },
  "request_id": "9f1c2b7e-4d2a-4a8e-9a51-0c3b2f6d1e77"
}
```

| HTTP | `code` | Cuándo | Qué hace el frontend |
| --- | --- | --- | --- |
| 401 | `UNAUTHENTICATED` | Sin token, token expirado o revocado | El interceptor limpia la sesión y redirige a `/login?redirect=…` |
| 403 | `FORBIDDEN` | Sin permiso para la acción | Notificación "No tienes permiso para esta acción" |
| 404 | `NOT_FOUND` | No existe o está fuera de la visibilidad del usuario | Página o notificación de no encontrado |
| 409 | `INVALID_STATUS_TRANSITION`, `ASSIGNEE_REQUIRED`, `INCIDENT_LOCKED` | Regla de dominio incompatible con el estado actual | Notificación con `message` y recarga del detalle |
| 422 | `VALIDATION_ERROR` | Entrada inválida (incluye credenciales incorrectas en login) | Muestra `errors` en cada campo del formulario |
| 429 | `TOO_MANY_REQUESTS` | Límite de peticiones | Notificación con indicación de reintento |
| 500 | `SERVER_ERROR` | Error inesperado; sin traza en producción | Notificación genérica con `request_id` |

El login responde 422 con el mismo mensaje ("Credenciales inválidas") para usuario inexistente, contraseña errada o usuario inactivo, para no revelar qué cuentas existen.

**Endpoints**

| Método | Ruta | Permiso | Body | Respuesta |
| --- | --- | --- | --- | --- |
| GET | `/health` | Público | — | 200 `{status, db}` |
| POST | `/auth/login` | Público | `{email, password}` | 200 `{data: LoginResponse}` |
| POST | `/auth/logout` | Autenticado | — | 204 (revoca el token actual) |
| GET | `/auth/me` | Autenticado | — | 200 `{data: AuthUser}` |
| GET | `/catalogs` | Autenticado | — | 200 `{data: Catalogs}` |
| GET | `/users/assignable?search=` | `incidents.assign` | — | 200 `{data: UserSummary[]}` (máximo 20) |
| GET | `/incidents` | Autenticado (R-16) | Query (tabla siguiente) | 200 `Paginated<IncidentListItem>` |
| POST | `/incidents` | `incidents.create` | `IncidentCreatePayload` | 201 `{data: IncidentDetail}` + header `Location` |
| GET | `/incidents/{id}` | Autenticado (R-16) | — | 200 `{data: IncidentDetail}` |
| PATCH | `/incidents/{id}` | Policy `update` / `reprioritize` | `IncidentUpdatePayload` | 200 `{data: IncidentDetail}` |
| POST | `/incidents/{id}/transition` | Policy `transition` | `TransitionPayload` | 200 `{data: IncidentDetail}` |
| POST | `/incidents/{id}/assign` | Policy `assign` | `AssignPayload` | 200 `{data: IncidentDetail}` |
| DELETE | `/incidents/{id}` | `incidents.delete` | — | 204 |
| GET | `/incidents/{id}/activities?page=` | Autenticado (R-16) | — | 200 `Paginated<Activity>` (20 por página, más reciente primero) |
| GET | `/incidents/export?format=csv\|xlsx&…filtros` | `incidents.export` | — | 200 archivo en streaming con `Content-Disposition` |
| GET | `/dashboard/stats?date_from=&date_to=`&type= | `dashboard.view` (R-16) | — | 200 `{data: DashboardStats}` |

`PATCH` con `priority` exige el permiso de repriorizar además del de editar. La ruta `/incidents/export` se declara antes de `/incidents/{id}` para que no la capture el parámetro.

**Parámetros del listado** (los mismos aplican a la exportación, sin paginación)

| Parámetro | Ejemplo | Regla |
| --- | --- | --- |
| `page` | `1` | Entero ≥ 1. |
| `per_page` | `15` | 5 a 100; por defecto 15. |
| `sort` | `-priority` | Lista blanca: `id`, `title`, `priority`, `status`, `due_at`, `created_at`, `updated_at`. `-` = descendente. Por defecto `-created_at`; desempate siempre por `-id`. |
| `search` | `impresora` | 2 a 100 caracteres. Un número o `INC-123` busca por id exacto; el resto usa FULLTEXT con los operadores booleanos del usuario escapados. |
| `filter[status]` | `open,in_progress` | Lista separada por comas de estados válidos. |
| `filter[priority]` | `high,critical` | Lista de prioridades válidas. |
| `filter[assigned_to]` | `12` | Id de usuario, `me` o `unassigned`. |
| `filter[type]` | `incident` | `incident` o `request`. |
| `filter[overdue]` | `true` | Solo vencidos (R-13). |
| `filter[date_field]` | `created_at` | `created_at` (defecto) o `due_at`. |
| `filter[date_from]`, `filter[date_to]` | `2026-10-01` | `YYYY-MM-DD`, inclusivos, interpretados en America/Bogota y convertidos a UTC; `date_to` ≥ `date_from`. |

Cualquier parámetro inválido responde 422; nunca se ignora en silencio.

**Indicadores del dashboard**

Todos se calculan sobre `visibleTo($user)` y la población filtrada (`date_from` y `date_to` sobre `created_at`, y `type`), salvo la tendencia, que ignora el rango. Son cinco consultas; el resto se deriva de ellas.

| Indicador | Definición | Cómo se calcula |
| --- | --- | --- |
| Total | Incidentes de la población filtrada. | Suma de `by_status`. |
| Activos | Incidentes en `open`, `in_progress` u `on_hold`. | Suma de esos estados en `by_status`. |
| Vencidos | Activos con `due_at` anterior a ahora (R-13). | Suma de `by_priority[].overdue`. |
| Sin asignar | Activos sin `assigned_to`. | `COUNT` con `assigned_to IS NULL` y estado activo. |
| Por estado | Conteo de cada estado, en el orden del ciclo de vida, con 0 si no hay casos. | `GROUP BY status`. |
| Por prioridad | Conteo por prioridad y, dentro de cada una, cuántos están vencidos. | `GROUP BY priority` con `SUM(CASE WHEN … THEN 1 ELSE 0 END)` para los vencidos. |
| Tendencia | Creados y resueltos por día durante los últimos 30 días, en días de America/Bogota, con 0 en días sin datos. | Dos `GROUP BY DATE(CONVERT_TZ(columna, '+00:00', '-05:00'))`, sobre `created_at` y sobre `resolved_at`. |

Colombia no tiene horario de verano, así que el desfase fijo de −05:00 es exacto y no requiere las tablas de zonas horarias de MySQL. Un caso reabierto pierde su `resolved_at` (sección 4) y deja de contar como resuelto en la tendencia hasta que se resuelva de nuevo.

**Tipos (copiar a `frontend/src/types/api.ts`)**

```ts
export type Priority = 'low' | 'medium' | 'high' | 'critical';
export type IncidentStatus = 'open' | 'in_progress' | 'on_hold' | 'resolved' | 'closed' | 'cancelled';
export type IncidentType = 'incident' | 'request';
export type Role = 'admin' | 'agent' | 'requester';

export interface UserSummary { id: number; name: string }
export interface AuthUser extends UserSummary { email: string; roles: Role[]; permissions: string[] }
export interface LoginResponse { token: string; token_type: 'Bearer'; expires_at: string; user: AuthUser }

export interface IncidentListItem {
  id: number;
  code: string;                 // "INC-000123"
  type: IncidentType;
  title: string;
  priority: Priority;
  status: IncidentStatus;
  is_overdue: boolean;
  due_at: string;
  creator: UserSummary;
  assignee: UserSummary | null;
  created_at: string;
  updated_at: string;
}

export interface IncidentDetail extends IncidentListItem {
  description: string;
  resolved_at: string | null;
  closed_at: string | null;
  allowed_transitions: IncidentStatus[];   // calculado para el usuario actual
  can: { update: boolean; reprioritize: boolean; assign: boolean; delete: boolean };
}

export interface IncidentCreatePayload {
  type?: IncidentType;          // por defecto 'incident'
  title: string;
  description: string;
  priority: Priority;
  due_at?: string;              // si falta, lo calcula DueDatePolicy
  assigned_to?: number | null;  // solo con permiso incidents.assign
}
export type IncidentUpdatePayload = Partial<Pick<IncidentCreatePayload, 'type' | 'title' | 'description' | 'priority' | 'due_at'>>;
export interface TransitionPayload { status: IncidentStatus; note?: string }  // note obligatoria al reabrir
export interface AssignPayload { assigned_to: number | null }

export type ActivityAction = 'created' | 'updated' | 'assigned' | 'unassigned' | 'status_changed' | 'reprioritized' | 'deleted';
export interface Activity {
  id: number;
  action: ActivityAction;
  changes: Record<string, { from: unknown; to: unknown }>;
  note: string | null;
  actor: UserSummary | null;
  created_at: string;
}

export interface CatalogItem<T extends string> { value: T; label: string }
export interface Catalogs {
  priorities: CatalogItem<Priority>[];
  statuses: (CatalogItem<IncidentStatus> & { is_terminal: boolean })[];
  types: CatalogItem<IncidentType>[];
}

export interface DashboardFilters { date_from?: string; date_to?: string; type?: IncidentType }
export interface DashboardStats {
  total: number;
  active: number;               // open + in_progress + on_hold
  overdue: number;              // activos con due_at vencido
  unassigned: number;           // activos sin responsable
  by_status: { status: IncidentStatus; count: number }[];                 // todos los estados, orden del ciclo de vida, con 0
  by_priority: { priority: Priority; count: number; overdue: number }[];  // todas las prioridades, con 0
  trend: { date: string; created: number; resolved: number }[];           // 30 días (YYYY-MM-DD, America/Bogota), con 0
  generated_at: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null };
  links: { first: string; last: string; prev: string | null; next: string | null };
}

export interface ApiError { message: string; code: string; errors?: Record<string, string[]>; request_id?: string }
```

## 8. Contratos de tiempo real

Laravel emite hacia Reverb, de forma sincrónica y después del commit, eventos con un payload mínimo por dos tipos de canal privado; el frontend nunca pinta datos del evento, solo decide qué volver a consultar.

**Canales**

| Canal | Quién se suscribe (autorizado en `routes/channels.php`) | Eventos |
| --- | --- | --- |
| `private-incidents.staff` | Usuarios con rol `admin` o `agent` | `incident.created`, `incident.updated`, `incident.status_changed`, `incident.assigned`, `incident.deleted` |
| `private-users.{id}` | Solo el usuario `{id}` | `incident.assigned_to_you`; además, los cinco eventos anteriores cuando afectan un caso que ese usuario creó |

**Payload común**

```json
{
  "id": 123,
  "code": "INC-000123",
  "event": "incident.status_changed",
  "changes": ["status"],
  "actor": { "id": 4, "name": "Ana Agente" },
  "occurred_at": "2026-10-31T20:15:00Z"
}
```

**Comportamiento esperado en el frontend**

- **Listado:** ante `created` o `deleted`, o si el `id` está en la página visible, vuelve a consultar con debounce de 1 s y muestra un aviso discreto ("Se actualizó la lista").
- **Detalle:** si el `id` coincide y el actor no es el usuario actual, recarga detalle y línea de tiempo.
- **Dashboard:** recarga las estadísticas con debounce de 2 s.
- **`incident.assigned_to_you`:** notificación con enlace al detalle.

**Conexión**

- Autorización de canales en `POST /api/broadcasting/auth` con el mismo token Bearer (`Broadcast::routes` con middleware `auth:sanctum` y prefijo `api`).
- Echo usa el broadcaster `reverb` contra el mismo origen; el gateway enruta `/app` y `/apps` al contenedor `reverb`.
- Echo se conecta después del login y se desconecta en el logout; un indicador en la cabecera muestra si la conexión está activa.
- Si el WebSocket falla, la aplicación sigue funcionando: los datos se cargan por HTTP y hay un botón "Actualizar".

**Emisión.** Los eventos implementan `ShouldBroadcastNow` y los dispara un listener que corre después del commit. Si Reverb no responde, el listener registra el error en el log y el comando responde normalmente: el tiempo real nunca rompe una operación de negocio.

## 9. Estructura: infraestructura, backend y frontend

Cinco contenedores detrás de un único gateway en `localhost:8080`; el backend se organiza por contextos y capas DDD Lite, y el frontend por responsabilidad, con un único cliente HTTP.

&#91;embedded content: topología de Docker · 5 contenedores, 1 puerto expuesto\]

app y reverb comparten la misma imagen PHP; app guarda en MySQL y avisa a reverb en el mismo request; solo el gateway publica un puerto en el host.

**Servicios de Docker**

| Servicio | Imagen base | Puerto | Responsabilidad | Healthcheck |
| --- | --- | --- | --- | --- |
| `gateway` | `nginx:1.27-alpine` | 8080 → 80 | `/` → `frontend`; `/api` `(incluye /api/broadcasting/auth)` → `app` por FastCGI; `/app` y `/apps` → `reverb` (upgrade WebSocket) | `wget` a `/api/v1/health` |
| `app` | `php:8.4-fpm-alpine` (Dockerfile propio) | 9000 interno | API Laravel. Entrypoint: espera BD, asegura `APP_KEY`, migra y siembra si está vacío | `php-fpm` responde ping |
| `reverb` | Misma imagen que `app` | 8080 interno | `php artisan reverb:start --host=0.0.0.0 --port=8080` | Puerto abierto |
| `mysql` | `mysql:8.4` | 3306 interno (opcional 33060 en host) | Datos; script de init crea `incidents_test` | `mysqladmin ping` |
| `frontend` | `node:24-alpine` (build) → `nginx:alpine` | 80 interno | SPA compilada, con fallback a `index.html` | `wget` a `/` |

Orden de arranque con `depends_on: condition: service_healthy`: `mysql` → `app` → `reverb` y `frontend` → `gateway`. Solo `app` ejecuta migraciones y seeders (`RUN_MIGRATIONS=true`); `reverb` arranca con la misma imagen sin hacerlo.

Como el backend es solo API, el gateway pasa a FastCGI con `SCRIPT_FILENAME=/var/www/html/public/index.php` fijo y no necesita los archivos de Laravel montados.

**Backend (`backend/app`)**

```text
Domain/
  Incidents/
    Models/Incident.php              agregado raíz: Eloquent + métodos de dominio
    Enums/Priority.php, IncidentStatus.php, IncidentType.php
    Events/IncidentRegistered.php, IncidentDetailsUpdated.php, IncidentReprioritized.php,
           IncidentAssigned.php, IncidentUnassigned.php, IncidentStatusChanged.php, IncidentDeleted.php
    Exceptions/DomainRuleViolation.php (base, lleva code y HTTP 409),
               InvalidStatusTransition.php, AssigneeRequired.php, IncidentLocked.php
    Services/DueDatePolicy.php
  Identity/
    Models/User.php
    Enums/Role.php
  Audit/
    Models/IncidentActivity.php
    Listeners/RecordIncidentActivity.php      sincrónico, misma transacción
Application/
  Incidents/
    Actions/RegisterIncident.php, UpdateIncidentDetails.php, AssignIncident.php,
            TransitionIncidentStatus.php, DeleteIncident.php
    Data/  DTOs readonly construidos desde los FormRequest
    Queries/ListIncidentsQuery.php, IncidentFilters.php
  Reporting/
    Queries/DashboardStatsQuery.php
    Exports/IncidentsExport.php
Infrastructure/
  Broadcasting/IncidentChanged.php, IncidentAssignedToYou.php   ShouldBroadcastNow
  Broadcasting/Listeners/BroadcastIncidentChange.php             después del commit, con try/catch y log
Http/
  Controllers/Api/V1/  AuthController, IncidentController, IncidentTransitionController,
                       IncidentAssignmentController, IncidentActivityController,
                       IncidentExportController, DashboardController, CatalogController,
                       AssignableUserController, HealthController
  Requests/  un FormRequest por endpoint de escritura + ListIncidentsRequest
  Resources/ IncidentListResource, IncidentDetailResource, ActivityResource, UserResource
  Middleware/AssignRequestId.php, ForceJsonResponse.php
Policies/IncidentPolicy.php
```

Notas para Claude Code: como los modelos no están en `App\Models`, registra las policies con `Gate::policy()` y enlaza cada factory con `newFactory()` o el atributo de factory del modelo. Los eventos de dominio no saben de WebSockets: un listener en `Infrastructure` los traduce a eventos de broadcast.

**Frontend (`frontend/src`)**

```text
plugins/      quasar.ts (lang es, Notify, Dialog, Loading), echo.ts
api/          http.ts (instancia Axios + interceptores), auth.api.ts, incidents.api.ts,
              dashboard.api.ts, catalogs.api.ts, users.api.ts
types/        api.ts (sección 7, sin cambios)
stores/       auth.store.ts, catalogs.store.ts
composables/  useIncidentFilters.ts (filtros ↔ query de la URL), useServerErrors.ts (422 → campos),
              usePermissions.ts, useRealtime.ts, useDebouncedRefetch.ts
router/       routes.ts, guards.ts
layouts/      AuthLayout.vue, MainLayout.vue
pages/        LoginPage.vue, DashboardPage.vue, IncidentsListPage.vue, IncidentFormPage.vue,
              IncidentDetailPage.vue, ForbiddenPage.vue, NotFoundPage.vue
components/   incidents/ IncidentFilters, StatusBadge, PriorityChip, TransitionActions,
                         AssigneeSelect, ActivityTimeline
              dashboard/ StatCard, StatusChart, PriorityChart, TrendChart
              common/    EmptyState, ConfirmDialog
utils/        dates.ts (UTC ↔ America/Bogota), presentation.ts (color e ícono por estado y prioridad)
```

Rutas: `/login`, `/dashboard` (inicio), `/incidents`, `/incidents/new`, `/incidents/:id`, `/incidents/:id/edit`, `/403` y una ruta comodín de 404. Cada ruta declara `meta.requiresAuth` y, si aplica, `meta.permission`.

**Cliente HTTP e interceptores (`api/http.ts`)**

- Ningún componente usa Axios directamente; todo pasa por los módulos `*.api.ts`.
- **Petición:** agrega `Authorization` si hay token, `Accept: application/json` y un `X-Request-Id` (UUID).
- **Respuesta con error:** normaliza a `ApiError` y actúa según la tabla de la sección 7. En 401 limpia el store y redirige a login (excepto si la petición fallida es el propio login). Sin respuesta (red caída) se trata como error de servidor. Siempre rechaza la promesa con el `ApiError` para que la vista decida.
- **Token:** se guarda en `localStorage` (`incidents.auth` = `{token, expires_at}`); al iniciar la app se descarta si expiró y, si sigue vigente, se hidrata el usuario con `GET /auth/me`.

## 10. Fases de implementación

Doce fases en orden estricto: repositorio, scaffolding, Docker funcionando, cinco fases de backend y tres de frontend, y una de calidad y entrega. Cada fase termina con sus criterios cumplidos y un commit.

### Fase 0 — Repositorio y convenciones

1. `git init` con rama `main` y carpetas `backend/`, `frontend/`, `docker/`, `docs/`.
2. `.gitignore` (vendor, node\_modules, .env, dist, logs), `.editorconfig` y `.gitattributes` con `* text=auto eol=lf` (evita que los scripts `.sh` se rompan en Windows).
3. `docs/PLAN.md` (este documento), `docs/decisions.md` (el documento "Decisiones técnicas" exportado; las decisiones nuevas se agregan ahí con el siguiente ID libre) y `docs/api-contract.md` (secciones 7 y 8).
4. README con las secciones que exige el PRD, aún vacías; `.env.example` en la raíz con variables de Compose y valores por defecto.

- [ ] Primer commit: `chore: initial repository structure`.

### Fase 1 — Scaffolding sin dependencias locales

1. Crear Laravel 13 en `backend/` con un contenedor efímero de Composer (`composer create-project laravel/laravel . "^13.0"`).
2. Dentro de un contenedor PHP 8.4: `php artisan install:api` (Sanctum y `routes/api.php`) y `php artisan install:broadcasting --reverb`; no migrar todavía.
3. Paquetes: `spatie/laravel-permission`, `spatie/simple-excel`; de desarrollo: `pestphp/pest` y `pestphp/pest-plugin-laravel` (`pest --init`).
4. Crear el frontend en `frontend/` con un contenedor `node:24-alpine`: plantilla Vite `vue-ts`, luego `quasar`, `@quasar/extras`, `@quasar/vite-plugin`, `sass-embedded`, `vue-router`, `pinia`, `axios`, `laravel-echo`, `pusher-js`, `echarts`, `vue-echarts`; de desarrollo: `eslint`, `prettier`, `vue-tsc`.

- [ ] `backend/` y `frontend/` versionados sin `vendor/` ni `node_modules/`.
- [ ] Commit: `chore: scaffold laravel 13 api and vue 3 quasar spa`.

### Fase 2 — Docker y arranque del entorno

1. `docker/php/Dockerfile`: `php:8.4-fpm-alpine` con `pdo_mysql`, `pcntl`, `intl`, `zip`, `bcmath` y `opcache`; instala dependencias de Composer (incluidas las de desarrollo, para poder correr tests dentro del contenedor).
2. `docker/php/entrypoint.sh`: espera a MySQL; si `APP_KEY` está vacía, genera una y la persiste en el volumen de `storage`; si `RUN_MIGRATIONS=true`, ejecuta `migrate --force` y el comando `app:seed-if-empty`; termina con `exec php-fpm`.
3. `docker/nginx/gateway.conf` con las rutas de la sección 9 (incluye cabeceras de upgrade para WebSocket).
4. `docker/frontend/Dockerfile` multi-stage: build con Node 24 (variables `VITE_*` como build args) y servido con `nginx:alpine` con fallback a `index.html`.
5. `docker/mysql/init/01-test-db.sql` crea `incidents_test` con permisos; `docker/mysql/conf.d/my.cnf` fija `utf8mb4` y `utf8mb4_0900_ai_ci`.
6. `docker-compose.yml` con los 5 servicios, healthchecks, `depends_on` con `service_healthy`, volúmenes nombrados (`mysql-data`, `app-storage`) y valores por defecto `${VAR:-valor}` para que funcione sin crear `.env`.
7. `compose.dev.yml` opcional: monta el código como volumen y sirve el frontend con Vite y recarga en caliente detrás del mismo gateway.
8. Endpoint `GET /api/v1/health` que verifica la conexión a la BD.

- [ ] `docker compose up -d --build` en un clon limpio deja los 5 servicios `healthy` sin pasos manuales.
- [ ] `http://localhost:8080` muestra la SPA y `/api/v1/health` responde 200 con `db` en `ok`.
- [ ] `docker compose down` + `up -d` no duplica migraciones ni datos.
- [ ] Nada de PHP, Composer o Node instalado en el host.
- [ ] Commit: `build(docker): compose stack with gateway, php-fpm, reverb, mysql and spa`.

### Fase 3 — Backend: fundamentos, autenticación y autorización

1. Configuración: `APP_TIMEZONE=UTC`, `APP_LOCALE=es`, QUEUE\_CONNECTION=sync, CACHE\_STORE=database, `lang/es/validation.php` con los mensajes de las reglas usadas y nombres de atributos en español.
2. Middleware `AssignRequestId` y `ForceJsonResponse` en el grupo `api`; el `request_id` se agrega al contexto de los logs.
3. Manejo de excepciones en `bootstrap/app.php`: cada excepción se traduce al formato único de error (sección 7); `DomainRuleViolation` → 409 con su `code`; en producción, sin trazas.
4. Sanctum: expiración de 480 minutos; `AuthController` con login, logout y me; limitador `login` de 5 por minuto por email + IP; usuarios inactivos no inician sesión.
5. spatie/laravel-permission: enum `Role`, `RolesAndPermissionsSeeder` con la matriz de la sección 4; `UserResource` devuelve roles y permisos.
6. `UserSeeder`: 1 admin, 5 agentes y 10 solicitantes; tres cuentas demo con credenciales fijas (`admin@psicol.test`, `agente@psicol.test`, `solicitante@psicol.test`, contraseña `Password123!`).
7. Rutas `api/v1` con `auth:sanctum` y limitador de 120 por minuto.
8. Tests Pest: login correcto, credenciales inválidas (422), logout revoca el token (la siguiente petición da 401), ruta protegida sin token (401) y forma del error.

- [ ] Tests en verde dentro del contenedor.
- [ ] `GET /auth/me` devuelve roles y permisos.
- [ ] Commit: `feat(auth): sanctum token auth, roles and permissions, error envelope`.

### Fase 4 — Backend: dominio de incidentes (escritura)

1. Migraciones de `incidents` e `incident_activities` con columnas e índices de la sección 6.
2. Enums `Priority` (respaldado por int, con `code()` y `fromCode()`), `IncidentStatus` (con tabla de transiciones, `active()`, `isTerminal()`) e `IncidentType`, todos con `label()` en español.
3. Modelo `Incident`: `SoftDeletes`, casts a enums y `datetime`, relaciones `creator`, `assignee` y `activities`, scopes `visibleTo()`, `active()` y `overdue()`, accessors `is_overdue` y `code`, y los métodos de dominio de la sección 4 que acumulan eventos.
4. `DueDatePolicy` y `config/incidents.php` con los días de SLA.
5. Actions: abren transacción, invocan el método de dominio, guardan y despachan los eventos acumulados. El listener de auditoría corre sincrónico dentro de la transacción; el de broadcast corre después del commit, sincrónico y dentro de un try/catch.
6. `IncidentPolicy` con la matriz de permisos y las reglas R-04, R-11, R-14 y R-16.
7. Controllers delgados (autorizan, delegan a la Action y responden con el Resource), FormRequests y Resources con eager loading de `creator:id,name` y `assignee:id,name`.
8. Tests unitarios: todas las combinaciones de la tabla de transiciones (dataset de Pest) y el cálculo del SLA. Tests de integración: crear (creador = usuario autenticado, estado `open`, `due_at` calculado), requester no reprioriza (403), transición inválida (409), `in_progress` sin responsable (409 `ASSIGNEE_REQUIRED`), caso terminal no editable (409), requester no ve casos ajenos (404), solo admin elimina, y cada comando deja una actividad.

- [ ] Tests en verde.
- [ ] Ninguna regla de negocio en controladores ni FormRequests más allá del formato de entrada.
- [ ] Commit: `feat(incidents): aggregate, state machine, actions, policies and audit trail`.

### Fase 5 — Backend: listado avanzado, rendimiento y datos de prueba

1. `ListIncidentsRequest` valida los parámetros de la sección 7 y produce `IncidentFilters`.
2. `ListIncidentsQuery`: parte de `visibleTo($user)`, selecciona solo las columnas del listado (sin `description`), aplica eager loading, filtros, búsqueda (id exacto o `whereFullText` en modo booleano con operadores escapados), orden de la lista blanca con desempate por `id` y `paginate()`.
3. `IncidentSeeder` con inserción masiva en lotes de 1.000 filas (nunca `factory()->create()` fila por fila). Distribución: estados open 25 %, in\_progress 25 %, on\_hold 10 %, resolved 15 %, closed 20 %, cancelled 5 %; prioridades baja 30 %, media 40 %, alta 20 %, crítica 10 %; cerca del 20 % de los activos vencidos; fechas en los últimos 12 meses; títulos y descripciones en español a partir de plantillas variadas (para que la búsqueda sea demostrable). Datos coherentes con las reglas: quien está en progreso o después tiene responsable, `resolved_at` y `closed_at` donde corresponde, y una actividad `created` por incidente.
4. Cantidad configurable con `SEED_INCIDENTS` (5.000 por defecto) y comando `php artisan incidents:seed-bulk --count=100000`.
5. `Model::shouldBeStrict(! app()->isProduction())` en `AppServiceProvider`.
6. `docs/performance.md` con el `EXPLAIN` y el tiempo medido, con 100.000 filas, de: listado sin filtros, filtro por estado y prioridad, búsqueda por texto, `assigned_to=me` y conteo de vencidos.
7. Tests: filtros combinados, búsqueda, `sort` fuera de la lista blanca (422), `per_page` > 100 (422), visibilidad del requester y número de consultas constante del listado con 15 y con 50 resultados (sin N+1).

- [ ] Ningún `EXPLAIN` con `type = ALL`; listado por debajo de 300 ms en local con 100.000 filas.
- [ ] Commit: `feat(incidents): advanced listing, fulltext search, indexes and bulk seeders`.

### Fase 6 — Backend: dashboard, catálogos y exportación

1. `GET /catalogs` construido desde los enums.
2. `DashboardStatsQuery` implementa los indicadores de la sección 7 con cinco consultas sobre `visibleTo($user)`: por estado, por prioridad con sus vencidos, sin asignar, creados por día y resueltos por día. `total`, `active` y `overdue` se derivan de esas agrupaciones. Filtros `date_from` y `date_to` (sobre `created_at`) y `type`; la tendencia ignora el rango y siempre cubre los últimos 30 días. Se completan con 0 los estados, prioridades y días sin datos.
3. Sin caché: medir el tiempo del endpoint con 100.000 filas y anotarlo en `docs/performance.md`. Si supera 300 ms, registrar en `docs/decisions.md` la propuesta de cachear antes de implementarla.
4. Exportación: reutiliza `ListIncidentsRequest` y `ListIncidentsQuery` sin paginar, recorre con `lazyById(1000)` y escribe en streaming con spatie/simple-excel; columnas en español; nombre `incidentes_AAAAMMDD_HHMM.csv|xlsx`.
5. Tests: cifras correctas sobre datos conocidos (incluidos sin asignar, vencidos por prioridad y días sin datos en la tendencia), alcance del requester, filtro por tipo, regla de vencidos, y cabeceras y número de filas de la exportación.

- [ ] Commit: `feat(reporting): dashboard stats, catalogs and csv/xlsx export`.

### Fase 7 — Backend: tiempo real

1. Configurar Reverb (`BROADCAST_CONNECTION=reverb`, variables `REVERB_*`).
2. `IncidentChanged` e `IncidentAssignedToYou` (`ShouldBroadcast`Now), emitidos después del commit dentro de un try/catch que registra el error en el log, con el payload y canales de la sección 8; `BroadcastIncidentChange` traduce los eventos de dominio.
3. Autorización de canales en `routes/channels.php` y `Broadcast::routes` bajo `api` con `auth:sanctum`.
4. Tests: cada evento de dominio emite el broadcast con los canales correctos, la autorización de canales acepta y rechaza según el rol y, si Reverb no responde, el comando igual responde 200 y deja el error en el log.

- [ ] Con dos sesiones abiertas (admin y agente), un cambio de estado en una se refleja en la otra en menos de 2 s.
- [ ] Commit: `feat(realtime): reverb broadcasting of incident changes`.

### Fase 8 — Frontend: fundamentos

1. `plugins/quasar.ts`: idioma español, íconos Material, plugins Notify, Dialog y Loading, colores de marca.
2. `types/api.ts` con los contratos de la sección 7.
3. `api/http.ts` con los interceptores de la sección 9 y los módulos `*.api.ts`.
4. `auth.store.ts` (token, expiración, usuario, permisos; `login`, `logout`, `hydrate`) y `catalogs.store.ts` (se carga una vez tras el login).
5. Router con guards: `requiresAuth`, `permission`, `/login` solo para invitados y regreso a la ruta original con `redirect`.
6. Layouts: `MainLayout` con menú lateral responsive (mini en escritorio, superpuesto en móvil), cabecera con usuario, rol y logout; `AuthLayout` para el login.
7. `LoginPage`: reglas de email y contraseña, errores 422 en los campos, mensaje de 429, botón con estado de carga y envío con Enter.
8. Composable `useServerErrors` para mapear un 422 a cualquier formulario.

- [ ] Login y logout funcionan; un token expirado o revocado lleva al login con un mensaje.
- [ ] Recargar la página mantiene la sesión; las rutas protegidas no se abren sin token.
- [ ] Commit: `feat(frontend): api client, interceptors, auth store, router guards and login`.

### Fase 9 — Frontend: incidentes

1. `IncidentsListPage`: QTable en modo servidor (`v-model:pagination` + `@request`) con código, título, tipo, prioridad, estado, responsable, vencimiento (resaltado si está vencido) y creación; en pantallas pequeñas pasa a tarjetas (modo grid). Filtros: estado y prioridad múltiples, responsable con búsqueda remota, rango de fechas con selector de campo, interruptor de vencidos y búsqueda con debounce de 400 ms. Los filtros viven en la URL. Estados de carga, vacío y error. Botón de exportar CSV/XLSX visible solo con permiso y con los filtros actuales.
2. `IncidentFormPage` (crear y editar): campos según `can`, reglas de Quasar iguales a las del backend, `due_at` opcional con la ayuda "Si lo dejas vacío se calcula según la prioridad", `AssigneeSelect` solo con permiso de asignar, errores 422 en los campos y confirmación al salir con cambios sin guardar.
3. `IncidentDetailPage`: cabecera con código, estado, prioridad y alerta de vencido; descripción y datos; botones de transición generados desde `allowed_transitions` (diálogo con nota obligatoria al reabrir); asignar, editar y eliminar con confirmación; `ActivityTimeline` paginada.
4. Ante un 409: notificación con el mensaje y recarga del detalle.

- [ ] Ningún botón aparece si `can` o `allowed_transitions` no lo permiten.
- [ ] Las tres vistas son usables a 360 px de ancho.
- [ ] Recargar el listado conserva los filtros.
- [ ] Commit: `feat(frontend): incident list, form and detail views`.

### Fase 10 — Frontend: dashboard y tiempo real

1. `DashboardPage`, de arriba abajo:
   - Filtros: rango de fechas sin valor por defecto (atajos 7 días, 30 días y este mes) y tipo.
   - Fila de 4 tarjetas: Total, Activos, Vencidos (con su porcentaje sobre activos) y Sin asignar.
   - Barras horizontales por estado, en el orden del ciclo de vida.
   - Barras por prioridad apiladas en dos tramos, vencidos (resaltado) y el resto.
   - Línea de creados vs. resueltos de los últimos 30 días.
   - Un clic en una tarjeta o barra abre el listado con el filtro equivalente; por ejemplo, Sin asignar → `filter[assigned_to]=unassigned&filter[status]=open,in_progress,on_hold`.
   - En pantallas pequeñas, tarjetas en 2×2 y gráficos apilados. Sin tortas ni donas.
2. `plugins/echo.ts`: broadcaster `reverb`, autorización con el token Bearer y reconexión.
3. `useRealtime`: suscripción por página, limpieza en `onUnmounted` y recarga con debounce según la sección 8; indicador de conexión en la cabecera.

- [ ] El dashboard refleja cambios hechos en otra sesión sin recargar la página.
- [ ] Las cifras coinciden con el listado filtrado equivalente.
- [ ] Commit: `feat(frontend): dashboard charts and realtime updates`.

### Fase 11 — Calidad y entrega

1. Pint en el backend; ESLint, Prettier y `vue-tsc` en el frontend, sin errores.
2. Suite completa: `docker compose exec app php artisan test`.
3. README completo: requisitos (Docker y Compose v2), instalación (`git clone` + `docker compose up -d`), URL, tabla de variables de entorno, migraciones y seeders (automáticos y comandos manuales), credenciales de prueba, cómo correr los tests, resumen de arquitectura con enlace a `docs/`, supuestos y decisiones, y solución de problemas (puerto ocupado, finales de línea en Windows).
4. Prueba desde cero en un clon limpio: `docker compose down -v` y `docker compose up -d --build`.
5. Revisión visual a 360, 768 y 1280 px.

- [ ] Checklist de la sección 11 completo.
- [ ] Commit: `docs: readme, decisions and performance notes`.

## 11. Trazabilidad requisito → entrega

Cada requisito del PRD, obligatorio o ideal, tiene un mecanismo y una fase; esta tabla es el checklist final de la Fase 11.

| Requisito del PRD | Cómo se cumple | Fase |
| --- | --- | --- |
| Login, logout y protección de rutas | Sanctum Bearer, `auth:sanctum`, guards de Vue Router | 3, 8 |
| CRUD con id, título, descripción, prioridad, estado, creador, asignado, vencimiento y timestamps | Tabla `incidents`, Actions, Resources y vistas de formulario y detalle | 4, 9 |
| Paginación y ordenamiento | `ListIncidentsQuery` + QTable en modo servidor | 5, 9 |
| Búsqueda por texto | FULLTEXT en modo booleano o id exacto | 5, 9 |
| Filtros por prioridad, estado, asignado y rango de fechas | `IncidentFilters` sincronizado con la URL | 5, 9 |
| Dashboard: total, por estado, vencidos, por prioridad | `DashboardStatsQuery` sobre índices + ECharts | 6, 10 |
| Contexto: indicadores para seguimiento y análisis de la operación | Activos, sin asignar, vencidos por prioridad y tendencia de creados vs. resueltos de 30 días (S-10) | 6, 10 |
| Eager loading y cero N+1 | `with()` + `shouldBeStrict()` + test de número de consultas | 4, 5 |
| Optimización de consultas e índices SQL | Índices de la sección 6 + `EXPLAIN` en `docs/performance.md` | 4, 5 |
| Estructura limpia de endpoints | `/api/v1`, recursos REST + endpoints por intención | 3 a 7 |
| Frontend: login, dashboard, listado, crear/editar, detalle | Páginas de la sección 9 | 8 a 10 |
| Manejo centralizado de peticiones, interceptores y token | `api/http.ts` + `auth.store.ts` | 8 |
| Docker: compose, Laravel, Nginx, MySQL, frontend Vue | 5 servicios de la sección 9 | 2 |
| Ideal: `docker compose up -d` | Entrypoint que migra y siembra si la BD está vacía | 2 |
| Ideal: WebSockets o tiempo real | Reverb + Echo | 7, 10 |
| Ideal: tests con PHPUnit o Pest | Pest contra MySQL real | 3 a 7, 11 |
| Ideal: roles y permisos | spatie/laravel-permission + `IncidentPolicy` | 3, 4 |
| Ideal: exportación Excel/CSV | spatie/simple-excel en streaming | 6, 9 |
| Ideal: logs o auditoría | `incident_activities` + logs con `request_id` | 3, 4 |
| Entregables: repositorio Git y README completo | Monorepo + README de la Fase 11 | 0, 11 |
| Seeders: ≥ 500 incidentes, múltiples usuarios, estados y prioridades | 5.000 por defecto, 16 usuarios, 6 estados y 4 prioridades | 3, 5 |
| Responsive front / back | Breakpoints de Quasar y modo tarjetas; respuestas paginadas y consistentes | 9, 10 |
| Validaciones visuales front / back | FormRequest en español + reglas Quasar + 422 por campo | 3, 4, 8, 9 |
| Manejo de errores front / back | Formato único de error + interceptores + notificaciones | 3, 8 |
| Debe ser ejecutable | Prueba desde cero en clon limpio | 2, 11 |
