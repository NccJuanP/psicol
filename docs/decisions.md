# Decisiones técnicas — Gestión de Incidentes

Este documento registra qué se decidió y por qué; el cómo y el orden de trabajo están en [`PLAN.md`](PLAN.md). Tiene dos partes:

- **Diseño (secciones 1 a 8):** decisiones tomadas antes de implementar. S-, R-, H- y D-01 a D-24 vienen del plan; D-48 a D-63 se tomaron al detallarlo.
- **Implementación (sección 9):** D-25 a D-47, tomadas durante la implementación. El código, el README y [`performance.md`](performance.md) las citan por ese ID.

Las decisiones nuevas se agregan en la sección 9 con el siguiente ID libre: **D-64**.

## 1. Interpretación del PRD

El PRD permite asumir decisiones razonables si se justifican; estas diez fijan cómo se leyó cada punto ambiguo.

| ID | Decisión | Por qué |
| --- | --- | --- |
| S-01 | Solicitud e incidente son un mismo agregado (`Incident`) con un campo `type`. | Comparten ciclo de vida, campos y filtros; el PRD los nombra juntos. Separarlos duplicaría código sin una regla que lo justifique. |
| S-02 | El filtro de rango de fechas aplica sobre la fecha de creación y permite elegir la de vencimiento. | El PRD no dice qué fecha; ambas sirven para operar. |
| S-03 | "Vencido" se calcula al consultar; no es un estado guardado. | No necesita tareas programadas ni puede quedar desincronizado. |
| S-04 | La fecha de vencimiento siempre existe; si no se envía, se calcula por el SLA de la prioridad. | La métrica de vencidos cubre todos los casos y crear un incidente tiene menos fricción. |
| S-05 | Tres roles: `admin`, `agent` y `requester`. | Cubre "usuario creador" y "usuario asignado" y el ideal de roles y permisos sin sobrediseñar. |
| S-06 | Eliminación lógica, solo por admin. | Conserva la auditoría y los indicadores históricos. |
| S-07 | El dashboard aplica la misma regla de visibilidad que el listado. | Una sola regla evita fugas de datos y cifras que no cuadran entre pantallas. |
| S-08 | UTC en base de datos y API; hora de America/Bogota en la interfaz. | Evita errores de vencimiento por desfase horario. |
| S-09 | 5.000 incidentes por defecto en los seeders y un comando para cargar 100.000. | Cumple el mínimo de 500, demuestra volumen y no alarga el primer arranque. |
| S-10 | El dashboard suma activos, sin asignar, vencidos por prioridad y una tendencia de 30 días. | El PRD pide indicadores para "seguimiento y análisis": las cuatro cifras dicen cómo estamos hoy y la tendencia, hacia dónde vamos. |

## 2. Dominio (DDD Lite)

Se aplica DDD donde protege reglas (lenguaje ubicuo, agregado con invariantes, enums como value objects, eventos de dominio) y se omite donde solo agrega capas. Las reglas completas (R-01 a R-16) están en la sección 4 del plan.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-04 | Monolito modular con cuatro contextos: Incidents (núcleo), Identity & Access, Audit y Reporting. | Microservicios | El tamaño del problema no justifica despliegues separados; los límites en carpetas permiten extraer un módulo después. | Los límites se respetan por convención, no por despliegue. |
| D-05 | Modelo rico sobre Eloquent + Actions (casos de uso) + Query objects para lecturas, sin repositorios. | Repositorios con entidades puras y mappers | Eloquent ya es Active Record; envolverlo duplica código sin proteger mejor las reglas. Actions y Queries separan escritura y lectura (CQRS ligero). | El dominio queda acoplado a Eloquent; cambiar de ORM costaría más. |
| D-48 | Máquina de estados explícita: 6 estados y 8 transiciones en una tabla dentro del enum `IncidentStatus`. | Estado editable libremente / librería externa de state machine | Ciclo de vida predecible, una sola fuente de verdad y probable con un dataset que cubre todas las combinaciones. | Agregar un estado exige tocar el enum y la tabla. |
| D-07 | Endpoints por intención para cambiar estado y asignar (`/transition`, `/assign`). | `PATCH` genérico del campo `status` | Cada comando produce un evento con significado; la regla vive en un solo lugar y la auditoría dice qué pasó, no solo qué campo cambió. | Más endpoints que un CRUD puro. |
| D-15 | Auditoría propia (`incident_activities`) escrita de forma sincrónica dentro de la misma transacción del comando. | spatie/laravel-activitylog / auditoría asíncrona | La línea de tiempo es un read model del dominio ("pasó de Abierto a En progreso"), no un diff genérico. Si falla la auditoría, falla el comando: no hay cambios sin rastro. | Un insert adicional por comando. |
| D-24 | El backend calcula las acciones permitidas para el usuario (`allowed_transitions`, `can`). | Repetir las reglas en Vue | El frontend no reimplementa el dominio; solo muestra u oculta botones. | El detalle pesa un poco más. |

