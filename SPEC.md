# Towns Canada — Geoapify Importer Project Specification (Updated)

## Purpose

Build a reusable Drupal module named Geoapify Importer for Towns Canada.

The module imports and synchronizes geographic place data from the Geoapify Places API into Drupal, initially targeting the Point of Interest content type.

The importer must be designed as a maintainable ingestion pipeline that can later support additional source types, including Listings.

## Environment

### Local development

- Drupal root: `/Users/ronmacquarrie/Sites/townscanada/public_html`
- Custom module: `/Users/ronmacquarrie/Sites/townscanada/public_html/modules/custom/geoapify_importer`
- Development is currently local.

### Production

- Drupal root: `/home/townscanada/public_html`
- Do not make production changes while developing this module.

### Drupal / PHP

- Drupal 11.x
- PHP 8.4+
- Must remain compatible with Drupal 12
- Module requirement: `^11 || ^12`
- There is NO `web/` directory in this Drupal installation. Configuration paths use `sites/default/...`, not `web/sites/default/....`

## Version Control

- Module is its own git repository, rooted at the module directory (not the full Drupal install).
- Public GitHub remote: `https://github.com/itwerxdotca/geoapify_importer`
- Branch: `main`
- Managed day-to-day via GitHub Desktop.
- `.gitignore` excludes OS/editor cruft; no secrets are ever stored in module files (API key lives in Drupal State API, not config or code).

## Module

- Machine name: `geoapify_importer`
- Name: `Geoapify Importer`

### Current module structure (as committed)

```
geoapify_importer/
├── config/
│   └── schema/
│       └── geoapify_importer.schema.yml
├── src/
│   ├── Form/
│   │   └── GeoapifyImporterSettingsForm.php
│   └── Service/
│       ├── GeoapifyClient.php
│       ├── SourceFileWriter.php
│       └── AddressVerifier.php
├── geoapify_importer.info.yml
├── geoapify_importer.services.yml
├── geoapify_importer.routing.yml
└── geoapify_importer.links.menu.yml
```

Note: `config/install/` from the original spec is not currently present as a tracked directory (git does not track empty directories); it can be added back if/when default config needs to ship with the module.

## Source Data Architecture

Raw source data is stored as **files**, not SQL.

Pipeline:

```
Geoapify API
	↓
Raw source files
	↓
Classification / validation / change detection
	↓
Drupal taxonomy + POI fields
	↓
Drupal database
```

- No custom SQL table is used for primary raw-data storage.
- Raw source files are not stored inside the module code directory.

### Private filesystem — RESOLVED

- Local private filesystem path: `/Users/ronmacquarrie/Sites/townscanada/private`
- Configured in `settings.php` via `$settings['file_private_path']`.
- Verified working via `\Drupal\Core\StreamWrapper\PrivateStream::basePath()`.
- Sits as a sibling to `public_html`, outside the webroot, outside the module, and (locally) outside any VCS, since no git repo wraps the Drupal install itself.

### Source-file naming/partitioning strategy — RESOLVED

```
private://geoapify_importer/
├── pois/
│   ├── {place_id}/
│   │   ├── latest.json
│   │   └── history/
│   │       └── {timestamp}.json
```

- `{place_id}` is Geoapify's own opaque place ID (long encoded string), used verbatim as the directory name — intentional, matching the spec's preferred record-identity mechanism.
- `{timestamp}` format: `gmdate('Ymd\THis\Z')`, e.g. `20260920T223039Z` (UTC, filesystem-safe, sortable as a string).
- On each write: if a `latest.json` already exists for that place, it is archived into `history/{timestamp}.json` before the new payload is written.
- Writes are atomic (write-to-temp, then rename) to avoid partial files.

**Implemented as:** `Drupal\geoapify_importer\Service\SourceFileWriter` (service ID `geoapify_importer.source_writer`).

