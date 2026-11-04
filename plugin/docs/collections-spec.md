# Leiho Collections — specification (phase 1)

Leiho Collections lets a site define its content model in Leiho itself, without ACF: "Strapi / Webflow collections inside WordPress". A site needs WordPress and Leiho, nothing else. ACF keeps working through its adapter; both can run side by side.

Phase 1 covers the storage engine, the schema format, migrations, revisions, the admin REST API and the adapter that plugs Collections into the visual editor and the content API. Phase 2 is the schema builder UI, phase 3 the ACF → Collections import (§9); both build on the API described here.

## 1. Concepts

| | |
|---|---|
| **Collection** | A content type with many entries (Services, Articles, Team members). |
| **Singleton** | A content type with exactly one entry (Home, Contact, Site settings). Leiho creates the entry; it can't be duplicated or deleted. |
| **Entry** | One row of `wp_posts` (post type = the collection id) plus one row of the collection's field table. |
| **Field** | A typed value of an entry, identified by a stable `key` and exposed under its `name`. |

### Hybrid storage

Every entry is a real WordPress post, so users and roles, drafts, scheduling, trash, revisions, the media library, SEO and multilingual plugins keep working. Title, slug, status, dates, author, `post_content` (when `editor` is supported), excerpt and featured image stay in `wp_posts` / `wp_postmeta` as usual.

Field values do **not** go to postmeta. Each collection owns one table, `{$wpdb->prefix}leiho_<collection id>`, with one row per entry keyed by `post_id`. Scalar fields get a typed, indexed column; nested values are stored as JSON text. This keeps queries cheap (one primary-key read per entry instead of dozens of meta rows), makes values sortable and filterable in SQL, and keeps `wp_postmeta` small.

## 2. Schema format

The schema lives in the `leiho_collections` option and can be exported to / imported from a file, `leiho.schema.json`, meant to be versioned in the front-end repository (schema as code).

```json
{
  "leiho": "collections",
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
| `id` | required | Post type name and table suffix. `^[a-z][a-z0-9_]{0,19}$`, not a WordPress reserved post type (`post`, `page`, `attachment`…), not `export`/`import`/`activity` (Leiho's own table). Never changes. |
| `kind` | `collection` (default) \| `singleton` | |
| `label`, `singular_label` | | Admin labels. `singular_label` defaults to `label`. |
| `path` | | Front-end URL pattern (`/services/{slug}`, `{id}` also works). Used unless the Leiho settings set one. |
| `supports` | | Extra post features: `editor`, `excerpt`, `thumbnail`, `page-attributes`. `title` and `revisions` are always on. |
| `icon` | | A `dashicons-*` name for the admin menu. |
| `external` | bool | The post type is registered outside Leiho (theme, plugin, ACF): Leiho doesn't register it, doesn't refuse it because it already exists, and doesn't add it to the types served by the content API (the Leiho settings decide, as before). Leiho only owns its fields. Never a singleton. Set by the ACF import. |
| `source` | `""` \| `acf` | `acf`: imported from ACF. Field and block names then keep their ACF API name (`^[A-Za-z_][A-Za-z0-9_-]{0,63}$`, camelCase and hyphens included) so the front end receives the same JSON; keys stay `^[a-z][a-z0-9_]{0,47}$`. |
| `fields` | | Fields, max 200 at the top level. |

### Field

| Property | | |
|---|---|---|
| `key` | stable id | `^[a-z][a-z0-9_]{0,47}$`, unique among siblings. Never change it: it names the column and the JSON keys. Defaults to `name` when omitted (on creation only). |
| `name` | API name | `^[a-z][a-z0-9_]{0,63}$` (snake_case; see `source` for imported collections), unique among siblings. Renaming = changing `name`, keeping `key`. |
| `label` | | Defaults to `name`. |
| `type` | | See below. |
| `required` | bool | A required field can't be emptied (checked on every write, by any client). |
| `max_length` | int | Text-like fields. Enforced on write. |
| `help` | string | Instructions shown to editors. |

| `type` | Options | Value served by the content API | Column |
|---|---|---|---|
| `text` | `multiline`, `max_length` | `string` | `VARCHAR(191)` indexed when `0 < max_length <= 191`, else `TEXT` |
| `richtext` | | rendered HTML `string` | `LONGTEXT` |
| `number` | `integer`, `min`, `max`, `step` | `number \| null` (a whole number is served as `5`, never `5.0`) | `BIGINT` if `integer`, else `DECIMAL(20,6)`; indexed |
| `boolean` | | `boolean` | `TINYINT(1)` indexed |
| `date` | | `"YYYY-MM-DD"` | `DATE` indexed |
| `datetime` | | `"YYYY-MM-DD HH:MM:SS"` (site time) | `DATETIME` indexed |
| `select` | `choices` (`[{value,label}]`), `multiple` | `string` / `string[]` | `VARCHAR(191)` indexed / JSON |
| `slug` | `max_length` | `string` (sanitized like a post slug) | `VARCHAR(191)` indexed |
| `email` | | `string` | `VARCHAR(191)` indexed |
| `url` | | `string` | `TEXT` |
| `color` | | `string` (`#rgb[a]`, `#rrggbb[aa]`, `rgb[a]()`) | `VARCHAR(32)` |
| `image`, `file` | | `LeihoMedia \| null` | `BIGINT UNSIGNED` indexed |
| `gallery` | `max` | `LeihoMedia[]` | JSON |
| `link` | | `{ url, title, target } \| null` | JSON |
| `reference` | `to` (collection ids or post types), `multiple`, `max` | `LeihoRef \| null` / `LeihoRef[]` | `BIGINT UNSIGNED` indexed / JSON |
| `list` | `fields`, `min`, `max` | `Array<{…}>` | JSON |
| `blocks` | `blocks` (`[{key,name,label,fields,min,max}]`), `min`, `max` | `Array<{ _layout: name, … }>` | JSON |
| `group` | `fields` | `{…}` | JSON |