**Decisiones de negocio (hotspots del event storming)**

| ID | Decisión | Por qué |
| --- | --- | --- |
| H-1 | Un agente solo cambia el estado de casos asignados a él; primero se autoasigna. | Todo cambio tiene un responsable visible. |
| H-2 | Cerrado y cancelado son terminales; solo se reabre desde resuelto, con nota obligatoria. | Cerrar es la conformidad del solicitante y el histórico no se reescribe. |
| H-3 | El solicitante solo edita mientras el caso está abierto. | No se cambia el problema mientras alguien trabaja en él. |
| H-4 | El vencimiento lo cambian el asignado o el admin, nunca a una fecha pasada. | Renegociar plazos es normal; crear casos ya vencidos no. |
| H-5 | El reloj del vencimiento no se detiene en "En espera". | Simplicidad; pausar el SLA queda como mejora futura. |
| H-6 | Cierran el creador o un admin. | El cierre representa la conformidad de quien pidió. |
| R-04 | La prioridad la fija quien crea, pero solo la cambian el asignado o un admin. | La criticidad la valida quien atiende, no quien reporta. |
| R-16 | Un caso fuera de la visibilidad del usuario responde 404, no 403. | No revelar que el incidente existe. |

## 3. Arquitectura e infraestructura

Cinco contenedores (gateway, app, reverb, mysql y frontend) detrás de un solo puerto; todo arranca con `docker compose up -d` y no se agrega nada que el PRD no pida.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-01 | Monorepo con `backend/`, `frontend/`, `docker/` y `docs/`. | Dos repositorios | Un solo `git clone` y un solo `docker compose up -d`; el PRD pide un repositorio. | Backend y frontend comparten historial de commits. |
| D-02 | Laravel 13 sobre PHP 8.4. | Laravel 12 / PHP 8.5 | Laravel 13 salió el 17 de marzo de 2026, soporta PHP 8.3 a 8.5 y recibe parches de seguridad hasta Q1 2028 ([Laravel News](https://laravel-news.com/laravel-13-released)). PHP 8.4 maximiza la compatibilidad de paquetes. | No se usan las novedades de PHP 8.5. |
| D-03 | MySQL 8.4 LTS con cotejamiento `utf8mb4_0900_ai_ci`. | MariaDB 11.4 | FULLTEXT en InnoDB maduro, CTE y funciones de ventana; el cotejamiento insensible a tildes hace que "impresion" encuentre "impresión". | — |
| D-20 | Un gateway Nginx publica todo en `localhost:8080`: SPA en `/`, API en `/api` y WebSocket en `/app`. | Puertos separados con CORS | Nginx es obligatorio igual: PHP-FPM no habla HTTP. Darle también el rol de gateway trae mismo origen (sin CORS ni peticiones de preflight por el header `Authorization`), WebSocket sin exponer otro puerto, un build del frontend con rutas relativas que sirve en cualquier entorno, una sola URL para quien evalúa y una topología parecida a producción. | Un archivo de configuración de Nginx más largo y un salto de red interno despreciable. |
| D-14 | Sin Redis ni worker de colas: `QUEUE_CONNECTION=sync`, y caché y rate limiting con el driver `database`. | Redis + contenedor `queue` | El PRD no pide colas ni Redis. En PHP-FPM cada petición arranca la app y muere al responder, así que el trabajo en segundo plano exige un proceso worker aparte, el equivalente a un Worker Service consumiendo Service Bus. Aquí ese trabajo sería solo avisar a Reverb, que dentro de la red de Docker tarda milisegundos. Se ahorran dos contenedores. | El request espera esa llamada. Ninguna clase puede implementar `ShouldQueue`, porque sin worker quedaría encolada para siempre. |
| D-13 | Tiempo real con Laravel Reverb + Echo. Los eventos se emiten con `ShouldBroadcastNow` después del commit, dentro de un try/catch que solo registra el error, y llevan un payload mínimo para que el cliente vuelva a consultar. | Pusher / polling / broadcast en cola | Reverb es oficial y corre en Docker sin servicios externos. Emitir después del commit evita avisar de datos que hicieron rollback. El try/catch garantiza que el tiempo real nunca rompa una operación de negocio. El payload mínimo no filtra datos fuera de la visibilidad de cada usuario. | Si Reverb cae se pierde el aviso, no el dato: la pantalla se corrige en la siguiente consulta. |
| D-50 | El entrypoint del contenedor `app` espera a MySQL, asegura `APP_KEY`, migra y siembra solo si la base está vacía; los demás contenedores no migran. | Pasos manuales en el README | El PRD valora `docker compose up -d` sin más pasos, y reiniciar no duplica datos. | El primer arranque tarda más; migrar automáticamente no es aceptable en producción sin control. |
| D-21 | Tests con Pest contra MySQL real, en una base `incidents_test` creada al iniciar el contenedor. | SQLite en memoria | SQLite no soporta `MATCH … AGAINST` ni usa los índices igual, así que los tests no probarían lo que corre de verdad. | Tests algo más lentos. |

## 4. API y contratos

Una API versionada con un único formato de error, donde el código HTTP distingue entrada inválida, estado que no lo permite y recurso que el usuario no puede ver.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-06 | Sanctum con tokens Bearer que expiran a las 8 horas y se revocan en el logout. | Sanctum en modo cookie / JWT | El PRD pide manejo de token e interceptores. Sanctum es oficial y el token se puede revocar; un JWT no se revoca sin lista negra. | El token vive en `localStorage` y queda expuesto ante un XSS; se mitiga con la expiración, y el modo cookie queda como mejora (anexo, propuesta 12). |
| D-08 | API en `/api/v1`, JSON en snake_case y fechas ISO-8601 en UTC; los tipos TypeScript reflejan la respuesta 1:1. | camelCase con una capa de conversión | Es la convención de Laravel y no hay que traducir nombres entre capas. | En el frontend los nombres usan snake_case. |
| D-09 | En la API los enums viajan como códigos de texto; en la base, la prioridad es `TINYINT` (1–4) y el estado `VARCHAR(20)`. | Tipo `ENUM` de MySQL | Ordenar por prioridad equivale a ordenar por criticidad con un índice pequeño. Un `ENUM` obliga a migrar para agregar un valor. La API no expone cómo se guarda. | Hay que mapear entre número y código en el enum de PHP. |
| D-49 | 422 para entrada inválida, 409 cuando una regla de dominio choca con el estado actual (`INVALID_STATUS_TRANSITION`, `ASSIGNEE_REQUIRED`, `INCIDENT_LOCKED`), 404 para lo que está fuera de la visibilidad, todo con el mismo formato de error y un `request_id`. | Todo como 400 o 422 | El frontend puede reaccionar distinto: marcar campos, refrescar el detalle o mostrar "no encontrado". El `request_id` permite cruzar un error de pantalla con el log. | El frontend maneja más casos. |
| D-51 | El login responde 422 con el mismo mensaje para usuario inexistente, contraseña errada o usuario inactivo, con un límite de 5 intentos por minuto por email e IP. | Mensajes específicos | No revela qué cuentas existen y frena la fuerza bruta. | El usuario no sabe cuál dato falló. |
| D-52 | Un parámetro de consulta inválido (filtro, orden o `per_page` fuera de rango) responde 422; nunca se ignora en silencio. | Ignorar lo inválido | Un filtro ignorado en silencio devuelve cifras falsas sin que nadie lo note. | — |
| D-23 | El backend es la fuente de verdad de la validación (FormRequest con mensajes en español); el frontend replica las reglas básicas y muestra los 422 en cada campo. | Validar solo en el frontend | Defensa en profundidad con respuesta inmediata al usuario. | Las reglas básicas existen en dos lugares. |

## 5. Datos y rendimiento

El rendimiento se resuelve con índices pensados para cada consulta y midiendo con 100.000 filas, antes de agregar infraestructura.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-53 | Índices compuestos, cada uno atado a una consulta concreta: (`status`, `due_at`), (`priority`, `status`), (`assigned_to`, `status`), (`created_by`, `status`), más `created_at`, `due_at` y `resolved_at`. Al medir con 100.000 filas se agregó `deleted_at` como sufijo en los índices de creación, estado y prioridad (D-38, D-42). | Un índice por columna | El índice compuesto resuelve filtro y agrupación en una sola pasada. Como sufijo, `deleted_at` vuelve cubrientes los conteos y las agrupaciones: el dashboard bajó de 356 ms a unos 100 ms sin caché. | Escrituras algo más lentas y más espacio. |
| D-10 | Búsqueda con índice FULLTEXT sobre título y descripción, en modo booleano con prefijo; un número o `INC-123` busca por id exacto. | `LIKE '%texto%'` | `LIKE` con comodín inicial recorre toda la tabla; FULLTEXT escala a cientos de miles de filas. | No hay tolerancia a errores de tipeo (anexo, propuesta 9); términos de menos de 3 caracteres no se indexan. |
| D-11 | Paginación por offset (`page`, `per_page` hasta 100). | Paginación por cursor | La tabla del frontend necesita el total y saltar a la página N; con índices, el conteo es aceptable. | Páginas muy profundas son más lentas, un caso raro en esta operación. |
| D-55 | El listado selecciona solo sus columnas (sin `description`) y carga creador y responsable con eager loading (`id`, `name`). | `SELECT *` | La descripción es `TEXT` y nadie la ve en el listado; el eager loading evita N+1. | — |
| D-22 | `Model::shouldBeStrict()` fuera de producción, más un test que verifica que el listado hace el mismo número de consultas con 15 o con 50 filas. | Revisión manual | Un lazy loading lanza excepción, así los N+1 aparecen en desarrollo y en CI, no en producción. | Hay que declarar las relaciones de cada consulta. |
| D-54 | Seeders con inserción masiva en lotes de 1.000 y datos coherentes con las reglas (responsable donde corresponde, fechas de resolución y cierre, ~20 % de activos vencidos). | `factory()->create()` fila por fila | Cargar 100.000 filas fila por fila tarda minutos; por lotes, segundos. Datos coherentes hacen que el dashboard y la búsqueda se puedan demostrar. | El seeder no pasa por las Actions, así que se le exige coherencia de forma explícita. |
| D-17 | Exportación CSV/XLSX en streaming con spatie/simple-excel, recorriendo por id (`lazyById`) con los mismos filtros y la misma visibilidad del listado. | maatwebsite/excel / armar el archivo en memoria | Memoria constante sin importar cuántas filas se exporten, y el archivo coincide con lo que el usuario ve. | Formato de Excel básico, sin estilos. |

## 6. Dashboard

El dashboard responde dos preguntas: cómo está la operación hoy y hacia dónde va. Se calcula en vivo con cinco consultas sobre índices.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-56 | Indicadores: las cuatro cifras del PRD (total, por estado, vencidos, por prioridad) más activos, sin asignar, vencidos por prioridad y creados vs. resueltos de los últimos 30 días. | Solo las cuatro cifras / KPIs avanzados (tiempo medio de resolución, cumplimiento de SLA) | "Sin asignar" es lo primero que revisa quien reparte trabajo; un crítico vencido no pesa igual que uno bajo; la tendencia cubre el "análisis" que pide el PRD. Todo sale de datos e índices que ya existen. Los KPIs avanzados quedan como mejora. | Una consulta y un gráfico más. |
| D-12 | Cálculo en vivo con cinco consultas (por estado, por prioridad con vencidos, sin asignar, creados por día, resueltos por día); total, activos y vencidos se derivan de ellas. Sin caché. | Caché en Redis / tablas resumen | Con los índices, cada agregación sobre 100.000 filas responde en milisegundos; cachear antes de medir es optimizar a ciegas. La Fase 6 mide el endpoint y solo se cachearía si pasa de 300 ms. Al medir dio 356 ms y se resolvió con índices cubrientes, no con caché (D-42). | A escala de millones de filas habría que revisar (anexo, propuestas 1 y 20). |
| D-57 | Tarjetas para las cifras únicas; barras horizontales por estado en el orden del ciclo de vida; barras por prioridad con el tramo vencido resaltado; una línea para la tendencia. Sin tortas ni donas. | Gráficos de torta | Las barras se comparan de un vistazo y las porciones de una torta no. El orden del ciclo de vida hace que el gráfico por estado se lea como un embudo. | — |
| D-19 | Apache ECharts mediante `vue-echarts`. | Chart.js | El gráfico se describe como un objeto de configuración (ejes, series, colores), casi un DTO, en vez de código que dibuja. Es modular y trae el evento de clic. | Una dependencia más pesada que Chart.js, mitigada importando solo los módulos usados. |
| D-58 | Filtros por rango de fechas (sobre creación, sin valor por defecto) y por tipo. Los vencidos se calculan "a hoy" sobre la población filtrada; la tendencia ignora el rango y siempre muestra los últimos 30 días. | Rango por defecto de 30 días | Sin rango por defecto, "Total" significa el total real. La tendencia con ventana fija siempre se puede comparar. | La tendencia no responde al filtro de fechas, y su título lo indica. |
| D-59 | Los días de la tendencia se agrupan en hora de Bogotá con `CONVERT_TZ(columna, '+00:00', '-05:00')`. | Agrupar por día UTC / cargar las tablas de zonas horarias de MySQL | Colombia no tiene horario de verano, así que el desfase fijo es exacto; agrupar en UTC movería a otro día los casos creados después de las 7 p. m. | Si el sistema se usara en otra zona horaria, habría que parametrizarlo. |
| D-60 | Un clic en una tarjeta o barra abre el listado con el filtro equivalente; ambas pantallas comparten las mismas reglas de filtro y visibilidad. | Dashboard de solo lectura | Del indicador se pasa directo a los casos, y las cifras del dashboard coinciden con el listado filtrado. | — |

## 7. Frontend

La SPA no decide reglas de negocio: consume un contrato tipado, centraliza la comunicación y muestra lo que el backend permite.

| ID | Decisión | Alternativa descartada | Por qué | Costo aceptado |
| --- | --- | --- | --- | --- |
| D-18 | Vue 3 + Quasar 2 instalado con `@quasar/vite-plugin`, TypeScript, Pinia y Axios. | Vuetify / Quasar CLI | Quasar trae una tabla con paginación en el servidor y modo de tarjetas para móvil, y plugins de notificaciones y diálogos para mostrar errores. El plugin de Vite se instala sin asistentes interactivos, así que se puede automatizar. | Sin las utilidades del CLI de Quasar (boot files), reemplazadas por plugins propios. |
| D-61 | Un único cliente HTTP (`api/http.ts`) con interceptores: agrega el token y un `X-Request-Id`, normaliza cada error al formato de la API y en 401 cierra la sesión. Ningún componente usa Axios directamente. | Llamadas a Axios en cada componente | Es lo que pide el PRD (manejo centralizado, interceptores, token) y deja un solo lugar para cambiar autenticación o manejo de errores. | — |
| D-62 | Los filtros, el orden y la página del listado viven en la URL. | Estado solo en memoria o en el store | Recargar o compartir el enlace conserva la vista, el botón Atrás funciona y el dashboard puede abrir el listado ya filtrado. | Hay que sincronizar URL y estado en un composable. |
| D-63 | Los eventos WebSocket solo avisan qué cambió; la vista vuelve a consultar con debounce (1 s el listado, 2 s el dashboard). Si el WebSocket falla, la app sigue funcionando por HTTP. | Pintar los datos que trae el evento | Un evento con datos completos podría mostrar casos fuera de la visibilidad del usuario; con el debounce, varios eventos seguidos producen una sola recarga. | Una petición HTTP extra por cambio visible. |

## 8. Lo que se dejó fuera a propósito

Nada de esto lo pide el PRD; cada punto tiene una condición concreta que lo justificaría y está detallado en el anexo de propuestas.

| Qué | Por qué no ahora | Cuándo sí | Anexo |
| --- | --- | --- | --- |
| Cola con Redis + worker, o patrón outbox | El único trabajo diferible es avisar a Reverb, que tarda milisegundos dentro de Docker. | Cuando haya trabajo pesado (correos, integraciones) o se necesite entrega garantizada de eventos. | 19 |
| Caché del dashboard | No hay una medición que lo justifique. | Si la Fase 6 mide más de 300 ms o hay muchos usuarios concurrentes. | 20 |
| Tablas resumen para estadísticas | Las agregaciones en vivo bastan para 100.000 filas. | Con millones de filas o tendencias de varios años. | 1 |
| Metabase, Superset o ClickHouse | Son cambios de tecnología; el PRD pide indicadores básicos. | Si negocio pide exploración por su cuenta o el volumen llega a decenas de millones. | 3, 4, 5 |
| Sanctum con cookie HttpOnly | Bearer cumple el manejo de token que pide el PRD. | Al endurecer la seguridad para producción; el gateway ya da el mismo origen que requiere. | 12 |
| SLA en horario laboral y pausa en "En espera" | Simplicidad. | Si el negocio mide SLA contractuales. | 14 |
| Búsqueda tolerante a errores de tipeo | FULLTEXT cubre la búsqueda pedida. | Si los usuarios no encuentran casos por ortografía. | 9 |

## 9. Decisiones de implementación (D-25 a D-47)

Tomadas durante la implementación. Formato: **ID — título**, con contexto, decisión y razón.

### D-25 — Este archivo reúne las decisiones de diseño y las de implementación

- **Contexto:** la Fase 0 pedía copiar aquí el documento "Decisiones técnicas", que llegó después de implementar. Sus decisiones nuevas usaban D-25 a D-40, los mismos IDs que ya citaban el código, el README y `performance.md`.
- **Decisión:** ese documento es la parte de diseño de este archivo (secciones 1 a 8) y sus decisiones nuevas se renumeraron como D-48 a D-63. Las de implementación conservan D-25 a D-47.
- **Razón:** todas las referencias existentes siguen apuntando a la decisión correcta.

### D-26 — El backend no lleva frontend propio

- **Contexto:** el esqueleto de Laravel trae Vite, Tailwind y una vista `welcome`, y `install:broadcasting` genera `resources/js/echo.js`.
- **Decisión:** se eliminan `package.json`, `vite.config.js`, `resources/js`, `resources/css` y la vista `welcome`; `routes/web.php` queda vacío.
- **Razón:** el backend es solo API (D-20); la SPA y Echo viven en `frontend/`. Evita un segundo `npm install` y código muerto.

### D-27 — Composer resuelve dependencias para PHP 8.4

- **Contexto:** la imagen `composer:2` trae PHP 8.5, pero el runtime es PHP 8.4 (D-02).
- **Decisión:** `config.platform.php = 8.4.99` en `backend/composer.json`.
- **Razón:** el `composer.lock` nunca exige una versión de PHP que el contenedor `app` no tiene.

### D-28 — Configuración de MySQL por flags y script de init en shell

- **Contexto:** la Fase 2 pide `docker/mysql/conf.d/my.cnf` y `docker/mysql/init/01-test-db.sql`.
- **Decisión:** el cotejamiento va como flags en `command:` del servicio `mysql`, y la BD de tests la crea `01-test-db.sh`.
- **Razón:** MySQL ignora los `.cnf` montados con permisos de escritura global, que es como Docker Desktop en Windows monta los archivos. Un `.sql` no puede leer `MYSQL_USER`, así que el `GRANT` quedaría fijo a un nombre de usuario.

### D-29 — Sin `compose.dev.yml` por ahora

- **Contexto:** la Fase 2 lo marca como opcional.
- **Decisión:** no se crea; el flujo de trabajo es `docker compose up -d --build`.
- **Razón:** menos superficie que mantener; se agrega si la recarga en caliente hace falta en las fases de frontend.

### D-30 — `AssignRequestId` es middleware global

- **Contexto:** la Fase 3 lo ubica en el grupo `api`.
- **Decisión:** se registra como middleware global; `ForceJsonResponse` sí queda en el grupo `api`.
- **Razón:** las rutas inexistentes (404) no pasan por el middleware de grupo y su error quedaría sin `request_id`.

### D-31 — Tabla `users` sin `email_verified_at` ni `remember_token`

- **Contexto:** el esqueleto de Laravel trae esas columnas; la sección 6 del plan no las incluye.
- **Decisión:** se quitan y se agrega `is_active`.
- **Razón:** no hay verificación de correo ni sesiones "recordarme" en una API con tokens Bearer.

### D-32 — Sin DTOs en `Application/Incidents/Data`

- **Contexto:** la sección 9 lista DTOs readonly construidos desde los FormRequest.
- **Decisión:** las Actions reciben el arreglo de `$request->validated()` y convierten a enums y fechas en un solo lugar.
- **Razón:** el FormRequest ya fija la forma de la entrada; un DTO por endpoint repetiría cada campo sin proteger ninguna regla adicional.

### D-33 — No se quita el responsable de un caso en progreso o en espera

- **Contexto:** R-08 exige responsable para pasar a `in_progress`, pero el plan no dice qué pasa al desasignar después.
- **Decisión:** `assignTo(null)` en `in_progress` u `on_hold` responde 409 `ASSIGNEE_REQUIRED`; reasignar a otro agente sí se permite.
- **Razón:** "nada se atiende sin dueño" vale durante toda la atención, no solo al iniciarla.

### D-34 — La fecha de vencimiento la cambian los mismos que repriorizan

- **Contexto:** H-4 dice que `due_at` lo editan el asignado o el admin; el contrato solo expone `can.reprioritize`.
- **Decisión:** un `PATCH` con `priority` o `due_at` exige la habilidad `reprioritize`.
- **Razón:** son los mismos actores (asignado o admin) y el contrato no cambia; el frontend muestra ambos campos con `can.reprioritize`.

### D-35 — Las transiciones inexistentes responden 409, no 403

- **Contexto:** la policy decide quién ejecuta cada transición y el agregado decide si existe.
- **Decisión:** si la transición no está en la máquina de estados (o el caso es terminal), la policy no la bloquea y el agregado responde 409; las reglas de "creador" y "asignado" se aplican por relación con el caso, sin importar el rol.
- **Razón:** un error de dominio se informa como tal (`INVALID_STATUS_TRANSITION`, `INCIDENT_LOCKED`) y la regla de visibilidad ya impide sondear casos ajenos.

### D-36 — Visibilidad por *route binding*

- **Contexto:** R-16 aplica a detalle, edición, transición, asignación, eliminación y línea de tiempo.
- **Decisión:** el parámetro `{incident}` se resuelve con `Incident::visibleTo($user)->findOrFail()` en `AppServiceProvider`.
- **Razón:** una sola línea cubre todas las rutas presentes y futuras; ningún controlador puede olvidarla.

### D-37 — Los tests pisan variables con `<server>` y se niegan a correr fuera de `*_test`

- **Contexto:** Laravel lee primero `$_SERVER`, donde Docker deja `DB_DATABASE=incidents`; `<env force="true">` de PHPUnit solo escribe `$_ENV`, así que `RefreshDatabase` vaciaba la base principal.
- **Decisión:** `phpunit.xml` usa `<server>` y `Tests\TestCase::createApplication()` aborta si la base no termina en `_test`.
- **Razón:** un test nunca debe poder borrar datos reales.

### D-38 — `idx_incidents_created_at` es `(created_at, deleted_at)`

- **Contexto:** con `(created_at)` el conteo del listado sin filtros (`WHERE deleted_at IS NULL`) hacía un recorrido completo de la tabla (`type = ALL`), y la Fase 5 lo prohíbe. `(deleted_at, created_at)` lo arreglaba, pero el optimizador pasaba a preferirlo para los conteos filtrados y estos subían de 30 ms a 200 ms.
- **Decisión:** `deleted_at` va como sufijo del índice de fecha de creación. La sección 6 del plan sigue sin un índice propio para `deleted_at`.
- **Razón:** el conteo se vuelve un recorrido de índice cubriente (10 ms), el orden por `created_at, id` sigue usando el índice y no compite con los demás. Las mediciones están en `docs/performance.md`.

### D-39 — La búsqueda descarta términos de menos de 3 caracteres

- **Contexto:** InnoDB no indexa palabras más cortas que `innodb_ft_min_token_size` (3).
- **Decisión:** los términos de 1 o 2 caracteres se ignoran. Si no queda ninguno, la búsqueda devuelve cero resultados en lugar de todo el listado. Los operadores booleanos que escriba el usuario se eliminan al partir el texto en letras y dígitos.
- **Razón:** un término que nunca puede coincidir no debe volver obligatoria (`+`) toda la búsqueda, y devolver todo ante "de" sería engañoso.

### D-40 — Tests de búsqueda en una suite `Integration` con truncado

- **Contexto:** InnoDB solo actualiza el índice FULLTEXT al hacer commit, y `RefreshDatabase` envuelve cada test en una transacción que nunca confirma.
- **Decisión:** `tests/Integration` usa `DatabaseTruncation`; el resto de los tests de base de datos sigue con `RefreshDatabase`.
- **Razón:** solo la búsqueda necesita commits reales; truncar en todos los tests los haría más lentos sin necesidad.

### D-41 — La exportación sale por id descendente, sin `sort` ni paginación

- **Contexto:** la exportación recorre el resultado con `lazyById`, que exige ordenar por `id`. Un `sort` distinto se perdería en silencio, y el contrato no admite ignorar parámetros.
- **Decisión:** `GET /incidents/export` acepta `format`, `search` y `filter[...]`; `page`, `per_page` y `sort` responden 422. Las filas salen con `lazyByIdDesc(1000)`, de la más reciente a la más antigua. Las fechas van en hora de Colombia y las etiquetas en español.
- **Razón:** se conserva la memoria constante y el orden se parece al del listado por defecto. El frontend envía los filtros activos sin el orden.

### D-42 — Sufijo `deleted_at` en los índices de estado y prioridad

- **Contexto:** con 100.000 filas, el dashboard del admin tardaba 356 ms, por encima de los 300 ms. Los `GROUP BY status` y `GROUP BY priority` leían cada fila solo para comprobar `deleted_at`.
- **Decisión:** `idx_incidents_status_due` pasa a `(status, due_at, deleted_at)` e `idx_incidents_priority_status` a `(priority, status, due_at, deleted_at)`. No se agrega caché.
- **Razón:** con índices cubrientes, el dashboard baja a unos 100 ms, y los conteos filtrados y de vencidos también mejoran (de 32 a 11 ms y de 24 a 5 ms). Se resuelve en la base de datos, sin invalidación de caché. Mediciones en `docs/performance.md`.

### D-43 — Alcance de los eventos de tiempo real

- **Contexto:** la sección 8 define los canales, pero no qué pasa cuando el creador también es staff ni cuando alguien se autoasigna.
- **Decisión:** cada cambio va a `private-incidents.staff` y a `private-users.{creador}`, sin importar el rol del creador. `incident.assigned_to_you` solo se emite cuando el nuevo responsable es otra persona, no en la autoasignación. Un comando que falla (409, 422 o rollback) no emite nada, porque el listener implementa `ShouldHandleEventsAfterCommit`.
- **Razón:** el payload solo dispara una nueva consulta, así que un evento duplicado para un creador que es staff lo absorbe el debounce del frontend. Avisarle a alguien de algo que acaba de hacer él mismo sería ruido.

### D-44 — Autorización de canales con `withBroadcasting`

- **Contexto:** el esqueleto registra `channels.php` con el middleware `web` en `/broadcasting/auth`.
- **Decisión:** `bootstrap/app.php` usa `withBroadcasting(..., ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])`, que deja la ruta en `/api/broadcasting/auth`.
- **Razón:** el mismo token Bearer y el mismo formato de error que el resto de la API; el gateway ya enruta `/api` a PHP.

### D-45 — Piezas de la sección 9 que no se crean en el frontend

- **Contexto:** la estructura de la sección 9 lista `usePermissions.ts` y `common/ConfirmDialog`.
- **Decisión:** no se crean. Los permisos se consultan con `useAuthStore().can()` y las confirmaciones usan el plugin `Dialog` de Quasar. `useDebouncedRefetch` llega en la Fase 10 junto con el tiempo real, que es el único que lo necesita.
- **Razón:** el primero sería un alias de una línea y el segundo repetiría lo que el plugin ya hace (título, mensaje, botones, `persistent`, `prompt` con validación).

### D-46 — Detalles de comportamiento de las vistas de incidentes

- **Contexto:** el plan pide que las vistas respeten `can` y `allowed_transitions`, sin entrar en algunos casos borde.
- **Decisión:**
  - El formulario de edición envía solo los campos que cambiaron. Reenviar una prioridad sin cambios exigiría el permiso de repriorizar y respondería 403.
  - La fecha de vencimiento se edita con `datetime-local` y se interpreta en hora de Colombia (desfase fijo −05:00).
  - En el detalle, un admin cambia el responsable desde un diálogo (puede dejarlo vacío para quitarlo). Un agente solo ve "Tomar caso", cuando `can.assign` lo permite, y al crear solo puede asignarse a sí mismo.
  - El `router-view` principal usa como `key` la ruta (`path`), para que pasar de editar a crear no reutilice el formulario lleno.
- **Razón:** reflejan las reglas del backend (R-04, H-4, `IncidentPolicy::assignTo`, S-08) sin duplicarlas: el backend sigue decidiendo.

### D-47 — Dashboard y tiempo real en el frontend

- **Contexto:** la Fase 10 define qué muestra el dashboard y cómo reacciona cada vista a los eventos, pero deja abiertos algunos detalles.
- **Decisión:**
  - **Gráficas:** las barras por estado van en un solo tono, porque el eje ya nombra cada barra y llevan el valor directo. En prioridad, "Vencidos" va en rojo de estado con leyenda y "Resto" en un tono neutro. La tendencia usa los dos primeros tonos del orden categórico validado (azul y naranja) con leyenda y tooltip.
  - **Clics en la tendencia:** solo los puntos de "Creados" llevan al listado, porque `resolved_at` no es un filtro del listado.
  - **Suscripciones:** cada vista se suscribe con `useRealtime` y se desuscribe al desmontarse, sin abandonar los canales compartidos. Staff escucha `private-incidents.staff` y todos escuchan `private-users.{id}`.
  - **Conexión:** Echo se conecta y desconecta siguiendo el token de sesión, desde `main.ts`.
  - **Respaldo:** el listado y el dashboard tienen un botón "Actualizar" para cuando el WebSocket no está disponible.
  - **Bundle:** el chunk del dashboard pesa unos 560 kB (190 kB gzip) por ECharts, aun importándolo de forma modular. Como la ruta se carga en diferido, el resto de la app no lo paga.
- **Razón:** se cumplen la sección 8 y la guía de visualización (identidad nunca solo por color, un solo eje y valores en tinta de texto) con el mínimo de piezas.