- `write(string $place_id, array $payload): string` — writes/archives as above, returns the URI written.
- `readLatest(string $place_id): ?array` — returns the current payload, or `NULL` if none exists yet.

**Verified end-to-end with live Geoapify data** (Fort McMurray museum search): write, archive-on-rewrite, and read-back all confirmed working against real API responses.

**Known edge case, not yet addressed:** two writes to the same place within the same second would collide in `history/` (current behavior: throws, via `EXISTS_ERROR`, rather than silently overwriting — considered the safer default for now).

## API Authentication

- Geoapify API key storage uses the Drupal State API.
- State key: `geoapify_importer.api_key`
- Not exported through config sync; not stored in Git.
- Settings UI uses a password field; existing key is never displayed; UI shows whether a key is currently configured; leaving the field blank preserves the existing key.
- No Key module dependency.
- Confirmed intact and unaffected by this session's form/config changes (verified via `drush php:eval` presence check).

## Geoapify Client

- Service: `geoapify_importer.client`
- Class: `Drupal\geoapify_importer\Service\GeoapifyClient`
- Constructor uses PHP 8 constructor-property-promotion: `ClientInterface $httpClient`, `LoggerInterface $logger`, `StateInterface $state`.

### Places API — VERIFIED

- Endpoint: `https://api.geoapify.com/v2/places` (class constant `PLACES_ENDPOINT`, `private const`)
- Method: `request(array $query = []): array`
- Required parameters, confirmed against Geoapify's own docs: `apiKey` and at least one `categories` value.
- Spatial filter confirmed working: `filter=circle:lon,lat,radiusMeters` (rejects the previously-tried `filter=countrycode:ca` pattern, which is not a valid Places filter type).
- Live-tested query (Fort McMurray, museums):
  ```
  categories=entertainment.museum
  filter=circle:-111.3809,56.7264,5000
  limit=5
  ```
  Returned a valid `FeatureCollection` with 2 real features (Heritage Village, Marine Park Museum), including `place_id`, full `properties`, and `geometry`.

### Reverse Geocoding API — implemented, NOT YET LIVE-VERIFIED

- Endpoint: `https://api.geoapify.com/v1/geocode/reverse` (class constant `REVERSE_GEOCODE_ENDPOINT`, `private const`)
- Method: `reverseGeocode(float $lat, float $lon): array`
- Confirmed against Geoapify docs: response includes `rank.confidence`, `rank.confidence_city_level`, `rank.confidence_street_level`, `rank.confidence_building_level` (each 0–1), and `rank.match_type` (values include `full_match`, `inner_part`, `match_by_building`, `match_by_street`, `match_by_postcode`, `match_by_city_or_disrict`, `match_by_country_or_state`).
- **OPEN ITEM:** the exact array path used in `AddressVerifier` (`$result['results'][0]['rank']['match_type']`) is inferred from documentation only. A real `reverseGeocode()` test call has not yet been made in this project. Must be confirmed before this logic is trusted in production.

## Current Settings Route

- Path: `/admin/config/services/geoapify-importer`
- Route: `geoapify_importer.settings`
- Permission: `administer site configuration`
- Form class: `GeoapifyImporterSettingsForm`

### Bugs found and fixed this session

1. **Missing `ConfigFormBase` constructor call.** The form's constructor originally overrode the parent constructor entirely, injecting only `StateInterface` and never calling `parent::__construct()`. This left `$this->configFactory` unset, so any call to the inherited `$this->config()` helper would have fatally errored.
2. **Drupal 11's `ConfigFormBase` requires two constructor arguments, not one.** Initial fix only passed `ConfigFactoryInterface` to `parent::__construct()`, which still fataled (`ArgumentCountError`) because Drupal 11 also requires `TypedConfigManagerInterface` (added in core 10.2+ for config validation). Fixed by injecting both `ConfigFactoryInterface` and `TypedConfigManagerInterface`, updating `create()` to pull `config.typed` from the container alongside `config.factory` and `state`.
3. **`getEditableConfigNames()` returned an empty array**, appropriate only when the form touched no config objects (it previously only used State API). Updated to return `['geoapify_importer.settings']` now that the form owns real config.

