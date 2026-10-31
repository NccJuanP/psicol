# Decisiones técnicas

Las decisiones de partida son los supuestos S-01 a S-10, los hotspots H-1 a H-6 y las decisiones D-01 a D-24 de [`PLAN.md`](PLAN.md) (secciones 1, 2 y 5). Las decisiones nuevas tomadas durante la implementación se agregan aquí con el siguiente ID libre.

Formato: **ID — título**, con contexto, decisión y razón.

## D-25 — Este archivo reemplaza al documento "Decisiones técnicas" exportado

- **Contexto:** la Fase 0 pide copiar aquí el documento "Decisiones técnicas", que no se entregó junto al plan.
- **Decisión:** se referencian las decisiones del plan en vez de duplicarlas; aquí solo se registran las nuevas.
- **Razón:** una sola fuente de verdad; si el documento aparece, se pega debajo sin cambiar IDs.

## D-26 — El backend no lleva frontend propio

- **Contexto:** el esqueleto de Laravel trae Vite, Tailwind y una vista `welcome`, y `install:broadcasting` genera `resources/js/echo.js`.
- **Decisión:** se eliminan `package.json`, `vite.config.js`, `resources/js`, `resources/css` y la vista `welcome`; `routes/web.php` queda vacío.
- **Razón:** el backend es solo API (D-20); la SPA y Echo viven en `frontend/`. Evita un segundo `npm install` y código muerto.

## D-27 — Composer resuelve dependencias para PHP 8.4

- **Contexto:** la imagen `composer:2` trae PHP 8.5, pero el runtime es PHP 8.4 (D-02).
- **Decisión:** `config.platform.php = 8.4.99` en `backend/composer.json`.
- **Razón:** el `composer.lock` nunca exige una versión de PHP que el contenedor `app` no tiene.

## D-28 — Configuración de MySQL por flags y script de init en shell

- **Contexto:** la Fase 2 pide `docker/mysql/conf.d/my.cnf` y `docker/mysql/init/01-test-db.sql`.
- **Decisión:** el cotejamiento va como flags en `command:` del servicio `mysql`, y la BD de tests la crea `01-test-db.sh`.
- **Razón:** MySQL ignora los `.cnf` montados con permisos de escritura global, que es como Docker Desktop en Windows monta los archivos. Un `.sql` no puede leer `MYSQL_USER`, así que el `GRANT` quedaría fijo a un nombre de usuario.

## D-29 — Sin `compose.dev.yml` por ahora

- **Contexto:** la Fase 2 lo marca como opcional.
- **Decisión:** no se crea; el flujo de trabajo es `docker compose up -d --build`.
- **Razón:** menos superficie que mantener; se agrega si la recarga en caliente hace falta en las fases de frontend.

## D-30 — `AssignRequestId` es middleware global

- **Contexto:** la Fase 3 lo ubica en el grupo `api`.
- **Decisión:** se registra como middleware global; `ForceJsonResponse` sí queda en el grupo `api`.
- **Razón:** las rutas inexistentes (404) no pasan por el middleware de grupo y su error quedaría sin `request_id`.

## D-31 — Tabla `users` sin `email_verified_at` ni `remember_token`

- **Contexto:** el esqueleto de Laravel trae esas columnas; la sección 6 del plan no las incluye.
- **Decisión:** se quitan y se agrega `is_active`.
- **Razón:** no hay verificación de correo ni sesiones "recordarme" en una API con tokens Bearer.

## D-32 — Sin DTOs en `Application/Incidents/Data`

- **Contexto:** la sección 9 lista DTOs readonly construidos desde los FormRequest.
- **Decisión:** las Actions reciben el arreglo de `$request->validated()` y convierten a enums y fechas en un solo lugar.
- **Razón:** el FormRequest ya fija la forma de la entrada; un DTO por endpoint repetiría cada campo sin proteger ninguna regla adicional.

## D-33 — No se quita el responsable de un caso en progreso o en espera

- **Contexto:** R-08 exige responsable para pasar a `in_progress`, pero el plan no dice qué pasa al desasignar después.
- **Decisión:** `assignTo(null)` en `in_progress` u `on_hold` responde 409 `ASSIGNEE_REQUIRED`; reasignar a otro agente sí se permite.
- **Razón:** "nada se atiende sin dueño" vale durante toda la atención, no solo al iniciarla.

## D-34 — La fecha de vencimiento la cambian los mismos que repriorizan

- **Contexto:** H-4 dice que `due_at` lo editan el asignado o el admin; el contrato solo expone `can.reprioritize`.
- **Decisión:** un `PATCH` con `priority` o `due_at` exige la habilidad `reprioritize`.
- **Razón:** son los mismos actores (asignado o admin) y el contrato no cambia; el frontend muestra ambos campos con `can.reprioritize`.

## D-35 — Las transiciones inexistentes responden 409, no 403