JSON columns are `LONGTEXT` holding JSON text: no JSON SQL function is used, so MySQL 5.7, MariaDB and the SQLite drop-in behave the same. Inside JSON, objects are keyed by field **key** (renaming a nested field costs nothing) and block rows carry `"_block": "<block key>"`. Empty values are stored as `NULL`.

Nesting is limited to 8 levels; top-level `VARCHAR` columns to 60 per collection (InnoDB row size).

## 3. Tables

```sql
CREATE TABLE wp_leiho_service (
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

What the database actually contains is recorded in the `leiho_collections_state` option: for each collection, its table, and for each field key the column, its SQL type and the field definition it was created for, plus the list of archived columns. Migrations compare the requested schema with this state, never with the previous schema.

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

Archived columns are listed in `GET /leiho/v1/collections/{id}` and dropped only by an explicit `POST /leiho/v1/collections/{id}/purge` with `confirm` set to the collection id.

The plan is idempotent: the state is updated after each successful operation, so a migration interrupted by a database error is resumed by saving again. SQL uses `CREATE TABLE`, `ALTER TABLE … ADD COLUMN`, `CREATE INDEX`, `DROP INDEX` + `ALTER TABLE … DROP COLUMN` (purge only): all supported by MySQL 5.7+, MariaDB 10.3+ and the SQLite drop-in. No `ALTER … MODIFY` / `RENAME COLUMN`.

## 5. Revisions

Entry values follow WordPress revisions:

- When WordPress stores a revision of an entry (`_wp_put_post_revision`), the entry's row is snapshotted into the revision's `_leiho_fields` meta: `{ "v": 1, "fields": { "<key>": { "type": "…", "value": … } } }` (keyed by field key, so a snapshot survives renames and retypes).
- `wp_save_post_revision_post_has_changed` also compares the row with the latest snapshot, so a field-only change creates a revision. Saving fields through Leiho asks WordPress for a revision after writing.
- `wp_restore_post_revision` writes the snapshot back (converting values whose field type changed since, ignoring fields no longer in the schema), then stores a new revision of the restored state.
- Entries deleted permanently lose their row; trashed entries keep it.

## 6. API surface

### PHP

```php
Leiho_Collections::schema();                         // normalized schema
Leiho_Collections::get( 'service' );                 // one collection or null
Leiho_Collections::save_schema( $schema, $dry_run ); // validate + migrate + store → report | WP_Error
Leiho_Collections::put_collection( $collection, $dry_run );
Leiho_Collections::delete_collection( 'service' );
Leiho_Collections::export();                         // array, the leiho.schema.json content
Leiho_Collections::import( $json_or_array, $dry_run );
Leiho_Collections::entry( 'home' );                  // singleton post id
Leiho_Collections::save_entry( $post_id, array( 'price' => 120, 'sections.0.image' => 12 ) ); // { saved, errors }
```

A migration report: `{ dry_run, operations: [{ op, collection, key?, column?, … }], converted: { "<collection>.<key>": { converted, failed } }, warnings: [] }`.

### REST (`leiho/v1`, `manage_options`, filter `leiho_collections_capability`)

| Route | |
|---|---|
| `GET /collections` | `{ version, collections, state }` |
| `POST /collections` | Create a collection (body: collection). `?dry_run=1`. |
| `GET /collections/{id}` | Collection + table + archived columns. |
| `PUT /collections/{id}` | Replace a collection's definition, migrate. `?dry_run=1`. |
| `DELETE /collections/{id}` | Remove from the schema; data kept. |
| `POST /collections/{id}/purge` | Drop archived columns (`confirm` = id). |
| `GET /collections/export` | `leiho.schema.json` (attachment). |
| `POST /collections/import` | Replace the whole schema with a file's content. `?dry_run=1`. |

| `GET /collections/import-acf` | Content types with ACF fields and their import state; `elsewhere`: field groups attached to no content type. §9. |
| `GET /collections/import-acf/preview?post_type=` | Preview of an import. |
| `POST /collections/import-acf/run` | `{ post_type }`: starts the import, then copies one batch per call. |
| `GET /collections/import-acf/status?post_type=` | Import record. |
| `POST /collections/import-acf/rollback` | `{ post_type, confirm }` (confirm = post type): back to ACF. |

`DELETE /collections/{id}` refuses an imported collection (`409`): go back to ACF instead.

Invalid schemas answer `400` with `data.errors`: a list of `{ path, message }` (`collections.1.fields.3.key`; relative to the collection, `fields.3.key`, on the single-collection routes).

### Content and editor

Unchanged routes now include Collections: `/leiho/v1/export`, `/content`, `/types` serve every collection post type (added to `leiho_content_post_types` via the settings), and `/leiho/v1/schema/{id}` + `/leiho/v1/save/{id}` edit entries with the same dotted paths as ACF (`sections.2.items.1.title`).

## 7. Adapter

`Leiho_Native_Adapter` (id `collections`) implements `Leiho_Adapter_Interface` and `Leiho_Content_Adapter_Interface`. It translates each Collections field to the ACF-shaped field array the existing code already understands, and reuses the shared field engine (`Leiho_Field_Adapter`, extracted from the ACF adapter) for describing, reading, patching dotted paths, sanitizing and shaping public values. The visual editor, the change log, the content API and `Leiho_Types` therefore work unchanged.

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

- The classic wp-admin edit screen shows the post fields only; collection fields are edited in the Leiho editor (or its form view, for collections without a `path`) or through the APIs.
- Phase 2 (done): the schema builder in Leiho → Collections, a UI on top of the REST routes above. New fields get `key = name` (made unique among siblings) when they are first applied; the builder never changes the key of a saved field.
- Phase 3 (done): ACF → Collections import, §9.
- Per-request cache only; the export reads one row per entry (primary key lookups).
- `datetime` values are stored as entered (site time), like ACF.
- Uninstalling Leiho drops the collection tables and options (define `LEIHO_KEEP_DATA` to keep them); entries stay in `wp_posts`.

## 9. ACF import

`Leiho_ACF_Import` moves the ACF fields of one content type into a Leiho collection. Nothing is deleted from ACF (postmeta, field groups, ACF post types): the import is reversible.

### Preview

The field groups attached to the post type are the active groups whose location rules name it (`Leiho_ACF_Adapter::targets()`), in ACF's order. A group whose rules add other conditions (page template, taxonomy…) is imported too, and reported: its fields become available on every entry. Each ACF field becomes:

| ACF | Leiho | Notes |
|---|---|---|
| `text`, `textarea` | `text` (`multiline` for a textarea) | `maxlength` → `max_length` |
| `wysiwyg` | `richtext` | Rendered like ACF (`acf_the_content`), even once ACF is deactivated |
| `number`, `range` | `number` | `min`, `max`, `step` |
| `email`, `url` | same | |
| `oembed` | `url` | The URL, as ACF served it |
| `image`, `file`, `gallery` | same | Media restrictions (size, dimensions, types) dropped, reported |
| `select` / `checkbox` / `radio`, `button_group` | `select` / `select` + `multiple` / `select` | Choices kept in order; values outside them are refused (reported) |
| `true_false` | `boolean` | |
| `link` | `link` | |
| `post_object` / `relationship` | `reference` (+ `multiple`) | `to` = ACF's post types, or every editable type when ACF sets none; taxonomy filters and bidirectionality dropped |
| `date_picker` / `date_time_picker` | `date` / `datetime` | `Ymd` → `Y-m-d` |
| `time_picker` | `text` | `HH:MM:SS` |
| `color_picker` | `color` | |
| `repeater` / `group` / `flexible_content` | `list` / `group` / `blocks` | Recursively; layouts become block types with the same name |
| `clone` | its fields / `group` | A seamless clone is already replaced by the cloned fields in ACF's definitions; a clone shown as a group becomes a group (same JSON). A clone with nothing to clone is dropped |
| `tab`, `message`, `accordion` | — | No value: ignored |
| `page_link`, `taxonomy`, `user`, `password`, `google_map`, others | — | Not carried over: their values leave the content API (they stay in ACF) |

Keys are derived from the names (`heroTitle` → `herotitle`, unique among siblings); names are kept (`source: "acf"`). Conditional logic and default values are dropped and reported. The generated collection is `external: true` and validated like any schema before anything is written; a post type that can't be a collection id (`post`, `page`, hyphens, more than 20 characters) is not importable, with the reason: Leiho fields belong to a collection's own post type, there is no "Leiho fields on pages".

### Run

`start()` creates the collection (`put_collection()`) and the import record, in the `leiho_acf_import` option (post type → `{ status, origin, args, total, done, cursor, imported, failed, different, failures, diffs, … }`). Each `step()` copies the next batch of entries (all statuses but `auto-draft` and `inherit`, by increasing id from `cursor`): it resets the entry's row, reads every ACF value raw (`get_field( key, id, false )`, ACF default values included), converts it to the editor's input format (rows keyed by name, `_layout` for blocks, `Ymd` dates, vanished media and posts removed) and writes it with `Leiho_Collections::save_entry()`, i.e. the editor's validation, without a revision per entry. A refused field is recorded (`post_id`, `field`, `code`, `message`) and the rest of the entry and of the import go on. Then the value served by Leiho (`Leiho_Native_Adapter::get_content()`) is compared with the value ACF served before the copy, field by field (canonical JSON: `5` and `5.0` are equal); any difference is recorded. The first 200 failures and differences are kept; the counters are exact.

While the record is `running`, ACF still serves the type (the native adapter only serves it to the import itself). When the last batch is done the record becomes `done` and the type is served by Leiho: `Leiho_ACF_Adapter` ignores it (`retired()`), and the ACF location rules no longer match it (`acf/location/rule_match`), so the classic edit screen doesn't show the ACF groups anymore. If the collection disappears from the schema afterwards (a schema file imported without it), ACF serves the type again.

### Post type registration

`origin` is `acf` (declared in ACF's Post Types screen), `external` (theme, plugin, mu-plugin) or `builtin`. For `acf`, the arguments ACF registers the type with (`ACF_Post_Type::get_post_type_args()`) are stored in the record, and `Leiho_ACF_Import::register_post_types()` (`init`, priority 6, after ACF) registers the type with them when nothing else did, i.e. once ACF is deactivated. Taxonomies declared in ACF are not taken over. For `external`, the theme or plugin keeps registering it.

### Rollback

`rollback()` (ACF must be active) removes the collection from the schema, drops its table and its state (`Leiho_Collections_Store::drop()`) and deletes the record: ACF serves the original values again. Values edited in Leiho since the import are not written back to ACF; they are lost with the table.

### Front-end compatibility

For the imported fields, `/export`, `/content` and `/types` give the same JSON as before: same names and order, media objects, references (unpublished targets omitted), repeater rows, blocks with `_layout` first, rendered rich text, select / checkbox values as strings, `Y-m-d` dates, numbers (whole numbers as integers), booleans, `null` / `""` / `[]` for empty values. Known differences, all reported by the preview or the import: fields that aren't carried over disappear; values the editor's validation refuses (unknown choice, text over `max_length`, missing required sub-field…) are left empty; values it rewrites (tags or line breaks in a single-line text, unsafe HTML in rich text) are listed as differences; in `/types`, fields of groups with extra location rules are no longer optional; numbers with more than 6 decimals are rounded by `DECIMAL(20,6)`. The editor shows one group (the collection) instead of one per ACF group; dotted paths (`sections.2.cartes.1.titre`) are unchanged.