**Verified working:** form loads with no errors, submits successfully, and `drush config:get geoapify_importer.settings` returns the correct saved values:
```
reverse_geocode_enabled: false
reverse_geocode_confidence_threshold: 0.8
reverse_geocode_accepted_match_types:
  - full_match
  - match_by_building
```

**Note:** the `geoapify_importer.settings` config object does not exist in the database until the settings form is submitted at least once — the schema only defines its shape, it doesn't create the object.

## Initial Drupal Destination

- Content type / bundle: `point_of_interest`
- `point_of_interest` is the bundle machine name, not the entity type; config names follow `node.point_of_interest.field_name`.

### Existing Point of Interest Fields

```
field_canadian_towns
field_description
field_hero_image
field_meta_description
field_poi_category
field_poi_tags
field_poi_location
field_poi_address
```

### Geographic model

- `field_poi_location` stores coordinates (primary geographic identity).
- `field_poi_address` stores an optional Canadian street address (address module).
- `field_canadian_towns` references the Canadian Towns taxonomy (province → town).
- Remote/natural POIs may have no meaningful town reference.
- Do not force Listings into an identical geographic model.

### POI Address — clarified relationship

`field_canadian_towns` and `field_poi_address` are **two parts of one logical address concept**, not independent fields:
- `field_canadian_towns`: structured province→town component, via taxonomy.
- `field_poi_address`: the remainder of the address (street, postal code, address lines), via the address module, with locality intentionally hidden because the taxonomy already owns that role.

This means the two fields must be treated as **one ownership/sync unit** — they cannot be synced independently without risking a mismatch between the taxonomy-referenced town and the address module's data.

**This logic must also apply to the future Listings content type** — see "Shared vs. Content-Type-Specific Logic" below.

## POI Taxonomy

72 terms: 8 parent categories, 64 child categories.

Parent categories: Arts & Attractions, History & Heritage, Landmarks & Structures, Nature & Landscapes, Parks, Religious & Sacred Places, Trails & Routes, Unusual & Quirky.

- User-facing/editorial; do not treat term IDs as permanent mapping logic.
- Geoapify category mapping must be data-driven (stable identifiers such as UUIDs, not raw term IDs).
- Schema.org mapping is a separate concern from editorial taxonomy.

### Initial Geoapify Mapping Direction

Unchanged from original spec — still needs re-verification against current Geoapify category documentation before being treated as authoritative. No changes made this session. Mappings must support at least: DIRECT, CONDITIONAL, IGNORE, UNMAPPED. Unmapped source categories must not silently become arbitrary POI categories.

## Field Ownership Matrix — RESOLVED (v1)

| Field(s) | Source data available? | Ownership | Sync behavior |
|---|---|---|---|
| Title (node title) | Yes — `properties.name` | Source-controlled | **SOURCE_INITIAL** — set on creation, never overwritten afterward. *(Open question: should this instead stay in sync long-term? Deferred.)* |
| `field_poi_location` | Yes — `geometry.coordinates` | Source-controlled | **SOURCE_AUTHORITATIVE** — always synced |
| `field_canadian_towns` + `field_poi_address` (combined unit — see above) | Yes — `properties.city`/`state` for town, `properties.address_line1/2`, `street`, `postcode` etc. for address | Source-controlled, mediated by mapping | **SOURCE_ASSISTED_REVIEW** by default; see verification rule below for when this is skipped |
| `field_poi_category` | Yes — via mapping engine | Source-controlled, mediated by mapping | **SOURCE_ASSISTED_REVIEW** — CONDITIONAL/UNMAPPED results are never auto-applied |
| `field_description` | No | Towns Canada-controlled | **EDITORIAL_LOCKED** |
| `field_hero_image` | No | Towns Canada-controlled | **EDITORIAL_LOCKED** |
| `field_meta_description` | No | Towns Canada-controlled | **EDITORIAL_LOCKED** |
| `field_poi_tags` | No | Towns Canada-controlled | **EDITORIAL_LOCKED** |

