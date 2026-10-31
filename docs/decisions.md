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
