# Okno Collections — specification (phase 1)

Okno Collections lets a site define its content model in Okno itself, without ACF: "Strapi / Webflow collections inside WordPress". A site needs WordPress and Okno, nothing else. ACF keeps working through its adapter; both can run side by side.

Phase 1 (this document) covers the storage engine, the schema format, migrations, revisions, the admin REST API and the adapter that plugs Collections into the visual editor and the content API. The schema builder UI and the ACF → Collections import come later and build on the API described here.

## 1. Concepts

| | |
|---|---|
| **Collection** | A content type with many entries (Services, Articles, Team members). |
| **Singleton** | A content type with exactly one entry (Home, Contact, Site settings). Okno creates the entry; it can't be duplicated or deleted. |
| **Entry** | One row of `wp_posts` (post type = the collection id) plus one row of the collection's field table. |
| **Field** | A typed value of an entry, identified by a stable `key` and exposed under its `name`. |

### Hybrid storage

Every entry is a real WordPress post, so users and roles, drafts, scheduling, trash, revisions, the media library, SEO and multilingual plugins keep working. Title, slug, status, dates, author, `post_content` (when `editor` is supported), excerpt and featured image stay in `wp_posts` / `wp_postmeta` as usual.

Field values do **not** go to postmeta. Each collection owns one table, `{$wpdb->prefix}okno_<collection id>`, with one row per entry keyed by `post_id`. Scalar fields get a typed, indexed column; nested values are stored as JSON text. This keeps queries cheap (one primary-key read per entry instead of dozens of meta rows), makes values sortable and filterable in SQL, and keeps `wp_postmeta` small.

## 2. Schema format

The schema lives in the `okno_collections` option and can be exported to / imported from a file, `okno.schema.json`, meant to be versioned in the front-end repository (schema as code).

```json
{
  "okno": "collections",
  "version": 1,
  "collections": [
    {
      "id": "home",
      "kind": "singleton",
      "label": "Home",
      "path": "/",
      "fields": [
        { "key": "hero_title", "name": "hero_title", "label": "Hero title", "type": "text", "required": true, "max_length": 80 },
        { "key": "sections", "name": "sections", "label": "Sections", "type": "blocks", "blocks": [
          { "key": "hero", "name": "hero", "label": "Hero", "fields": [
            { "key": "image", "name": "image", "label": "Image", "type": "image" }
          ] },
          { "key": "cards", "name": "cards", "label": "Cards", "max": 3, "fields": [
            { "key": "items", "name": "items", "label": "Cards", "type": "list", "max": 6, "fields": [
              { "key": "title", "name": "title", "label": "Title", "type": "text" },
              { "key": "service", "name": "service", "label": "Service", "type": "reference", "to": ["service"] }
            ] }
          ] }
        ] }
      ]
    },
    {
      "id": "service",
      "label": "Services",
      "singular_label": "Service",
      "path": "/services/{slug}",
      "supports": ["thumbnail", "excerpt"],
      "fields": [
        { "key": "price", "name": "price", "label": "Price", "type": "number", "min": 0 },
        { "key": "featured", "name": "featured", "label": "Featured", "type": "boolean" },
        { "key": "tone", "name": "tone", "label": "Tone", "type": "select", "choices": [
          { "value": "light", "label": "Light" }, { "value": "dark", "label": "Dark" }
        ] },
        { "key": "related", "name": "related", "label": "Related", "type": "reference", "to": ["service"], "multiple": true, "max": 3 }
      ]
    }
  ]
}
```

### Collection