### Sync-behavior states (per-field; distinct from the per-record import-status enum)

- **SOURCE_AUTHORITATIVE** — always overwritten from the latest source fetch.
- **SOURCE_INITIAL** — populated once at creation, then left alone.
- **SOURCE_ASSISTED_REVIEW** — source proposes a value; lands in review (`NEEDS_REVIEW`) rather than auto-applying.
- **EDITORIAL_LOCKED** — sync never writes to this field.

### Address/town verification rule — RESOLVED (v1, configurable)

The address/town unit **skips review and is treated as verified** when either:

1. **Places API self-verification:** the Geoapify Places response for the place includes both `housenumber` AND `street` (a genuine civic address, not just a nearby-street reference or a place name in `address_line1`).
2. **Reverse-geocode fallback** (only triggered when #1 fails, and only if enabled — see below): the coordinates are reverse-geocoded, and the result's `rank.match_type` is in an admin-configurable accepted list (default: `full_match`, `match_by_building`) AND `rank.confidence_building_level` meets an admin-configurable threshold (default: `0.8`).

If neither condition is met, the address/town unit falls to `SOURCE_ASSISTED_REVIEW` as normal.

**Implemented as:** `Drupal\geoapify_importer\Service\AddressVerifier` (service ID `geoapify_importer.address_verifier`), calling `GeoapifyClient::reverseGeocode()` only when needed (fallback, not run on every record, to control API cost).

**Configuration (UI-editable via the settings form, not hardcoded):**
- `reverse_geocode_enabled` (boolean, default `false`) — whole feature is opt-in due to added API cost.
- `reverse_geocode_confidence_threshold` (float, 0–1, default `0.8`).
- `reverse_geocode_accepted_match_types` (list, default `['full_match', 'match_by_building']`).

All three are exposed on `/admin/config/services/geoapify-importer`, with UI copy explaining that enabling the fallback increases Geoapify API usage/cost. **Confirmed working end-to-end**, including config schema validation and persistence.

**OPEN ITEM:** the exact response field paths (`rank.match_type`, `rank.confidence_building_level`) are inferred from Geoapify documentation and have not yet been confirmed against a real `reverseGeocode()` API call. Must verify before relying on this in production.

## Shared vs. Content-Type-Specific Logic (Architecture Goal — reaffirmed)

Per the original spec's Architecture Goal, and reaffirmed explicitly this session for the address-verification rule specifically:

- **Shared/reusable (content-type-agnostic):** the fact that "does this coordinate resolve to a verified address" is a data-quality question about Geoapify itself, independent of what content type consumes the answer. `AddressVerifier` and `GeoapifyClient::reverseGeocode()` are built as shared infrastructure for this reason, returning a generic confidence result rather than writing directly to any POI-specific field.
- **NOT shared (content-type-specific):** the actual target field names (`field_canadian_towns`, `field_poi_address`) are POI's bundle-specific fields. Whether Listings uses the same taxonomy vocabulary, the same address module configuration, or a different geographic model entirely is **not yet known** and must not be assumed.
- **OPEN QUESTION, unresolved:** does the future Listings content type reference the same Canadian Towns taxonomy vocabulary as POI? Does it use the address module the same way (locality hidden, town via taxonomy)? This must be answered before any Listings-specific mapping is built, but does not block current POI work since the shared resolver's output is generic.

## Ingestion / Synchronization Design (unchanged from original spec — not yet implemented)

- Drupal cron, Queue API, Drush commands, pagination, rate limiting, error handling, logging, duplicate detection, change detection, review workflows.
- Planned Drush commands: `geoapify:import`, `geoapify:check-updates`, `geoapify:status` (names/options may be refined during implementation).

## Record Identity / Duplicate Detection

- Preferred identity: Geoapify place ID — confirmed as the directory-naming key in `SourceFileWriter`.
- Fallback mechanisms (coordinates; normalized name + geographic context) not yet implemented.
- Duplicate detection must be deterministic and logged — not yet implemented at the Drupal-node level (file-level identity via `place_id` is in place; node-level duplicate checking is not).

## Import Statuses (unchanged from original spec — not yet implemented)

`NEW`, `IMPORTED`, `UNCHANGED`, `CHANGED`, `NEEDS_REVIEW`, `IGNORED`, `ERROR`, `SOURCE_UPDATED` — final status model still deferred until change-detection logic is built, per original spec's own sequencing rule.

## Development Rules (unchanged, reaffirmed — with one addition)

- Work locally unless explicitly instructed otherwise.
- Never assume a `web/` directory exists.
- Never modify production while developing.
- Verify Drupal paths and API behavior before giving commands — do not claim behavior without verification.
- Preserve working code unless a change is necessary; prefer non-destructive changes.
- Do not create SQL storage for raw source data; do not put persistent raw source files inside the module directory.
- Do not hardcode taxonomy term IDs or match-type/confidence thresholds into business logic when a config-driven mechanism can be used instead.
- Do not build the entire importer in one step; separate verified facts from proposed design.
- **When editing existing files, work from the actual current file contents (e.g. by cloning the real repo), not from fragments or assumptions.** This session found two real bugs (missing `ConfigFormBase` constructor arguments) and a corrupted config schema (mis-nested YAML from a manual paste) that fragment-based editing had missed or caused. Cloning the actual GitHub repo and editing real file contents directly is now the standard approach going forward.

## Current Verified State

### Verified

- Local Drupal 11 environment; PHP 8.4+ target
- Module discovered, installed, service container loads
- Settings route and menu work
- State API stores Geoapify key; key is configured locally and confirmed intact
- `GeoapifyClient` instantiates; Places API request succeeds with real spatial-circle + categories query; returns valid `FeatureCollection`
- POI content type, geographic fields, address field, and taxonomy exist
- Module is under git version control, pushed to a public GitHub remote (`itwerxdotca/geoapify_importer`, `main` branch), managed via GitHub Desktop
- Local private filesystem configured and verified (`PrivateStream::basePath()` confirms it)
- Source-file naming/partitioning strategy implemented and live-tested (write, archive-on-rewrite, read-back all confirmed against real Geoapify data)
- Field ownership matrix defined (v1), including the combined town/address unit and its verification-skip rule
- `AddressVerifier` and `GeoapifyClient::reverseGeocode()` implemented, wired into config and the settings UI
- Settings-form constructor bugs found and fixed (missing `config.factory`/`config.typed` injection) — form now loads and submits correctly
- `geoapify_importer.settings` config object confirmed created with correct values via `drush config:get`
- All module YAML files rewritten and confirmed clean (verified via `cat -evt`, no tabs, correct nesting)

### Not yet implemented / not yet verified

- Live confirmation of the real reverse-geocode response shape (`rank.match_type`, `rank.confidence_building_level` paths are doc-inferred, not confirmed)
- Geoapify response archival retention/pruning policy (deferred, not blocking)
- Classification engine (category mapping DIRECT/CONDITIONAL/IGNORE/UNMAPPED logic)
- Mapping engine
- Node-level duplicate detection (beyond file-level `place_id` identity)
- Change detection logic (diffing `latest.json` against `history/`)
- POI creation/update service
- Queue workers, cron processing, Drush import/update/status commands
- Import status persistence
- Listings content type field structure (unknown — required before any shared-logic-to-Listings wiring can happen)
- Production deployment