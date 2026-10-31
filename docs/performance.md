# Rendimiento

Mediciones de la Fase 5 con **100.000 incidentes** (5.000 del seeder por defecto más `php artisan incidents:seed-bulk --count=95000`, que tardó 11,4 s). Distribución sembrada: 59.989 activos, 11.926 de ellos vencidos (19,9 %).

Entorno: Docker Desktop en Windows 11, MySQL 8.4 y PHP 8.4-FPM con opcache, sin caché de aplicación. Son tiempos locales e indicativos, no un benchmark.

## Resumen

| Consulta | `EXPLAIN` (página / conteo) | `paginate()` en PHP | HTTP de punta a punta |
|---|---|---|---|
| Listado sin filtros | `index` / `index` (cubriente) | 10,7 ms | 47 ms |
| Estado + prioridad | `index` / `range` | 31,9 ms | 68 ms |
| Búsqueda por texto | `fulltext` / `fulltext` | 36,3 ms | 68 ms |
| `assigned_to=me` | `index` / `ref` | 24,6 ms | 58 ms |
| Conteo de vencidos | `range` | 24,3 ms | 91 ms (listado `filter[overdue]=true`) |

✅ Ningún plan usa `type = ALL`, y todo el listado responde por debajo de 300 ms.

- **`paginate()` en PHP:** es el conteo más la página más el eager loading, medido dentro del contenedor `app` con la mediana de 5 corridas.
- **HTTP de punta a punta:** es `curl` a través del gateway con token Bearer, también mediana de 5. Incluye arranque de Laravel, autenticación y serialización.

## Ajuste de índices durante la medición (D-38)

Con el índice del plan, `idx_incidents_created_at (created_at)`, el conteo del listado sin filtros (`WHERE deleted_at IS NULL`) daba `type = ALL`, porque ningún índice contiene `deleted_at`. Se probaron dos alternativas:

| Índice | Conteo sin filtros | Efecto secundario |
|---|---|---|
| `(created_at)` (plan) | `ALL`, 98.393 filas | — |
| `(deleted_at, created_at)` | `ref` cubriente, 12,6 ms | ❌ El optimizador lo prefiere también para los conteos filtrados (estimación de 49.400 filas para `IS NULL`): estado + prioridad sube a 200 ms y vencidos a 176 ms. |
| `(created_at, deleted_at)` | `index` cubriente, 10,7 ms | ✅ Ninguno. `ORDER BY created_at DESC, id DESC` sigue resolviéndose con el índice, porque MySQL omite la parte fijada por igualdad (`deleted_at IS NULL`). Como `deleted_at` no es prefijo, no compite con los demás índices. |

Quedó `(created_at, deleted_at)` con el mismo nombre.

## Cómo reproducir

```bash
docker compose exec app php artisan incidents:seed-bulk --count=95000
docker compose exec mysql mysql -uincidents -psecret incidents -e "ANALYZE TABLE incidents"
```

Después se corre `EXPLAIN` sobre el SQL que produce `ListIncidentsQuery::builder()`; el detalle completo está abajo.

## Detalle por consulta

### Listado sin filtros

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 15 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 98556 | Using where; Using index |

Resultados: 100,000. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **10.7 ms**.

### Estado (open,in_progress) + prioridad (high,critical)

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `status` in ('open', 'in_progress') and `priority` in (3, 4) and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 97 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | range | idx_incidents_priority_status | 15095 | Using index condition; Using where |

Resultados: 15,095. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **31.9 ms**.

### Búsqueda por texto "impresora"

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where match (`title`, `description`) against ('+impresora*' in boolean mode) and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | fulltext | ft_incidents_search | 1 | Using where; Ft_hints: no_ranking; Using filesort |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | fulltext | ft_incidents_search | 1 | Using where; Ft_hints: no_ranking |

Resultados: 5,325. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **36.3 ms**.

### assigned_to=me (agente)

```sql
select `id`, `type`, `title`, `priority`, `status`, `created_by`, `assigned_to`, `due_at`, `created_at`, `updated_at` from `incidents` where `assigned_to` = 2 and `incidents`.`deleted_at` is null order by `created_at` desc, `id` desc limit 15
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | index | idx_incidents_created_at | 51 | Using where; Backward index scan |

Conteo para la paginación:

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | ref | idx_incidents_assignee_status | 28818 | Using where |

Resultados: 14,200. Tiempo de `paginate()` (conteo + página + eager loading), mediana de 5: **24.6 ms**.

### Conteo de vencidos

```sql
select count(*) as aggregate from `incidents` where `status` in ('open', 'in_progress', 'on_hold') and `due_at` < '2026-10-31 23:35:08' and `incidents`.`deleted_at` is null
```

| tabla | type | key | rows | Extra |
|---|---|---|---|---|
| incidents | range | idx_incidents_status_due | 20679 | Using index condition; Using where |

Resultado: 11,926. Tiempo, mediana de 5: **24.3 ms**.

## Notas

- **Búsqueda:** el `Using filesort` está acotado a las filas que devuelve FULLTEXT (5.325 aquí), no a la tabla.
- **Términos amplios:** un término muy amplio, como uno que aparezca en todas las filas, ordenaría más filas. Si ocurre, el siguiente paso es ordenar por relevancia o limitar la búsqueda a `title`.
- **`assigned_to=me`:** la página recorre `idx_incidents_created_at` hacia atrás y filtra por responsable. Le basta leer unas 51 entradas para llenar 15 resultados, porque cada agente tiene alrededor del 14 % de los casos. El conteo usa `idx_incidents_assignee_status`.
- **Dashboard:** las consultas del dashboard se miden en la Fase 6 y se agregan a este documento.
