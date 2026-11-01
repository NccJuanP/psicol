# Psicol · Gestión de incidentes

Aplicación para registrar, asignar y seguir incidentes y solicitudes. Tiene una API en **Laravel 13** (PHP 8.4), una SPA en **Vue 3 + Quasar 2** con TypeScript, base de datos **MySQL 8.4** y tiempo real con **Laravel Reverb**. Todo corre en Docker y arranca con un solo comando.

## Requisitos

- Docker Engine o Docker Desktop con **Compose v2** (`docker compose version`).
- El puerto **8080** libre (se puede cambiar; ver [Solución de problemas](#solución-de-problemas)).
- Unos 4 GB de RAM disponibles para Docker.

No hace falta instalar PHP, Composer ni Node: todo se construye dentro de los contenedores.

## Instalación y arranque

```bash
git clone <url-del-repositorio> psicol
cd psicol
docker compose up -d
```

La primera vez se construyen las imágenes (unos minutos). El contenedor `app` espera a MySQL, genera la `APP_KEY`, aplica las migraciones y siembra datos de prueba si la base está vacía. Para seguir el arranque:

```bash
docker compose ps        # los 5 servicios deben quedar "healthy"
docker compose logs -f app
```

No hace falta crear un `.env`, porque todas las variables tienen valor por defecto. Para cambiar alguna, copia `.env.example` a `.env`.

## URL de acceso

| Qué | URL |
|---|---|
| Aplicación | http://localhost:8080 |
| API | http://localhost:8080/api/v1 |
| Salud | http://localhost:8080/api/v1/health |
| WebSocket (Reverb) | `ws://localhost:8080/app/…` |

Un gateway Nginx publica todo en el mismo origen, así que no hay CORS ni puertos extra.

## Credenciales de prueba

Las tres cuentas usan la contraseña `Password123!`.

| Rol | Correo | Qué puede hacer |
|---|---|---|
| Administrador | `admin@psicol.test` | Todo: asignar a cualquiera, cancelar casos en curso, eliminar, exportar. |
| Agente | `agente@psicol.test` | Ve todos los casos, toma casos sin responsable, atiende y resuelve los suyos, exporta. |
| Solicitante | `solicitante@psicol.test` | Registra casos y sigue solo los suyos; cierra o reabre cuando están resueltos. |

El seeder crea además 4 agentes y 9 solicitantes con contraseña `password`.

## Variables de entorno

Todas son opcionales. Se leen desde un `.env` en la raíz, si existe, y se pasan a los contenedores desde `docker-compose.yml`.

| Variable | Por defecto | Para qué |
|---|---|---|
| `GATEWAY_PORT` | `8080` | Puerto publicado en el host. |
| `APP_ENV` | `local` | Entorno de Laravel. En `production` se ocultan las trazas de error y se desactiva el modo estricto de Eloquent. |
| `APP_DEBUG` | `true` | Detalle de errores 500 en la respuesta (solo para desarrollo). |
| `APP_KEY` | *(vacía)* | Si queda vacía, se genera una y se guarda en el volumen `app-storage`. |
| `APP_URL` | `http://localhost:8080` | URL pública. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `incidents` / `incidents` / `secret` | Base de datos y usuario de la aplicación. |
| `DB_ROOT_PASSWORD` | `root` | Contraseña de root de MySQL. |
| `RUN_MIGRATIONS` | `true` | Si `app` migra y siembra al arrancar. |
| `SEED_INCIDENTS` | `5000` | Cantidad de incidentes del seeder inicial. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | `psicol` / `psicol-key` / `psicol-secret` | Credenciales de Reverb. La key también se compila en el frontend. |

## Migraciones y seeders

Son **automáticos**: en cada arranque, `app` ejecuta `migrate --force` y `app:seed-if-empty`. Este último solo siembra si no hay usuarios, así que `docker compose down` seguido de `up -d` no duplica nada. El seed corre en una transacción.

Comandos manuales:

```bash
docker compose exec app php artisan migrate                              # migraciones pendientes
docker compose exec app php artisan db:seed                              # roles, usuarios e incidentes
docker compose exec app php artisan incidents:seed-bulk --count=100000   # carga masiva para pruebas de rendimiento
docker compose down -v && docker compose up -d                           # empezar desde cero (borra los datos)
```

El seeder inserta en lotes de 1.000 filas, con datos coherentes con las reglas de negocio:

- Distribución por estado: 25 % abiertos, 25 % en progreso, 10 % en espera, 15 % resueltos, 20 % cerrados y 5 % cancelados.
- Alrededor del 20 % de los activos queda vencido.
- Los títulos y descripciones están en español, para que la búsqueda se pueda demostrar.

Con 100.000 filas, la carga tarda unos 11 s.

## Tests

**Backend:** Pest contra una base MySQL real (`incidents_test`), separada de la de la aplicación.

```bash
docker compose exec app php artisan test
```

Son 118 tests de unidad, integración, búsqueda FULLTEXT y tiempo real. La suite se niega a correr contra una base que no termine en `_test`.

**Calidad del código:**

```bash
# Backend
docker compose exec app ./vendor/bin/pint --test

# Frontend (en un contenedor efímero de Node)
docker run --rm -v "$PWD/frontend:/app" -w /app node:24-alpine sh -c "npm ci && npm run lint && npm run format:check && npm run type-check"
```

## Arquitectura

```text
                 ┌──────────────── localhost:8080 ───────────────┐
navegador ──────▶│ gateway (nginx)                               │
                 │   /            → frontend (SPA compilada)     │
                 │   /api/*       → app (php-fpm, Laravel)       │
                 │   /app, /apps  → reverb (WebSocket)           │
                 └───────────────────────────────────────────────┘
          app ──▶ mysql          app ──(después del commit)──▶ reverb
```

- **Backend (`backend/app`):** monolito modular con DDD Lite.
  - **`Domain`:** contiene el agregado `Incident`, que protege las invariantes (máquina de estados, responsable obligatorio, casos cerrados de solo lectura), los enums, los eventos y la auditoría.
  - **`Application`:** contiene las Actions de escritura y las Query de lectura (listado, dashboard, exportación).
  - **`Http`:** controladores delgados, FormRequests, Resources y la policy.
- **Frontend (`frontend/src`):**
  - **Cliente HTTP:** uno solo, con interceptores (`api/http.ts`).
  - **Estado:** stores de Pinia para la sesión y los catálogos.
  - **Filtros:** el listado los guarda en la URL.
  - **Permisos:** las vistas solo muestran lo que el backend permite (`can`, `allowed_transitions`).
- **Tiempo real:** cada cambio emite un evento con payload mínimo a canales privados. Las vistas vuelven a consultar con debounce, y si el WebSocket cae la aplicación sigue funcionando por HTTP.

Documentación en [`docs/`](docs/):

| Documento | Contenido |
|---|---|
| [`PLAN.md`](docs/PLAN.md) | Plan completo: dominio, reglas, decisiones de arquitectura y fases. |
| [`api-contract.md`](docs/api-contract.md) | Contrato de la API y del tiempo real (endpoints, errores, tipos TypeScript). |
| [`decisions.md`](docs/decisions.md) | Decisiones de diseño y de implementación, con su porqué (la próxima usa D-64). |
| [`performance.md`](docs/performance.md) | `EXPLAIN` y tiempos con 100.000 incidentes. |

## Supuestos y decisiones

Los supuestos de interpretación del PRD están en la sección 1 de [`PLAN.md`](docs/PLAN.md). Los principales:

- **Un solo agregado:** incidente y solicitud son el mismo agregado, con un campo `type`.
- **Vencimiento:** "vencido" es una condición derivada (estado activo y `due_at` en el pasado), no un estado guardado.
- **SLA por defecto:** si no se envía `due_at`, se calcula según la prioridad (crítica 1 día, alta 3, media 5, baja 10).
- **Roles:** hay tres, `admin`, `agent` y `requester`. El solicitante solo ve sus casos, también en el dashboard.
- **Eliminación:** es lógica (soft delete) y solo la hace un admin.
- **Zonas horarias:** la base y la API trabajan en UTC; la interfaz y los filtros por día usan la hora de Colombia.

Las decisiones tomadas al implementar están en [`decisions.md`](docs/decisions.md). Entre ellas:

- Los índices se ajustaron después de medir (D-38, D-42).
- La exportación sale por id descendente (D-41).
- Los tests están aislados de la base principal (D-37).

## Cumplimiento del PRD

| Requisito | Cómo se cumple |
|---|---|
| Login, logout y protección de rutas | Tokens Bearer de Sanctum (8 h, revocables), `auth:sanctum` y guards de Vue Router. |
| CRUD de incidentes | Tabla `incidents`, Actions, Resources, formulario y detalle; transiciones y asignación por endpoints propios. |
| Paginación, orden, búsqueda y filtros | Paginación y orden en el servidor, FULLTEXT o id exacto, filtros de estado, prioridad, responsable, tipo, vencidos y rango de fechas, todos en la URL. |
| Dashboard | Total, activos, vencidos, sin asignar, por estado, por prioridad con vencidos y tendencia de 30 días. Cada cifra abre el listado equivalente. |
| Eager loading y cero N+1 | `with()`, `Model::shouldBeStrict()` y un test que cuenta consultas. |
| Índices y optimización | Índices por consulta, con `EXPLAIN` sin `type = ALL` y listado y dashboard por debajo de 300 ms con 100.000 filas. |
| Manejo de errores y validaciones | Formato único de error con `request_id`, mensajes en español, 422 por campo y reglas espejo en el frontend. |
| Docker | 5 servicios con healthchecks; `docker compose up -d` deja todo listo. |
| Tiempo real | Reverb + Echo en el listado, el detalle, el dashboard y el aviso al asignado. |
| Tests | Pest contra MySQL real: 118 tests. |
| Roles y permisos | spatie/laravel-permission más `IncidentPolicy` (permiso, propiedad y estado del caso). |
| Exportación | CSV y XLSX en streaming, con los mismos filtros y visibilidad del listado. |
| Auditoría y logs | Línea de tiempo por incidente (`incident_activities`) y logs con `request_id`. |
| Seeders | 5.000 incidentes, 16 usuarios, 6 estados y 4 prioridades; carga masiva opcional. |
| Responsive | Tarjetas en móvil, menú superpuesto y dashboard en 2×2. Revisado a 360, 768 y 1280 px. |

## Solución de problemas

**El puerto 8080 está ocupado.** Arranca con otro puerto:

```bash
GATEWAY_PORT=8081 docker compose up -d
```

En PowerShell, crea un `.env` con `GATEWAY_PORT=8081` y vuelve a ejecutar `docker compose up -d`.

**En Windows, `app` se reinicia con `exec /usr/local/bin/entrypoint: no such file or directory`.** El script quedó con finales de línea CRLF. El repositorio fija `eol=lf` en `.gitattributes`, pero un clon hecho con `core.autocrlf=true` antes de ese archivo puede traerlos mal. Para corregirlo:

```bash
git config core.autocrlf false
git rm --cached -r . && git reset --hard
docker compose up -d --build
```

**La primera carga tarda o `gateway` no queda healthy.** `app` espera a MySQL y luego migra y siembra antes de declararse listo. Revisa el avance con `docker compose logs -f app`.

**La cabecera dice "Sin conexión en vivo".** El WebSocket no conecta (por ejemplo, porque `reverb` está caído). La aplicación sigue funcionando y los botones "Actualizar" recargan los datos. Revisa con `docker compose logs reverb`.

**Quiero volver a los datos iniciales.** `docker compose down -v && docker compose up -d` borra los volúmenes y vuelve a sembrar.