- **Contexto:** la policy decide quién ejecuta cada transición y el agregado decide si existe.
- **Decisión:** si la transición no está en la máquina de estados (o el caso es terminal), la policy no la bloquea y el agregado responde 409; las reglas de "creador" y "asignado" se aplican por relación con el caso, sin importar el rol.
- **Razón:** un error de dominio se informa como tal (`INVALID_STATUS_TRANSITION`, `INCIDENT_LOCKED`) y la regla de visibilidad ya impide sondear casos ajenos.

## D-36 — Visibilidad por *route binding*

- **Contexto:** R-16 aplica a detalle, edición, transición, asignación, eliminación y línea de tiempo.
- **Decisión:** el parámetro `{incident}` se resuelve con `Incident::visibleTo($user)->findOrFail()` en `AppServiceProvider`.
- **Razón:** una sola línea cubre todas las rutas presentes y futuras; ningún controlador puede olvidarla.

## D-37 — Los tests pisan variables con `<server>` y se niegan a correr fuera de `*_test`

- **Contexto:** Laravel lee primero `$_SERVER`, donde Docker deja `DB_DATABASE=incidents`; `<env force="true">` de PHPUnit solo escribe `$_ENV`, así que `RefreshDatabase` vaciaba la base principal.
- **Decisión:** `phpunit.xml` usa `<server>` y `Tests\TestCase::createApplication()` aborta si la base no termina en `_test`.
- **Razón:** un test nunca debe poder borrar datos reales.

## D-38 — `idx_incidents_created_at` es `(created_at, deleted_at)`

- **Contexto:** con `(created_at)` el conteo del listado sin filtros (`WHERE deleted_at IS NULL`) hacía un recorrido completo de la tabla (`type = ALL`), y la Fase 5 lo prohíbe. `(deleted_at, created_at)` lo arreglaba, pero el optimizador pasaba a preferirlo para los conteos filtrados y estos subían de 30 ms a 200 ms.
- **Decisión:** `deleted_at` va como sufijo del índice de fecha de creación. La sección 6 del plan sigue sin un índice propio para `deleted_at`.
- **Razón:** el conteo se vuelve un recorrido de índice cubriente (10 ms), el orden por `created_at, id` sigue usando el índice y no compite con los demás. Las mediciones están en `docs/performance.md`.

## D-39 — La búsqueda descarta términos de menos de 3 caracteres

- **Contexto:** InnoDB no indexa palabras más cortas que `innodb_ft_min_token_size` (3).
- **Decisión:** los términos de 1 o 2 caracteres se ignoran. Si no queda ninguno, la búsqueda devuelve cero resultados en lugar de todo el listado. Los operadores booleanos que escriba el usuario se eliminan al partir el texto en letras y dígitos.
- **Razón:** un término que nunca puede coincidir no debe volver obligatoria (`+`) toda la búsqueda, y devolver todo ante "de" sería engañoso.

## D-40 — Tests de búsqueda en una suite `Integration` con truncado

- **Contexto:** InnoDB solo actualiza el índice FULLTEXT al hacer commit, y `RefreshDatabase` envuelve cada test en una transacción que nunca confirma.
- **Decisión:** `tests/Integration` usa `DatabaseTruncation`; el resto de los tests de base de datos sigue con `RefreshDatabase`.
- **Razón:** solo la búsqueda necesita commits reales; truncar en todos los tests los haría más lentos sin necesidad.

## D-41 — La exportación sale por id descendente, sin `sort` ni paginación

- **Contexto:** la exportación recorre el resultado con `lazyById`, que exige ordenar por `id`. Un `sort` distinto se perdería en silencio, y el contrato no admite ignorar parámetros.
- **Decisión:** `GET /incidents/export` acepta `format`, `search` y `filter[...]`; `page`, `per_page` y `sort` responden 422. Las filas salen con `lazyByIdDesc(1000)`, de la más reciente a la más antigua. Las fechas van en hora de Colombia y las etiquetas en español.
- **Razón:** se conserva la memoria constante y el orden se parece al del listado por defecto. El frontend envía los filtros activos sin el orden.

## D-42 — Sufijo `deleted_at` en los índices de estado y prioridad

- **Contexto:** con 100.000 filas, el dashboard del admin tardaba 356 ms, por encima de los 300 ms. Los `GROUP BY status` y `GROUP BY priority` leían cada fila solo para comprobar `deleted_at`.
- **Decisión:** `idx_incidents_status_due` pasa a `(status, due_at, deleted_at)` e `idx_incidents_priority_status` a `(priority, status, due_at, deleted_at)`. No se agrega caché.
- **Razón:** con índices cubrientes, el dashboard baja a unos 100 ms, y los conteos filtrados y de vencidos también mejoran (de 32 a 11 ms y de 24 a 5 ms). Se resuelve en la base de datos, sin invalidación de caché. Mediciones en `docs/performance.md`.