| Property | | |
|---|---|---|
| `id` | required | Post type name and table suffix. `^[a-z][a-z0-9_]{0,19}$`, not a WordPress reserved post type (`post`, `page`, `attachment`…), not `export`/`import`/`activity` (Okno's own table). Never changes. |
| `kind` | `collection` (default) \| `singleton` | |
| `label`, `singular_label` | | Admin labels. `singular_label` defaults to `label`. |
| `path` | | Front-end URL pattern (`/services/{slug}`, `{id}` also works). Used unless the Okno settings set one. |
| `supports` | | Extra post features: `editor`, `excerpt`, `thumbnail`, `page-attributes`. `title` and `revisions` are always on. |
| `icon` | | A `dashicons-*` name for the admin menu. |
| `fields` | | Fields, max 200 at the top level. |

### Field

| Property | | |
|---|---|---|
| `key` | stable id | `^[a-z][a-z0-9_]{0,47}$`, unique among siblings. Never change it: it names the column and the JSON keys. Defaults to `name` when omitted (on creation only). |
| `name` | API name | `^[a-z][a-z0-9_]{0,63}$` (snake_case), unique among siblings. Renaming = changing `name`, keeping `key`. |
| `label` | | Defaults to `name`. |
| `type` | | See below. |
| `required` | bool | A required field can't be emptied (checked on every write, by any client). |
| `max_length` | int | Text-like fields. Enforced on write. |
| `help` | string | Instructions shown to editors. |

| `type` | Options | Value served by the content API | Column |
|---|---|---|---|
| `text` | `multiline`, `max_length` | `string` | `VARCHAR(191)` indexed when `0 < max_length <= 191`, else `TEXT` |
| `richtext` | | rendered HTML `string` | `LONGTEXT` |
| `number` | `integer`, `min`, `max`, `step` | `number \| null` | `BIGINT` if `integer`, else `DECIMAL(20,6)`; indexed |
| `boolean` | | `boolean` | `TINYINT(1)` indexed |
| `date` | | `"YYYY-MM-DD"` | `DATE` indexed |
| `datetime` | | `"YYYY-MM-DD HH:MM:SS"` (site time) | `DATETIME` indexed |
| `select` | `choices` (`[{value,label}]`), `multiple` | `string` / `string[]` | `VARCHAR(191)` indexed / JSON |
| `slug` | `max_length` | `string` (sanitized like a post slug) | `VARCHAR(191)` indexed |
| `email` | | `string` | `VARCHAR(191)` indexed |
| `url` | | `string` | `TEXT` |
| `color` | | `string` (`#rgb[a]`, `#rrggbb[aa]`, `rgb[a]()`) | `VARCHAR(32)` |
| `image`, `file` | | `OknoMedia \| null` | `BIGINT UNSIGNED` indexed |
| `gallery` | `max` | `OknoMedia[]` | JSON |
| `link` | | `{ url, title, target } \| null` | JSON |
| `reference` | `to` (collection ids or post types), `multiple`, `max` | `OknoRef \| null` / `OknoRef[]` | `BIGINT UNSIGNED` indexed / JSON |
| `list` | `fields`, `min`, `max` | `Array<{…}>` | JSON |
| `blocks` | `blocks` (`[{key,name,label,fields,min,max}]`), `min`, `max` | `Array<{ _layout: name, … }>` | JSON |
| `group` | `fields` | `{…}` | JSON |

JSON columns are `LONGTEXT` holding JSON text: no JSON SQL function is used, so MySQL 5.7, MariaDB and the SQLite drop-in behave the same. Inside JSON, objects are keyed by field **key** (renaming a nested field costs nothing) and block rows carry `"_block": "<block key>"`. Empty values are stored as `NULL`.

Nesting is limited to 8 levels; top-level `VARCHAR` columns to 60 per collection (InnoDB row size).

## 3. Tables

```sql
CREATE TABLE wp_okno_service (
  post_id BIGINT(20) UNSIGNED NOT NULL,
  f_price DECIMAL(20,6) NULL DEFAULT NULL,
  f_featured TINYINT(1) NULL DEFAULT NULL,
  f_tone VARCHAR(191) NULL DEFAULT NULL,
  f_related LONGTEXT NULL,
  PRIMARY KEY (post_id),
  KEY k_f_price (f_price), KEY k_f_featured (f_featured), KEY k_f_tone (f_tone)
) {charset_collate};
```

Columns are `f_<key>` (the prefix avoids reserved words). Identifiers are built only from validated ids and keys and re-checked against `^[A-Za-z0-9_]{1,64}$` before any DDL; values always go through `$wpdb->prepare()` / `$wpdb->insert()` / `$wpdb->update()`.

What the database actually contains is recorded in the `okno_collections_state` option: for each collection, its table, and for each field key the column, its SQL type and the field definition it was created for, plus the list of archived columns. Migrations compare the requested schema with this state, never with the previous schema.

## 4. Migrations

Saving a schema (REST, import or PHP) validates it, computes a plan, applies it, then stores the schema. `dry_run` returns the plan without touching anything.

| Change | Operation |
|---|---|
| New collection | `CREATE TABLE IF NOT EXISTS`; a singleton gets its entry. |
| New field | `ADD COLUMN` (+ index). If an archived column with the same key and SQL type exists, it is restored instead: removing a field by mistake and adding it back loses nothing. |
| Renamed field (same `key`, new `name`) | Nothing in the database. |
| Changed options with the same column type (label, choices, `max`…) | Nothing in the database. |
| Changed type, or a column type change (`max_length` 100 → 500, single → multiple reference…) | New column `f_<key>__<n>`, values converted row by row in PHP, old column **archived** (kept, reported). Values that can't be converted are counted in the report and stay in the archived column. |
| Removed field | Column **archived**, never dropped. |
| Removed collection | Unregistered; table, entries and state kept. Adding it back restores everything. |
| Nested change (inside list / blocks / group) | Nothing in the database: JSON is keyed by key. A removed nested field disappears from the stored JSON the next time that container is saved (revisions keep the previous values). |

Conversions: any scalar to text; numeric text to number; text to boolean (`"", "0", "false", "no"` → false); parsable text to date/datetime; a single value to a list (`id` → `[id]`) and the first element of a list to a single value; anything else is not converted.

Archived columns are listed in `GET /okno/v1/collections/{id}` and dropped only by an explicit `POST /okno/v1/collections/{id}/purge` with `confirm` set to the collection id.

The plan is idempotent: the state is updated after each successful operation, so a migration interrupted by a database error is resumed by saving again. SQL uses `CREATE TABLE`, `ALTER TABLE … ADD COLUMN`, `CREATE INDEX`, `DROP INDEX` + `ALTER TABLE … DROP COLUMN` (purge only): all supported by MySQL 5.7+, MariaDB 10.3+ and the SQLite drop-in. No `ALTER … MODIFY` / `RENAME COLUMN`.

## 5. Revisions

Entry values follow WordPress revisions:

- When WordPress stores a revision of an entry (`_wp_put_post_revision`), the entry's row is snapshotted into the revision's `_okno_fields` meta: `{ "v": 1, "fields": { "<key>": { "type": "…", "value": … } } }` (keyed by field key, so a snapshot survives renames and retypes).
- `wp_save_post_revision_post_has_changed` also compares the row with the latest snapshot, so a field-only change creates a revision. Saving fields through Okno asks WordPress for a revision after writing.
- `wp_restore_post_revision` writes the snapshot back (converting values whose field type changed since, ignoring fields no longer in the schema), then stores a new revision of the restored state.
- Entries deleted permanently lose their row; trashed entries keep it.

## 6. API surface

### PHP

```php
Okno_Collections::schema();                         // normalized schema
Okno_Collections::get( 'service' );                 // one collection or null
Okno_Collections::save_schema( $schema, $dry_run ); // validate + migrate + store → report | WP_Error
Okno_Collections::put_collection( $collection, $dry_run );
Okno_Collections::delete_collection( 'service' );
Okno_Collections::export();                         // array, the okno.schema.json content
Okno_Collections::import( $json_or_array, $dry_run );
Okno_Collections::entry( 'home' );                  // singleton post id
Okno_Collections::save_entry( $post_id, array( 'price' => 120, 'sections.0.image' => 12 ) ); // { saved, errors }
```

A migration report: `{ dry_run, operations: [{ op, collection, key?, column?, … }], converted: { "<collection>.<key>": { converted, failed } }, warnings: [] }`.

### REST (`okno/v1`, `manage_options`, filter `okno_collections_capability`)

| Route | |
|---|---|
| `GET /collections` | `{ version, collections, state }` |
| `POST /collections` | Create a collection (body: collection). `?dry_run=1`. |
| `GET /collections/{id}` | Collection + table + archived columns. |
| `PUT /collections/{id}` | Replace a collection's definition, migrate. `?dry_run=1`. |
| `DELETE /collections/{id}` | Remove from the schema; data kept. |
| `POST /collections/{id}/purge` | Drop archived columns (`confirm` = id). |
| `GET /collections/export` | `okno.schema.json` (attachment). |
| `POST /collections/import` | Replace the whole schema with a file's content. `?dry_run=1`. |

Invalid schemas answer `400` with `data.errors`: a list of `{ path, message }` (`collections.1.fields.3.key`; relative to the collection, `fields.3.key`, on the single-collection routes).

### Content and editor

Unchanged routes now include Collections: `/okno/v1/export`, `/content`, `/types` serve every collection post type (added to `okno_content_post_types` via the settings), and `/okno/v1/schema/{id}` + `/okno/v1/save/{id}` edit entries with the same dotted paths as ACF (`sections.2.items.1.title`).

## 7. Adapter

`Okno_Native_Adapter` (id `collections`) implements `Okno_Adapter_Interface` and `Okno_Content_Adapter_Interface`. It translates each Collections field to the ACF-shaped field array the existing code already understands, and reuses the shared field engine (`Okno_Field_Adapter`, extracted from the ACF adapter) for describing, reading, patching dotted paths, sanitizing and shaping public values. The visual editor, the change log, the content API and `Okno_Types` therefore work unchanged.

| Collections | Field engine type |
|---|---|
| `text` / `text` + `multiline` | `text` / `textarea` |
| `richtext` | `wysiwyg` |
| `boolean` | `true_false` |
| `date` / `datetime` | `date_picker` / `date_time_picker` (stored as `Y-m-d` / `Y-m-d H:i:s`) |
| `color` | `color_picker` |
| `slug` | `text` (sanitized with `sanitize_title`) |
| `reference` / + `multiple` | `post_object` / `relationship` (post types restricted to `to`) |
| `list` / `blocks` / `group` | `repeater` / `flexible_content` / `group` |
| others | same name |

Sanitization mirrors the ACF adapter (required, `max_length`, attachments and references must exist and be readable by the current user) and adds: references must belong to the `to` post types, numbers must respect `integer`/`min`/`max` and the column range, multiple selects only accept declared choices.

## 8. Limits and next phases

- The classic wp-admin edit screen shows the post fields only; collection fields are edited in the Okno editor or through the APIs. The schema builder (phase 2) adds an admin UI on top of the REST routes above.
- ACF → Collections import (phase 3) can build a schema from ACF field groups (types map one to one through the table in §7), call `save_schema()`, then copy values with `save_entry()` per post.
- Per-request cache only; the export reads one row per entry (primary key lookups).
- `datetime` values are stored as entered (site time), like ACF.
- Uninstalling Okno drops the collection tables and options (define `OKNO_KEEP_DATA` to keep them); entries stay in `wp_posts`.
