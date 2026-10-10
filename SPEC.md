# Towns Canada — Geoapify Importer Project Specification (Updated)

## Revision Notes (latest revision)

**Newest changes (this pass):**

1. **POI detail fields created and filled.** Seven fields (website, phone, email, opening hours, operator, amenities, and the amenity-mapping field) are created by `hook_install` (fresh installs) and `hook_update_10002` (existing sites). A new shared `PlaceInfo` service turns a place's Places properties into clean values; the processor builds the POI field values; the creator sets them on new nodes and the updater fills each only when empty. Verified on devtop: 8 existing nodes updated, 0 errors, each saved as a revision. See "POI Detail Fields".
2. **No Place Details call is needed for these.** A read-only scan of the stored places showed they already carry website, phone, email, hours, operator and facilities wherever OpenStreetMap has them, so the fields are filled from the stored data at no API cost. `PlaceDetails` exists but nothing calls it for filling.
3. **Amenities use the existing `poi_amenities` vocabulary**, with a new mapping field listing the Geoapify facility names each term matches. Devtop's Listing `amenities` vocabulary (1,823 junk numbered terms) was cleaned to production's 12.
4. **Opening hours:** stored as the raw OpenStreetMap string in plain long text on POIs; the Office Hours module (8.x-1.29, enabled on both sites) is for Listings. See "POI Detail Fields".

**Previous pass:**

1. **Node update logic built and verified** (`PoiNodeUpdater`). Existing POI nodes are now brought up to date per the field ownership matrix instead of being skipped. See "Node Updates".
2. **Town connection decided and partly built.** The node's town is the town the import was searching (boundary first, circle fallback also assigns), decided once in the shared `TownImportRunner` and applied by the creator and updater. See "Town and Address Connection".
3. **Repository and data scan.** Real counts from devtop showed that name-matching places to town terms resolves only 11% of node-eligible places, and that none of them has a street address. Both are recorded with the numbers.
4. **Built, then dropped:** `PlaceAddress` and `GeoPoint`. Recorded so they are not rebuilt.
5. **Devtop and production POI/Listing fields matched, and the town connection verified.** A script with production's configuration built in (dry run by default, no deletions) brought 8 field objects in line after a database backup, including attaching `field_canadian_towns` to `point_of_interest`. Run against Taber (a boundary search), the import then connected all 10 existing nodes to their town, and a repeat run reported 0 updates.
6. **Stale spec statements corrected** (module structure, node-level duplicate detection, node creation scope, the town resolver signature, the circle fallback), and the original spec sections that an earlier rewrite had dropped were restored (see "Original Specification Details (restored)").
7. **Accepted difference:** devtop's `canadian_towns` term fields differ from production's. Production has over 26,000 terms; devtop is a smaller test set and does not need to match.

**Previous pass:**

1. **Production field deployment groundwork built.** Five config/install YAML files (field storage + field instance definitions) added for the two fields this module genuinely owns: `field_source_storage_key` (now attached to BOTH `point_of_interest` AND `listing` — a deliberate choice, since it's a node-level dedup concept, not POI-specific, and the project's own architecture goal is for a future Listing importer to reuse the same mechanism) and `field_geoapify_categories` (on the `poi_category` taxonomy). A `geoapify_importer.install` file was added with `hook_requirements()` (checks that `field_poi_location`/`field_poi_address`/`field_poi_category` — fields this module does NOT own and must not try to create — actually exist, failing loudly if not) and `hook_update_10001()` (creates the two owned fields programmatically on an already-installed site, since `config/install` only applies automatically on a brand-new install).
2. **A real dry-run accuracy bug found and fixed.** `PoiImportProcessor`'s dry-run path assumed every successfully-mapped place would be newly created, without ever checking whether a node already existed — because the existence check previously lived only inside `PoiNodeCreator::create()`, which dry-run skipped entirely. Found via a real test: re-running `--dry-run` against Taber (already fully imported) reported "17 created" for places that already had real nodes. Fixed by making `PoiNodeCreator::findExistingNodeId()` public and having dry-run call it directly; also fixed dry-run to check for the "no usable name" case the same way a real run does, rather than only being accurate for the `nodes_created`/`nodes_skipped_existing` split.
3. **Session note:** this project is being handed off to a new account/session at this point. Everything below reflects the module's real, current state as verified this session — treat it as a reliable starting point for continuation, not as requiring re-verification from scratch.

**Previous pass's newest changes — this was a large stretch of work:**

1. **The import pipeline is now complete end-to-end, not just ingestion.** `PoiCategoryMapper` (DIRECT taxonomy mapping via a new `field_geoapify_categories` field on the `poi_category` vocabulary) and `PoiNodeCreator` (create-only node creation, never update) are built and verified. `PoiImportProcessor` orchestrates mapping+creation per place, consuming `TownImportRunner`'s output. `geoapify:import` now creates real, unpublished POI nodes, not just raw files. **Current real scale: 139 POI nodes created, 1,115 raw places stored, across multiple towns (testing has gone beyond Taber to other cities).**
2. **A real duplicate-record bug was found and fixed via the `building` tag issue, and a second real bug was found and fixed: `PoiNodeCreator` originally counted "no usable name" (a real, common case for unnamed OSM features) as a generic error — now counted separately (`no_name`) since it isn't a failure.**
3. **An alternative data source (AnythingPOI, a 5GB GeoParquet dataset) was evaluated in depth and rejected in favor of staying on Geoapify** — see new "Alternative Data Source Evaluation" section. Real findings: ~12x more raw coverage for the same search area, but a real, unmitigated duplicate-record problem (same place appearing as separate, unconflated rows from OSM vs. Overture) and a README that didn't match its own data twice (ID prefix convention, confidence-score baseline). ODbL licensing was researched in depth and is NOT a blocker for either source (Produced Work doctrine covers the planned business model) — important finding, not just a caveat.
4. **Geoapify's Place Details API is now integrated as an optional enrichment step**, addressing the sparse-contact-data gap found during the AnythingPOI comparison — see new "Rate Limiting and Enrichment" section. Looked up by OSM type/ID (no extra lookup needed). Off by default.
5. **A global daily rate limit is now enforced inside `GeoapifyClient` itself**, across every endpoint it calls (Places search, both geocoding directions, Place Details) — not scoped to any single feature. This was a deliberate design correction: an initial version scoped the limit to Place Details only, which would have missed the real risk of ordinary Places/geocoding calls alone exhausting a day's credit allowance during an unattended, cron-driven run.
6. **A real devtop environment problem was found and fixed:** PHP was resolving to 8.3.33 via Herd despite the global Herd setting showing 8.4 — this project's Herd site had its own separate isolation setting, only fixable via `herd use 8.4` for the CLI specifically (site-level `herd isolate` only affects web requests, not terminal commands).
7. **A real database schema problem was found and fixed:** devtop's `field_poi_address` table was missing a column (`address_line3`) that the installed Address module's current schema expects — likely built by an older module version. Fixed via direct `ALTER TABLE`, since Drupal's own `field:delete`/`updatedb` tooling could not resolve it (both depend on querying the same broken table).

**Previous pass's newest changes:**

1. **Category classification decided and expanded.** `PoiCategoryClassifier` now has three outcomes: `ignored`, `needs_review`, `pending_mapping`. Business-model decisions confirmed: commercial categories default to `ignored` (reason `commercial`, destined for Listings); historic sites, government buildings, hospitals, police, fire stations, tourism info centres, and cemeteries are exceptions that stay POI-eligible; museums/zoos/aquariums/theme parks/galleries/theatres and a gray-area group (beach resorts, campgrounds, marinas, ski lifts, stadiums, golf courses, brewery/winery/distillery tours) always require manual `needs_review`, because the deciding factor (usually ownership) isn't in Geoapify's data.
2. **Real bug found and fixed:** a blanket `building` ignore rule was silently mis-classifying real POI candidates (hospitals, churches, historic sites, government buildings) that carry a generic `building.*` tag alongside their specific category. Removed entirely; every genuine commercial case already matches independently.
3. **Classification wired into `geoapify:fetch`** as a reported column — never a write gate. Every fetched place is still stored regardless of classification.
4. **New finding: a fixed-radius circle is a measurably poor proxy for a town's real shape.** Confirmed on Calgary: a 20km circle and Calgary's real administrative boundary disagreed on 5 of ~198 supermarkets. `TownBoundaryResolver` (new) resolves and caches a town's real Geoapify boundary via Forward Geocoding, for use as `filter=place:{id}` instead of `filter=circle:...`. A joint confidence threshold (`match_type=full_match` AND `confidence=1`) was verified as necessary, not just cautious, via a real case: Tadmore, BC (a hamlet) returned `full_match` but `confidence=0`, and was correctly rejected only because both conditions are required together.
5. **`canadian_towns` taxonomy structure clarified:** province-level parent terms exist above town terms (an earlier query grabbed "Alberta" and found no coordinates, which is why); `field_geolocation` stores plain `lat`/`lng` degrees plus Geolocation-module-computed trig fields; `field_type` holds real Statistics-Canada-style designations (Locality, Hamlet, Village, Town, City), confirmed via real data and not yet used by any code.

**Previous pass's changes:**

1. **Record identity corrected.** Geoapify's `place_id` is NOT stable across requests, so it cannot be the record identity (the original spec's preferred identity, and something this project asserted without testing). The storage key is now derived from the OpenStreetMap type and ID by a new `PlaceIdentity` service. See "Record Identity / Duplicate Detection".
2. **Change detection built and verified** (`ChangeDetector`), including a 50 m distance tolerance for coordinates after an exact comparison produced a false "changed" result. See "Change Detection".
3. **`geoapify:fetch` now skips unchanged places** and reports a status per place.
4. **Directory-case bug fixed:** the classifier folder was committed as `Service/POI` but its namespace is `...\Service\Poi`. This worked on macOS (case-insensitive) but would have broken autoloading on production (Linux). Renamed to `Service/Poi`.
5. **New open item:** multi-category Places queries returned a subset of what was expected. See "Places API".

The copy of this document in the module repository is `SPEC.md`; keep the two in sync.

## Purpose

Build a reusable Drupal module named Geoapify Importer for Towns Canada.

The module imports and synchronizes geographic place data from the Geoapify Places API into Drupal, initially targeting the Point of Interest content type.

The importer must be designed as a maintainable ingestion pipeline that can later support additional source types, including Listings.

## Environment

### Local development

- Drupal root: `/Users/ronmacquarrie/Sites/townscanada/public_html`
- Custom module: `/Users/ronmacquarrie/Sites/townscanada/public_html/modules/custom/geoapify_importer`

### Production — CONFIRMED, live-verified this session

- Drupal root: `/home/townscanada/public_html`
- Drupal version: **11.4.7**
- PHP version: **8.4.26** (`/usr/bin/php8.4`)
- Drush version: **13.8.0.0** (matches local exactly)
- DB driver: mysql
- Install profile: standard
- Private files path: `/home/townscanada/backup` — **OPEN ITEM:** this path's name suggests it may originally be a backup-specific directory that happens to also serve as `file_private_path`. Confirm this is intended as the general-purpose private filesystem location before deploying `geoapify_importer` there, since our raw source files would live alongside whatever else uses that path.
- Access confirmed via SSH; all production checks performed this session were read-only (`drush php:eval` queries, `drush status`) — no writes made to production.
- **Local and production versions match exactly.** No platform-mismatch risk identified.

### Drupal / PHP

- Drupal 11.x (confirmed 11.4.7 on production)
- PHP 8.4+ (confirmed 8.4.26 on production)
- Must remain compatible with Drupal 12
- Module requirement: `^11 || ^12`
- No `web/` directory in this Drupal installation. Configuration paths use `sites/default/...`.

## Version Control

- Module is its own git repository, rooted at the module directory.
- Public GitHub remote: `https://github.com/itwerxdotca/geoapify_importer`
- Branch: `main`, managed via GitHub Desktop.
- `.gitignore` excludes OS/editor cruft; no secrets stored in module files.

## IMPORTANT: Local vs. Production Data Divergence — CONFIRMED THIS SESSION

**Local devtop and production do NOT share the same taxonomy content.** This was flagged by the project owner and is now confirmed as a real, structural fact of this project, not an assumption:

- Local's taxonomy term IDs, and potentially term names/counts, differ from production's.
- Any classification/mapping logic MUST query the live taxonomy on whichever environment it is running on at the time — it must NEVER rely on a static table of term IDs, or even a static table of term UUIDs built from one environment, since term *content* itself (which terms exist, what they're tagged with) can differ between environments, not just their internal IDs.
- This is the deciding factor behind the "dynamic mapping" approach chosen this session (see Classification Engine section below) over a static config-file mapping table.

**Production research was conducted this session** (read-only, via SSH + `drush php:eval` / `drush status`) specifically to stop building against assumptions and confirm real field structures. Findings are incorporated throughout this document. This should be the standard practice going forward: prefer confirming real production structure over extending assumptions built only against the local devtop.

## Module

- Machine name: `geoapify_importer`
- Name: `Geoapify Importer`

### Current module structure (as committed, plus the uncommitted town-passing changes)

```
geoapify_importer/
├── config/
│   ├── install/
│   │   ├── field.field.node.listing.field_source_storage_key.yml
│   │   ├── field.field.node.point_of_interest.field_source_storage_key.yml
│   │   ├── field.field.taxonomy_term.poi_category.field_geoapify_categories.yml
│   │   ├── field.storage.node.field_source_storage_key.yml
│   │   └── field.storage.taxonomy_term.field_geoapify_categories.yml
│   └── schema/
│       └── geoapify_importer.schema.yml
├── src/
│   ├── Drush/Commands/
│   │   ├── GeoapifyImportCommands.php
│   │   └── GeoapifyImporterCommands.php
│   ├── Form/
│   │   └── GeoapifyImporterSettingsForm.php
│   └── Service/
│       ├── AddressVerifier.php
│       ├── ChangeDetector.php
│       ├── CoordinateTransformer.php
│       ├── GeoapifyClient.php
│       ├── GeoapifyRateLimiter.php
│       ├── PlaceDetails.php
│       ├── PlaceIdentity.php
│       ├── PlaceInfo.php
│       ├── SourceFileWriter.php
│       ├── TownBoundaryResolver.php
│       ├── TownImportRunner.php
│       └── Poi/
│           ├── PoiCategoryClassifier.php
│           ├── PoiCategoryMapper.php
│           ├── PoiImportProcessor.php
│           ├── PoiNodeCreator.php
│           └── PoiNodeUpdater.php
├── geoapify_importer.info.yml
├── geoapify_importer.install
├── geoapify_importer.links.menu.yml
├── geoapify_importer.routing.yml
├── geoapify_importer.services.yml
├── SPEC.md
└── .gitignore
```

Note the `Service/Poi/` subdirectory: it keeps POI-specific classification logic separate from shared ingestion infrastructure (`GeoapifyClient`, `SourceFileWriter`, `AddressVerifier`, `ChangeDetector`, `PlaceIdentity`), per the Architecture Goal.

**Rule: directory names must match namespace casing exactly.** Local macOS is case-insensitive and hides mismatches; production Linux is case-sensitive and does not.

## Source Data Architecture

Raw source data is stored as **files**, not SQL. Pipeline (unchanged):

```
Geoapify API → Raw source files → Classification/validation/change detection → Drupal taxonomy + fields → Drupal database
```

### Private filesystem — RESOLVED (local); OPEN ITEM (production naming)

- Local: `/Users/ronmacquarrie/Sites/townscanada/private`, configured and verified.
- Production: `/home/townscanada/backup` — exists and is configured as `file_private_path`, but its name warrants confirmation before use (see Environment section above).

### Source-file naming/partitioning strategy — RESOLVED, VERIFIED AT SCALE

```
private://geoapify_importer/
├── pois/
│   ├── {storage_key}/
│   │   ├── latest.json
│   │   └── history/
│   │       └── {timestamp}.json
```

- `{storage_key}`: derived by `PlaceIdentity` from the OpenStreetMap type and ID, e.g. `osm-w-306707925`. Features with no usable OSM reference fall back to `gid-{place_id}`. **This replaces the earlier scheme of using Geoapify's `place_id` verbatim**, which was found to be unstable (see "Record Identity / Duplicate Detection").
- `{timestamp}`: `gmdate('Ymd\THis\Z')`.
- Archive-on-rewrite confirmed working; atomic writes (temp + rename).

**Implemented as:** `Drupal\geoapify_importer\Service\SourceFileWriter` (`geoapify_importer.source_writer`). `write()` and `readLatest()` both verified against real Geoapify data, including 7-feature batch fetches (see `geoapify:fetch` below). The methods' parameter is still named `$place_id` in code for historical reasons; it accepts any storage key (only `[a-zA-Z0-9_-]` survive sanitizing, which the `osm-`/`gid-` keys satisfy).

**Known edge case, unaddressed:** same-second double-write to one place collides in `history/` (currently throws via `EXISTS_ERROR` — considered acceptably safe for now).

## API Authentication

Unchanged. State API key (`geoapify_importer.api_key`), password field UI, confirmed intact through all changes this session.

## Geoapify Client

- Service: `geoapify_importer.client`; class `Drupal\geoapify_importer\Service\GeoapifyClient`.

### Places API — VERIFIED, including at scale

- Endpoint: `https://api.geoapify.com/v2/places` (`private const PLACES_ENDPOINT`).
- `request(array $query = []): array`.
- Required: `apiKey`, at least one `categories` value.
- Spatial filter: `filter=circle:lon,lat,radiusMeters`.
- **`limit` parameter confirmed against Geoapify's own docs: default 20, maximum 500.**
- Live-tested this session at `limit=20` (returned 7 real features — see Classification Engine section for what those features revealed).
- **OPEN ITEM — multi-category queries.** `categories=entertainment,tourism` returned 7 places, all `tourism.attraction.artwork.sculpture` (the Seven Grandfather Teachings), and none of the museums or theatres. `categories=entertainment` alone returned 7 different places (Landmark Theatres, Keyano Theatre & Arts Centre, Oil Sands Discovery Centre, Heritage Village, Landmark Cinema, Marine Park Museum, The Alley YMM). With `limit=20`, a union of the two would be expected to return more than 7, but the cause of what was actually returned is unknown and has not been investigated. Until understood, do not rely on comma-separated `categories` returning the union of its parts.

### Reverse Geocoding API — VERIFIED AND CORRECTED THIS SESSION

- Endpoint: `https://api.geoapify.com/v1/geocode/reverse` (`private const REVERSE_GEOCODE_ENDPOINT`).
- `reverseGeocode(float $lat, float $lon): array`.
- **CORRECTED FINDING:** earlier assumption that this endpoint returns `rank.match_type` / `rank.confidence_building_level` was WRONG, confirmed via two real live calls (Marine Park Museum; Bitumount historic site). The actual `rank` object on Reverse Geocoding responses contains only `importance` and `popularity` — no match/confidence fields at all. Those fields are documented under Forward Geocoding and Address Autocomplete specifically (APIs that match a *requested* address against candidates); Reverse Geocoding has no requested address to match against, so it structurally cannot return them.
- **Corrected design:** the fallback now checks for `housenumber` + `street` directly on the reverse-geocode result — the same signal already used for the primary Places API check. All config for the old (invalid) match_type/confidence approach was removed from schema, form, and stored config.
- **Live-tested against a deliberately hard case:** Bitumount (a remote 1920s–1950s oil sands ruin, ~90km north of Fort McMurray) reverse-geocodes to `street: "True North Road"` but has **no `housenumber`** — correctly resolves to `UNVERIFIED`, confirming the rule correctly declines to fabricate a false-positive verified address for a remote landmark with no real civic address.

## Current Settings Route

- Path: `/admin/config/services/geoapify-importer`; form class `GeoapifyImporterSettingsForm`.

### Bugs found and fixed this session (see prior spec revision for full detail; summarized here)

1. Missing `parent::__construct()` call to `ConfigFormBase` — fixed.
2. Drupal 11's `ConfigFormBase` requires BOTH `ConfigFactoryInterface` AND `TypedConfigManagerInterface` — fixed; this fix was initially made locally but not committed/pushed until a follow-up round caught the discrepancy between what was running locally and what was on GitHub.
3. `getEditableConfigNames()` was empty; now returns `['geoapify_importer.settings']`.
4. **Dead config removed:** `reverse_geocode_confidence_threshold` and `reverse_geocode_accepted_match_types` were removed entirely from schema, form, and stored config (via `drush config:delete` + re-save), since they referenced fields that Reverse Geocoding never actually returns (see above).

**Current config shape**, verified via `drush config:get`:
```
reverse_geocode_enabled: false
```

## Point of Interest — CONFIRMED REAL PRODUCTION FIELDS

Bundle: `point_of_interest`. Fields confirmed live on production this session (matches original spec and local devtop exactly):

```
field_canadian_towns   (entity_reference -> canadian_towns taxonomy)
field_description      (string_long)
field_hero_image       (entity_reference -> image)
field_poi_address      (address)
field_poi_location     (geolocation)      <- NOTE: Geolocation module, not plain lat/lon
field_poi_tags         (entity_reference -> tags)
field_meta_description (string)
field_poi_category     (entity_reference -> POI category taxonomy, 72 terms)
```

**Location:** `field_poi_location` uses the Geolocation module; Geoapify's `[lon, lat]` is converted by `CoordinateTransformer` (built, verified via an actual write).

### POI Address — clarified relationship (unchanged from prior revision)

`field_canadian_towns` + `field_poi_address` are one logical address unit (town via taxonomy, remainder via address module), synced together, not independently. Verification-skip rule (housenumber+street, corrected this session) applies to this unit.

## Listing Content Type — NEWLY DOCUMENTED THIS SESSION (real production fields)

Bundle: `listing`. This content type already exists on production with a considerably more developed structure than assumed at the start of this session. Confirmed fields:

```
field_business_ownership_types (entity_reference -> business_ownership_type taxonomy)
field_canadian_towns            (entity_reference -> canadian_towns)  <- SAME vocabulary as POI
field_hero_image                (entity_reference -> image)
field_listing_category          (entity_reference -> listing_category)  <- SEPARATE from POI's category taxonomy
field_listing_components        (entity_reference_revisions -> listing_gallery, listing_social, listing_amenities)  <- Paragraphs
field_listing_phone             (telephone)
field_listing_tags              (entity_reference -> tags)
field_listing_website           (link)
field_meta_description          (string)
field_street_address            (address)
field_street_location           (geolocation)  <- SAME field type as POI's field_poi_location
```

**Key findings from this discovery, resolving previously-open questions:**

1. **`field_canadian_towns` is shared between POI and Listing** — same vocabulary, confirmed by direct field inspection. The combined town/address ownership-unit design built for POI extends cleanly to Listing without modification.
2. **Listing has its own independent category taxonomy** (`listing_category`, separate from POI's 72-term vocabulary). No collision with the POI classification work.
3. **Listing already has a Paragraphs-based component system** (`field_listing_components`), referencing `listing_gallery`, `listing_social`, `listing_amenities` paragraph bundles. This is the existing, real mechanism that should be used for "paid tier unlocks more" — see Business Model / Classification Strategy section below. No new architecture needs to be invented for this; it already exists.
4. **`field_business_ownership_types`** references a rich, real taxonomy (Hybrid/Franchise, Indigenous Business Models, Local Ownership Models, National/International Ownership Models, Regional Ownership Models — ~30 terms total). **Confirmed purpose (per project owner): this field exists for a FUTURE feature** allowing listing owners to network/group with each other (e.g. "friends" lists, resource-sharing, easier contact between related businesses) — it is NOT intended for, and does not fit, POI-vs-Listing import classification. It has no term representing "not a business" (e.g. no term for government/public services), because that was never its purpose. **Decision: this field is out of scope for the classification engine.** It remains available to be "utilized" (project owner's words) for the ownership-networking feature when that is eventually built, but the importer must not repurpose it for import-time classification, to avoid conflating two unrelated meanings on one field.

## Business Model / Content-Type Classification Strategy — MAJOR DISCUSSION THIS SESSION, DECISION IN PROGRESS

This is the most significant open design conversation from this session and is **not yet fully resolved** — captured here in detail so the reasoning isn't lost.

### The problem

Geoapify categories don't cleanly separate into "free public attraction" vs. "commercial business" — many genuine tourist attractions (museums, zoos, aquariums, theme parks, breweries-with-tours) are also commercial operations. The original spec's mapping table listed several of these (`entertainment.museum`, `.zoo`, `.aquarium`, `.theme_park`, `.culture.*`) as DIRECT POI mappings — but the project owner's business model is to **sell Listings to businesses**, meaning giving a zoo or museum away for free as a POI actively undercuts a potential sale.

### Discovery that triggered this discussion (real data, this session)

Running the new `geoapify:fetch` Drush command (see below) against a 15km radius around Fort McMurray returned 7 features, all named after virtues: Humility, Truth, Honesty, Wisdom, Courage, Respect, Love. Investigation of the raw stored JSON confirmed these are individually-geocoded points under `tourism.attraction.artwork.sculpture` — very likely Fort McMurray's "Seven Grandfather Teachings" public art installation, tagged as 7 separate OSM nodes rather than one site. This is real, concrete evidence that naive category-based import produces obviously-wrong results (7 near-duplicate "attractions" that are really one sculpture series), and became the first confirmed entry in the classification engine's IGNORE list.

### Options discussed, in order

1. **Static, hardcoded config-file mapping table** (Geoapify category → term UUID). **REJECTED** once local/production taxonomy divergence was confirmed — a static table built from one environment's real UUIDs would be wrong on the other environment if term content itself differs, not just term IDs.

2. **Dynamic, taxonomy-field-driven mapping** (add `field_geoapify_categories` to POI Category taxonomy terms; mapper queries live taxonomy at classification time; hierarchy-walking so not every leaf category needs individual tagging). **ADOPTED AS THE CORRECT APPROACH**, specifically because it works correctly regardless of what each environment's taxonomy actually contains — no environment-specific code or manual sync needed. This was the direct resolution to the local/production divergence problem.

3. **Simple "conducts business = Listing, doesn't = POI" rule.** Discussed, but immediately ran into real tension: applying it strictly would flip museums/zoos/aquariums/theme parks from POI (original spec) to Listing — a deliberate reversal the project owner confirmed wanting, BUT which creates cases too ambiguous to resolve by category alone (e.g., is a municipally-run museum different from a privately-owned one? Geoapify's category data cannot tell us ownership).

4. **Explicit exceptions carved out regardless of "sells something":** historic places, government buildings, medical centers/hospitals, police stations, fire stations — confirmed by project owner as staying POI regardless of any commercial activity. **RESOLVED:** private medical/dental clinics and pharmacies are `ignored` (commercial), unlike hospitals — confirmed by project owner.

5. **Investigated whether `field_business_ownership_types` could resolve the public/private ambiguity automatically.** **RULED OUT** — see Listing Content Type section above; that field is scoped for a different, future purpose and has no "not a business" term.

6. **Discussed real-world precedent: how does Google structure Places/Business Profiles?** Confirmed via research: Google uses **one record per place, free by default**, with paid features (e.g. "Google Guaranteed") as an add-on to the *same* record — not a separate listing type for paid vs. free. This directly informed the recommended direction below.

7. **Discussed merging POI and Listing into one content type** to avoid the classification problem entirely. **REJECTED** by project owner — explicitly wants to keep the two existing content types separate, citing the cost of rebuilding search/views/permissions/templates around a merged structure. Confirmed: **no merge; POI and Listing remain fully separate content types, unchanged in their existing structure.**

8. **Discussed "start as free POI, later upgrade to paid Listing" as a genuine product goal** (confirmed: yes, this is wanted, prioritizing thoroughness over minimizing build size). Two architectural options were presented:
   - **Option 1 (recommended, not yet built):** Listing is its own node with an entity-reference field pointing back to a POI node it "upgrades"; POI stays the canonical free record, Listing adds paid-only data on top. No duplicate name/address/coordinates.
   - **Option 2:** a shared underlying "Place" data structure referenced by both POI and Listing as siblings, rather than one being primary. Bigger to build, not currently justified given the confirmed POI-first flow.
   - **Not yet finalized** which option to build, though Option 1 fits the stated flow (POI first, then optionally upgraded) most directly. Also not yet resolved: whether a POI is ever retired/removed once upgraded, or permanently remains as the free base layer with the Listing purely additive (current assumption, unconfirmed: the latter).

9. **Applying the Google model to Listing's EXISTING structure (this session's most concrete conclusion):** Since Listing already has a real Paragraphs-based component system (`field_listing_components`), the "paid unlocks more" mechanism does not need to be invented — a paid Listing simply gets access to more/different paragraph types (or richer versions of `listing_gallery`/`listing_social`/`listing_amenities`) attached to this same existing field, likely gated by a new boolean flag (e.g. `field_is_paid`, not yet created) or a future real subscription/billing record. **This significantly de-risks the "paid components" part of the plan — it's additive to Listing's existing structure, not a rebuild.**

### Classification rule — RESOLVED AND CODED (see Classification Engine section below for the full, current table)

- Default: a Geoapify category representing a commercial operation → `ignored` (reason `commercial`), destined for Listing.
- Explicit exceptions, POI-eligible regardless of commercial activity: historic/heritage sites, government buildings, hospitals, police, fire stations, tourism info centres, cemeteries.
- Private medical/dental clinics and pharmacies: `ignored` (commercial).
- Museums/zoos/aquariums/theme parks/galleries/theatres, and a gray-area group (beach resorts, campgrounds, marinas, ski lifts, stadiums, golf courses, brewery/winery/distillery tours): confirmed by project owner as `needs_review` — always manual, case by case, because Geoapify's data cannot answer the deciding question (usually ownership). This is now a real, coded third classifier outcome, not just a working assumption.

**Still open:** whether/how a POI can later be upgraded to a paid Listing (Option 1 vs. Option 2 above) remains undecided and unbuilt; Listing's own field-ownership matrix is undesigned; `field_is_paid` or equivalent does not exist yet.

## Classification Engine — CATEGORY DECISIONS RESOLVED; MAPPER BUILT AND VERIFIED

### Geoapify's full category list — authoritative, live-pulled

833 categories confirmed via Geoapify's own `list_place_categories` endpoint. Authoritative source for all mapping/classification work.

### `PoiCategoryClassifier` — three outcomes, expanded and verified

- Class: `Drupal\geoapify_importer\Service\Poi\PoiCategoryClassifier`. Service: `geoapify_importer.poi_category_classifier` (no dependencies).
- **`classify(array $categories): array`** now returns one of three statuses, not two:
  - **`ignored`** — never becomes a POI. Carries a `reason`: `not_a_place` (e.g. individual artwork), `commercial` (belongs in Listings), `infrastructure` (roads, utilities, parking — not a place or business), or `administrative_area` (regions/boundaries, not point attractions).
  - **`needs_review`** — NEW. A known, real category that can never be auto-classified, because the deciding factor (usually public vs. private ownership) is not in Geoapify's data. A future DIRECT-mapping mapper must not bypass this list even once taxonomy tagging exists for these categories.
  - **`pending_mapping`** — confirmed POI-eligible; `PoiCategoryMapper` attempts to resolve a real taxonomy term for these (see below).
- **Business-model policy, confirmed with the project owner and encoded in the classifier:**
  - Default: a commercial category is `ignored` (reason `commercial`), destined for the Listing content type, not POI.
  - Explicit exceptions — POI-eligible regardless of commercial activity, achieved simply by never appearing in any ignore list: historic/heritage sites, government buildings (`office.government.*`), hospitals (`healthcare.hospital`), police (`service.police`), fire stations (`service.fire_station`), tourism info centres (`tourism.information.*`), cemeteries (`memorial.cemetery`, `.graveyard`). `office.government.*` and `service.police`/`.fire_station` needed an explicit `IGNORE_EXCEPTIONS` carve-out, checked first, because they sit under otherwise-ignored parents (`office`, `service`).
  - Always `needs_review`, confirmed by the project owner: `entertainment.museum`, `.zoo`, `.aquarium`, `.theme_park`, `.culture.arts_centre`, `.culture.gallery`, `.culture.theatre` (classic attractions that also charge admission — ownership determines the real answer); `beach.beach_resort`, `camping`, `maritime.marina`, `ski.lift`, `sport.stadium`, `sport.golf_course`, `production.brewery`/`.winery`/`.distillery` (gray-area group, explicitly left for manual, case-by-case review rather than a blanket rule).
  - Private medical/dental clinics and pharmacies (`healthcare.clinic_or_praxis`, `.dentist`, `.pharmacy`) confirmed `ignored` (commercial) — unlike hospitals, these are private commercial practices.
- **Real bug found and fixed:** the ignore list originally included a blanket `building` entry. Geoapify tags many places with a generic `building.*` category ALONGSIDE their specific one (e.g. a museum carries both `building.tourism` and `entertainment.museum`; a church carries `building.place_of_worship` and `religion.place_of_worship.*`). Because `building` is a parent of children like `building.healthcare`, `building.historic`, `building.place_of_worship`, and `building.public_and_civil`, the blanket rule would have silently ignored real POI candidates whenever the generic tag was checked before the specific one — including hospitals, churches, historic sites, and government buildings, none of which are on any ignore list. Found via real data (Oil Sands Discovery Centre carries `building.tourism`) and only avoided by luck of matching order. Removed entirely rather than narrowed, since every genuine commercial `building.*` case already matches independently via its own specific category.
- **PROVENANCE:** most of this table was built from the full 833-category list plus this policy discussion, not from categories actually seen in fetched data — unlike the original single artwork entry. Treat as a first, reviewable pass; expand/correct as real data turns up mismatches.
- **Verified:** real stored records (Marine Park Museum → `needs_review`; Landmark Theatres → `ignored (commercial)`, confirmed via its real categories as `entertainment.cinema`, not a live theatre; The Alley YMM → `ignored (commercial)`, a bowling alley; Oil Sands Discovery Centre → `needs_review`, a real museum). Synthetic cases for the exception carve-out (government office, police → `pending_mapping`; law office, pharmacy → `ignored (commercial)`) and for the `building` fix (church with a `building` tag, bare `building.healthcare` → both now correctly `pending_mapping`; a restaurant with a `building` tag still correctly `ignored (commercial)`).

### Classification wired into `geoapify:fetch`

The fetch command now runs `PoiCategoryClassifier` on every fetched place and reports it in a new Classification column. **Classification never gates whether a place is written or skipped** — only `ChangeDetector` does that. Every fetched place is stored regardless of what it classifies as, per the decision to store first and sort on top of stored data.

### `PoiCategoryMapper` — DIRECT mapping, built and verified

- Class: `Drupal\geoapify_importer\Service\Poi\PoiCategoryMapper`. Service: `geoapify_importer.poi_category_mapper`.
- Scope: DIRECT mapping only (exact tag match), not the original spec's CONDITIONAL concept — no real conditional case has shown up in data.
- New field `field_geoapify_categories` (multi-value string) added to the `poi_category` taxonomy vocabulary (confirmed real machine name: `poi_category`). Editors tag a term (e.g. "Historical") with the Geoapify category strings it should catch (e.g. `entertainment.museum`).
- `map(array $categories): ?array` tries each of a place's categories, most specific first, against live-tagged terms. Returns `NULL` (→ `UNMAPPED`, routed to review, never a silent guess) if nothing matches.
- **Queries the live taxonomy on whichever environment it runs on** — same reasoning as the rest of the dynamic-mapping design: the field is config (identical everywhere), its values are content (expected to differ between devtop and production).
- Logs a warning (not an error) if more than one term claims the same category — a real editorial tagging conflict, not a crash.
- **Verified:** a real tagged term ("Historical" → `entertainment.museum`) correctly matched a real stored record (Heritage Village); an untagged category (`waterway.channels`) correctly returned `UNMAPPED`.

### `PoiNodeCreator` — node creation, CREATE ONLY, built and verified

- Class: `Drupal\geoapify_importer\Service\Poi\PoiNodeCreator`. Service: `geoapify_importer.poi_node_creator`.
- **Creates only; it never updates an existing node.** Updating is `PoiNodeUpdater` (see "Node Updates").
- Only called for places already resolved to a real term by `PoiCategoryMapper` — `needs_review` and `UNMAPPED` places get no node yet.
- New field `field_source_storage_key` (string) — now shared across BOTH `point_of_interest` and `listing` bundles (one field storage, two field instances), storing the `PlaceIdentity` key a node came from — the node-level duplicate-detection mechanism the spec previously flagged as undesigned. `findExistingNodeId()` checks this before creating, and is PUBLIC (not just used internally) specifically so dry-run reporting can check existence without triggering a write — see "Production Field Deployment" section for why this changed.
- **Every created node is UNPUBLISHED.** Publishing is an editorial decision, not an import one.
- `field_poi_location` set via `CoordinateTransformer` (see below). `field_poi_address` populated only when `AddressVerifier` reports `STATUS_VERIFIED`; otherwise left empty, per the field ownership matrix's `SOURCE_ASSISTED_REVIEW` default.
- `field_canadian_towns` is set from the optional `$town_tid` argument (the town the import was searching; see "Town and Address Connection"). With no town passed, nothing is set. The node holds ONE term, the town; the province is that term's parent.
- **Real bug found and fixed:** a place with no `name` property (a real, common case for some OSM features — e.g. an unnamed school field or park segment) was being counted as a generic `error`. This is expected, not a failure. Now split into its own `no_name` outcome, both in `PoiNodeCreator`'s own result and in `PoiImportProcessor`'s aggregate counts.
- **Verified end-to-end:** Heritage Village created correctly (title, category via the mapper, transformed location, correctly-empty address). Re-running creation for the same place correctly returned `skipped_existing` with the same node ID — no duplicate. At real scale (Taber's 46 places): 10 correctly matched already-existing nodes, 1 correctly held for review, 28 correctly unmapped, 7 correctly separated as `no_name`, 0 genuine errors.

### `CoordinateTransformer` — Geoapify coordinates to Geolocation field value, built and verified

- Class: `Drupal\geoapify_importer\Service\CoordinateTransformer`. Service: `geoapify_importer.coordinate_transformer`. Shared infrastructure — POI's `field_poi_location` and Listing's `field_street_location` both use the Geolocation module.
- Converts Geoapify's GeoJSON `[longitude, latitude]` into the Geolocation module's `['lat' => ..., 'lng' => ...]` shape. Validates range and throws on a swapped-order input (a plausible-looking but wrong location otherwise).
- **Verified via an actual write**, not just inference from the field structure already confirmed on `canadian_towns` terms: a real test POI node's `field_poi_location` correctly computed `lat_sin`/`lat_cos`/`lng_rad` automatically on save, matching the shape seen on town terms.
- **Environment note:** `field_poi_location`, `field_poi_address`, `field_poi_category`, and `field_source_storage_key` all needed to be created on devtop via `drush field:create` as dev-only stand-ins for testing — none are synced copies of production's real field configuration. Still an open item.

### `PoiImportProcessor` — orchestrates mapping + creation, wired into `geoapify:import`

- Class: `Drupal\geoapify_importer\Service\Poi\PoiImportProcessor`. Service: `geoapify_importer.poi_import_processor`.
- Consumes `TownImportRunner`'s `processed` list (every place it saw, each with its classification result and `town_tid`, collected regardless of file-change status). For `pending_mapping` places that map to a term: creates a node if none exists, otherwise sends the existing node to `PoiNodeUpdater`; does nothing for `ignored`/`needs_review`.
- `geoapify:import` reports POI-stage results (created, updated, in sync, needs_review, unmapped, ignored, no-name, errors) alongside the ingestion stats, per town and in a final table, plus one line per updated node.
- A future Listing importer would reuse `TownImportRunner`'s same output with its own processor — this is why `TownImportRunner` itself has no taxonomy-mapping or node-creation knowledge.

## Alternative Data Source Evaluation — AnythingPOI (evaluated, REJECTED; staying on Geoapify)

A 5GB Canada-wide POI dataset (`anythingpoi_canada_v0.1`, Zenodo, DOI 10.5281/zenodo.20009008) was proposed as a replacement data source and evaluated in real depth before any code was changed.

**What it is:** 5,565,256 Canadian POIs, fusing OpenStreetMap and Overture Maps Foundation data via H3 spatial conflation + Jaro-Winkler name matching + confidence scoring. GeoParquet format (18 files, one per Tier-1 category), 18 Tier-1 / 196 Tier-2 taxonomy (completely different from Geoapify's 833-category system — would have required rebuilding `PoiCategoryClassifier`'s table from scratch). Published May 2026, single academic author, "Work in Progress" status, 36 downloads at time of evaluation.

**Format was NOT a real blocker, contrary to initial assessment:** a real, actively-maintained Composer package (`flow-php/parquet`, PHP 8.3–8.5 compatible) can read GeoParquet directly — no external Python/DuckDB conversion step needed, reversing an earlier incorrect claim that PHP had no way to read it.

**Licensing was researched in depth and is NOT a blocker, for either data source:** ODbL 1.0 (the same license both this dataset and Geoapify's underlying OSM data use) distinguishes a "Derivative Database" (redistributing the data itself — triggers share-alike) from a "Produced Work" (using the data to build a finished product like a website — does NOT, and can be licensed/sold however the builder wants). A Towns Canada website showing POI/Listing pages, even commercially, is a Produced Work. The one real, concrete requirement is visible attribution (OpenStreetMap + Overture, where applicable), and the one thing to actually avoid is ever letting the public bulk-export the raw underlying data itself. **Note: this dataset's own README overstates ODbL's restrictions** ("the derived work must also be released under ODbL") beyond what the actual license text says — confirmed by cross-referencing the OSM Foundation's own ODbL documentation directly, not by trusting either source's paraphrase.

**Real comparison performed:** both sources queried for the same ~15km radius around Taber, AB. AnythingPOI returned 582 real POIs vs. Geoapify's 46 (via the curated 39-category search) — genuinely richer raw coverage, with real phone/website/address data Geoapify's search alone didn't surface.

**Real problems found that led to rejection:**
- **Unmitigated duplicate records.** The same real place (e.g. Taber's irrigation museum, a Super 8 Motel) appeared as multiple separate, unconflated rows from different sources — the dataset's own stats confirm only 1.4% of records nationally are cross-source-matched. `PlaceIdentity`'s existing OSM-type+ID dedup would not catch this, since duplicates often have different underlying source IDs. Would have required building genuinely new fuzzy name+address+distance dedup logic before safe to import from.
- **Documentation didn't match the actual data, twice:** the README's stated `id` prefix convention (`at_` for Overture-sourced, `osm_` for OSM-only) didn't match sample data (OSM-only records still carried `at_` prefixes); the documented `confidence_score` baseline values (0.01 OSM-only / 0.50 Overture-only) didn't match real sampled scores (~0.71–0.75 across the board).
- A real tagging error was spotted directly in sampled data ("Hidden Spring Pet Resort" tagged `Community | Animal Shelter`).

**Decision:** stay on Geoapify. Addressed the actual underlying gap (sparse contact data) via Geoapify's own complementary Place Details API instead — see next section — rather than taking on a new, unproven data source's real duplicate-record problem.

## Rate Limiting and Enrichment — NEW, BUILT AND VERIFIED

### `GeoapifyRateLimiter` — global daily cap, enforced inside `GeoapifyClient`

- Class: `Drupal\geoapify_importer\Service\GeoapifyRateLimiter`. Service: `geoapify_importer.rate_limiter`.
- **Enforced inside `GeoapifyClient` itself**, once per outgoing request, across ALL four of its methods (`request()`/Places search, `reverseGeocode()`, `forwardGeocode()`, `placeDetails()`) — not scoped to any single feature. This was a deliberate design correction after an initial version scoped the limit to Place Details only, which would have missed the real risk: an unattended, cron-driven import could exhaust a day's actual Geoapify credit allowance through ordinary Places/geocoding calls alone, with no cap ever noticing.
- Tracked via a date-keyed Drupal State entry (`geoapify_importer.requests_YYYY-MM-DD`), resetting automatically at UTC midnight — no cleanup job needed.
- **Counts requests, not credits** (stated simplification): most Geoapify endpoints cost 1 credit per request, including Place Details' default `details` feature, so the two numbers are usually the same. Would under-count real cost if a future feature requests Place Details' radius/isoline place-count features, which can cost several credits per call. Not a concern today since only the default `details` feature is used.
- Config: `geoapify_rate_limit_enabled` (default TRUE), `geoapify_daily_request_limit` (default 2500 — chosen with headroom under the free plan's 3,000/day).
- **Verified directly and conclusively:** a plain Places search call (not Place Details) was correctly blocked once an artificially-low limit (2) was reached, with the counter incrementing correctly (1, then 2, then blocked) — proving the limit is genuinely global, not feature-scoped.

### `GeoapifyClient::placeDetails()` and `PlaceDetails` — enrichment, built and verified

- New method on `GeoapifyClient`, endpoint `https://api.geoapify.com/v2/place-details`. Looks up by `osm_type`+`osm_id` directly — the same data `PlaceIdentity` already derives for every stored place, so no separate Geoapify place_id lookup is needed.
- `PlaceDetails` (class `Drupal\geoapify_importer\Service\PlaceDetails`, service `geoapify_importer.place_details`) wraps this with just a feature on/off toggle (`place_details_enabled`, default FALSE) — actual throttling is the global rate limiter's job, not re-implemented here.
- Returns `NULL`, never throws, for every "can't enrich this one" case (disabled, rate-limited, no OSM reference, request failure) — enrichment is additive and optional by design; its absence never blocks a place from being created.
- **What it returns, confirmed from Geoapify's own documentation:** contact info (phone/email/fax), `opening_hours`, `wheelchair` and other facility booleans, ownership signals (`operator`, `operator_details.type`, `owner`, `owner_details.type`), category-specific detail (accommodation stars, historic period/civilization, artwork artist), Wikidata/Wikipedia references. Cost: 1 credit per call for the default `details` feature.
- **Verified against real production-scale data** (139 real POI nodes, spanning multiple towns): confirmed working correctly — real values returned for some places (e.g. a park's `operator: City of North Bay`, `wikidata: Q111311537` on another), `NULL`/empty for others. **Confirmed this reflects real OpenStreetMap tagging sparsity, not a broken lookup** — parks are typically thinly tagged (no reason for a volunteer to add phone/hours to something with neither), while businesses are typically richly tagged. This was directly verified, not assumed: the same run that returned mostly-empty results for several parks also returned real operator/wikidata values for others in the identical call pattern.
- **Noted but not yet built:** `operator`/`owner` fields are a real, if imperfect, signal that could help resolve the `needs_review` ownership-ambiguity cases (museums, zoos, the gray-area group) automatically in some cases, rather than always requiring manual review. Flagged as a future enhancement, not built.

## Change Detection — BUILT AND VERIFIED

- Class: `Drupal\geoapify_importer\Service\ChangeDetector`; service `geoapify_importer.change_detector` (no dependencies). Shared ingestion infrastructure, not POI-specific.
- `detect(array $incoming, ?array $stored): array` returns `status` (`new`, `unchanged` or `changed`) and `changed_fields` (field name mapped to old/new values).
- **Ordering constraint:** `detect()` must run BEFORE `SourceFileWriter::write()`, because `write()` archives and replaces `latest.json`; after a write, stored and incoming are identical.
- **Compared fields (hardcoded, by decision — not UI-configurable):** `properties.name`, `street`, `housenumber`, `postcode`, `city`, `county`, `state_code`, `formatted`, `address_line1`, `address_line2`, `website`; `categories`; coordinates. Everything else, including rank/popularity scores and key order, is ignored as noise.
- **Categories** are compared order-insensitively.
- **Coordinates** are compared by distance moved (haversine), with a 50 m tolerance (`COORDINATE_TOLERANCE_METERS`). Reason: an earlier version rounded to 7 decimal places (about 1 cm) and reported Heritage Village as `changed: coordinates` on a re-fetch with a different query, when nothing had changed. The measured shift was 15.2 m for that building outline and effectively 0 m for a single-point node. **The 50 m value is a judgment call based on one measured data point.** Large outlines such as parks and lakes may shift more; tune from real data.
- **Verified:** synthetic new / unchanged / changed cases; live re-fetches reported unchanged; the same places re-fetched under a different category and radius reported unchanged (no false coordinate change); a synthetic 11 m shift reported unchanged; a synthetic 222 m shift reported changed (222.4 m).
- **Not verified:** behaviour at scale, or with large polygon features.
- **Implication for future sync:** `field_poi_location` is SOURCE_AUTHORITATIVE in the ownership matrix. Node-update logic must consult `ChangeDetector` before writing, not overwrite blindly, or the same jitter would rewrite saved locations.

## Town Search Area — NEW: BOUNDARY VS. CIRCLE

**Finding:** a fixed-radius circle around a town's centre point is a measurably poor proxy for its real shape. Confirmed on Calgary — bounding box roughly 32km x 41km, already larger than a 20km-radius (40km diameter) circle in one dimension. A direct comparison of `commercial.supermarket` results (circle vs. Calgary's real administrative boundary) found 199 vs. 198 total, with 5 places disagreeing: 3 caught by the circle but outside the real boundary, 2 inside the real boundary but outside the circle's reach. Small for Calgary specifically; likely larger for a town spread out lengthwise (project owner named Fort McMurray itself, plus outlying communities like Anzac and Gregoire, as a concrete concern — not yet tested, since Fort McMurray's compact `entertainment` category result set didn't expose the shape difference the way Calgary's spread-out supermarkets did).

**`canadian_towns` taxonomy, clarified this pass:**
- Province-level parent terms exist above town terms (e.g. "Alberta"), which is why an early, unfiltered query returned a term with no coordinates.
- `field_geolocation` (Geolocation module) stores `lat`/`lng` as plain decimal degrees, plus module-computed `lat_sin`/`lat_cos`/`lng_rad`/`value`. No transform needed to read a town's coordinates (unlike writing to POI's `field_poi_location`, which still needs one).
- `field_type` holds real designations: Locality, Hamlet, Village, Town, City (confirmed via real production data). Not yet used by any code; a plausible future use is skipping boundary resolution entirely for `Locality`, which likely has no formal administrative polygon.
- Whether Anzac/Gregoire are their own `canadian_towns` terms, or expected to be covered under Fort McMurray, was raised but **not yet checked**.

**`GeoapifyClient::forwardGeocode()`** — new method, Forward Geocoding API (`https://api.geoapify.com/v1/geocode/search`). Resolves free text (a town name) to a place, used specifically to find a town's administrative boundary.

**`TownBoundaryResolver`** — new service, `geoapify_importer.town_boundary_resolver`. Lives directly under `Service/` (shared infrastructure, not POI-specific).
- `resolve(int $tid, string $town_name, ?string $province_code, float $known_lat, float $known_lon): ?string` — returns a Geoapify place_id suitable for `filter=place:{id}`, or NULL if no sufficiently confident, geographically plausible boundary could be resolved. A candidate more than 50 km from the town's own known coordinates is rejected as a likely same-named town elsewhere.
- Caches successful resolutions to `private://geoapify_importer/towns/{tid}/boundary.json` (atomic write, same pattern as `SourceFileWriter`). Does not cache failures.
- **Confidence threshold:** requires `match_type='full_match'` AND `confidence=1`, together. **Verified as necessary, not just cautious**, via a real case: Tadmore, BC (a Hamlet) returned `full_match` but `confidence=0`, and was correctly rejected only because both conditions are required jointly — `match_type` alone would have wrongly accepted it. Tahsis, BC (Village) and Taber, AB (Town) both resolved correctly at this threshold, alongside the earlier Fort McMurray and Calgary results.
- The resolver does not perform the circle fallback itself; `TownImportRunner` does (a 15 km circle around the town's `field_geolocation` when `resolve()` returns NULL). Built and verified at real scale (see `geoapify:import`).
- **Not yet tested:** a town name colliding with a larger, more famous place elsewhere; a town entirely absent from OpenStreetMap's data.

## Dev Tooling — NEW THIS SESSION

### `drush geoapify:fetch` — Drush command, built and verified

- File: `src/Drush/Commands/GeoapifyImporterCommands.php`.
- Discovered automatically by Drush 13 via `ContainerInjectionInterface` (no `drush.services.yml` needed).
- Usage: `drush geoapify:fetch <count> [--categories=] [--lat=] [--lon=] [--radius=]`.
- Defaults: `categories=entertainment,tourism`, Fort McMurray-area center, 15km radius.
- `count` is clamped to Geoapify's real documented range (1–500), with a warning if adjusted, rather than erroring.
- For each fetched place: derives the storage key via `PlaceIdentity`, runs `ChangeDetector` against `SourceFileWriter::readLatest()`, skips unchanged places (no write, no new history file), writes new or changed ones via `SourceFileWriter::write()`, and runs `PoiCategoryClassifier` for a Classification column (reported for every place, regardless of write action — classification never gates a write).
- Output: a table (Name / Status / Action / Classification / Storage Key; changed rows list which fields changed) and a summary line such as `0 new, 0 changed, 7 unchanged (skipped), 0 error(s) out of 7 fetched place(s).` A failure on one place becomes an `error` row and does not abort the batch.
- **Scope, explicitly narrow:** this is a manual dev/test tool for exercising fetch+store+classify against ONE fixed search area at a time — it does NOT map to taxonomy terms, create nodes, or loop over towns. The eventual full `geoapify:import` command (per original spec) is separate, not-yet-built, and will layer per-town looping (see Town Search Area below), taxonomy mapping, and node-creation on top of what this command already proves works.
- **Live-tested at `limit=20`** (returned 7 real features — see Classification Engine discovery above).
- **Default `categories=entertainment,tourism` is suspect** because of the multi-category open item under "Places API".

## Ingestion / Synchronization Design (mostly unchanged — not yet implemented)

- Not yet built: Drupal cron / Queue API wiring (unattended operation), `geoapify:check-updates`, `geoapify:status`, pagination, review workflows. Built: change detection, node-level duplicate detection, global rate limiting, `geoapify:import`.
- `geoapify:fetch` (above) is a new, real, narrower precursor to `geoapify:import` — not a replacement for it.

## Record Identity / Duplicate Detection — CORRECTED

**Primary identity: OpenStreetMap type and ID**, from `properties.datasource.raw.osm_type` (`n`, `w` or `r`) and `.osm_id`, implemented by `PlaceIdentity::keyFor()` as the storage key `osm-{type}-{id}` (for example `osm-w-306707925`).

**Why not Geoapify's `place_id`** (the original spec's preferred identity). It was tested and found unstable:

- Decoding a `place_id` shows it begins with the feature's longitude and latitude as binary doubles; the trailing bytes encode the OSM ID and the place name.
- The same place, fetched by two different queries, produced different `place_id` strings. Heritage Village (`w306707925`) differed in position by 15.2 m between a museum-only query and a broader entertainment query; Marine Park Museum (`n4261576691`) differed by about 1 mm of float noise. The OSM ID and name bytes were identical each time.
- Consequence: the same place was stored twice under two directories until the storage key was changed. The cause of the coordinate variation between queries is unknown and has not been verified.
- At the time of the check, all 16 stored features had an OSM reference (0 without). That is a small sample from one area.

**Fallback:** a feature with no usable OSM reference uses `gid-{place_id}`. The distinct prefix makes these easy to find. The fallback inherits the instability above, so those records would be at risk of duplication. Not yet observed in practice.

**Known limitation:** if an OSM object is deleted and recreated it gets a new ID and will look like a new place. The fallback duplicate detection the original spec called for (coordinates; normalized name plus geographic context) is the intended safety net and is not yet implemented.

**Node-level duplicate detection (built):** each node stores the storage key it came from in `field_source_storage_key` (one field storage shared by `point_of_interest` and `listing`); `PoiNodeCreator::findExistingNodeId()` checks it before creating, and `PoiImportProcessor` routes existing nodes to `PoiNodeUpdater`.

## Import Statuses (unchanged, not yet implemented)

`NEW`, `IMPORTED`, `UNCHANGED`, `CHANGED`, `NEEDS_REVIEW`, `IGNORED`, `ERROR`, `SOURCE_UPDATED`. `IGNORED` now has a concrete, coded first implementation via `PoiCategoryClassifier`, though not yet wired into a persisted per-record status. `ChangeDetector` returns lowercase `new` / `unchanged` / `changed`, which correspond to NEW / UNCHANGED / CHANGED here; `geoapify:fetch` reports them, but nothing persists a per-record status yet.

## Field Ownership Matrix (unchanged from prior revision — see that document for the full table)

POI's matrix (title, location, category, description, etc.) stands as previously defined. **Not yet extended to Listing** — Listing's real field structure is now known (this session), but its own field-ownership matrix (which Listing fields are source-controlled vs. business-owner-controlled vs. platform-controlled-paid-features) has not yet been designed.

## Development Rules (reaffirmed, with one addition this session)

All prior rules stand. Additionally: **when local and production may structurally differ (taxonomy content, field structure, private file paths), verify against production directly (read-only, via SSH/Drush) rather than assuming local is representative.** This session's production research uncovered a materially more developed Listing content type than assumed, and confirmed a real local/production taxonomy divergence that directly changed the classification engine's design (static config rejected in favor of dynamic taxonomy-field mapping).

Two further rules from this revision:

- **Do not treat an external identifier as stable or unique until it has been tested across at least two differing requests.** The Geoapify `place_id` was assumed stable and was not; the error surfaced only when the same place was fetched by two different queries.
- **Keep directory names and namespace segments identical in case.** macOS hides mismatches that Linux production will not.

## Node Updates — BUILT AND VERIFIED

- Class `Drupal\geoapify_importer\Service\Poi\PoiNodeUpdater`; service `geoapify_importer.poi_node_updater` (arguments: `@entity_type.manager`, `@geoapify_importer.coordinate_transformer`, `@datetime.time`, `@logger.channel.geoapify_importer`).
- Brings an EXISTING POI node up to date from a fresh Geoapify feature, applying the field ownership matrix per field:
  - Title: never touched (editors rename places).
  - `field_poi_location`: re-synced only when the place has moved more than 50 m (same tolerance as `ChangeDetector`).
  - `field_poi_category`: filled only if empty; an existing value, especially an editor's, is never overwritten.
  - `field_canadian_towns`: filled only if empty, from the town the import found the place under.
  - `field_poi_address`: filled only if empty and the Places data itself has a house number and street. It deliberately does NOT use the reverse-geocode fallback: on update runs that would spend a metered request per run for every place that will never have an address.
  - Everything else (description, hero image, meta description, tags, publish status): never touched.
- Every real change is saved as a NEW REVISION with the log message "Updated by Geoapify Importer: <fields>", so editors can see and revert what the importer did.
- `PoiImportProcessor` sends a place whose node already exists (found via `field_source_storage_key`) to the updater, in dry runs too (the updater is told not to save, so a dry run reports exactly what a real run would do). Counts: `nodes_created`, `nodes_updated`, `nodes_unchanged` (replacing `nodes_skipped_existing`), `no_name`, and so on. `geoapify:import` prints one line per updated node.
- LIMITATION: only places that currently classify as `pending_mapping` and map to a term are updated; a node whose place has since become `needs_review` or unmapped is left alone.
- **Verified on Taber (real devtop data):** the dry run reported 10 nodes in sync. After deliberately moving one node's location about 500 m and renaming it as an editor would, and clearing another's category, the dry run reported exactly those two changes without saving; the real run applied both; the editor's title survived; each change appeared as a revision with the log message; a repeat run reported 0 updates. Also checked in a sandbox with stand-ins for Drupal's node classes (33 checks: tolerance, never-overwrite rules, a dry run saves nothing, bad input). The real save and revision calls were exercised only by the Taber run.

## Town and Address Connection — DECIDED, BUILT, AND VERIFIED ON BOUNDARY SEARCHES

**Production field facts (read-only inspection):**
- `field_canadian_towns` (POI and Listing): entity reference to the `canadian_towns` vocabulary, cardinality 1, not required. The node therefore holds ONE term, the town; the province is that term's parent (the vocabulary is one level: province, then town). Example: Taber (type Town, `field_province_code` AB) has the single parent Alberta. Term IDs differ between production and devtop (Taber is 163693 on production, 5733 on devtop).
- `field_poi_address` (POI) and `field_street_address` (Listing): Address module, Canada only, cardinality 1. Address line 1 and postal code are required once an address is entered; locality, province, address lines 2 and 3, dependent locality, sorting code, organization and the name fields are hidden. The POI field is optional; the Listing field is required.

**What the devtop data showed** (1,115 stored places; 257 of them would become nodes, meaning `pending_mapping` and mapped):
- Address: none of the 257 has both a house number and a street (7 have a house number, none has a street; most are parks). Across all 1,115 places: 150 have a house number, 699 a street and 738 a postcode; 139 have house number plus street, and all 139 also have a valid Canadian postcode. The 139 existing nodes have no addresses.
- Town by name: matching a place's Geoapify `city` and `state_code` to a town term (name plus province code, town-level terms only) matched only 29 of the 257 (11%); 216 matched nothing and 12 had no city or province. Some misses are naming differences ("Town of Trenton", "Terrace Bay Township", "East Ferris Township"); for plain names such as Brantford, North Bay and Hamilton the cause was not determined (devtop's taxonomy differs from production's, so this may be devtop-specific). Conclusion: name matching is not a reliable way to find a place's town.
- Place Details (Marine Park Museum, one place): returns street, city, province code, postcode, county, suburb and formatted address, but no house number; the same street and postcode were already in the Places record. No gain for addresses on that sample.

**Decision:** the town is the one the import was searching when it found the place: boundary search first, 15 km circle fallback, and the fallback ALSO assigns the town. It is decided once, in the shared `TownImportRunner`, and applied by each content type's creator and updater, so Listings reuse it. No name matching and no new service. Where search areas overlap, the first town to reach a place wins (the town is only filled when empty).

**Built (saved on devtop after commit 085e15d; not yet committed):**
- `TownImportRunner`: each place handed over carries `town_tid`.
- `PoiImportProcessor`: passes `$entry['town_tid']` to the creator and updater.
- `PoiNodeCreator::create(..., ?int $town_tid = NULL)`: sets `field_canadian_towns` when given.
- `PoiNodeUpdater::update(..., bool $dry_run = FALSE, ?int $town_tid = NULL)`: fills it only if empty.
- Sandbox checks (stand-ins) pass, and the change set is verified on real Taber nodes (below).

**RESOLVED:** devtop's `point_of_interest` type had no `field_canadian_towns`, so the first Taber dry run after these changes reported 0 updates. The field comparison showed the storage on devtop was already an entity reference, as on production (the earlier report that it was a geolocation field was wrong), so the field was attached to POI by the field-matching script (see "Devtop vs Production Field Comparison"). **Verified on Taber:** the dry run then listed the 10 existing nodes as `would update ... field_canadian_towns`; the real run connected all 10 to the town Taber; a repeat run reported 0 updated and 10 in sync; reading a node back gave town Taber and province Alberta (the town term's parent). All 10 came from a boundary search; the 15 km circle fallback has not yet been exercised on real data.

**Built, then dropped (recorded so they are not rebuilt):**
- `PlaceAddress` (town matching by name, province and distance, plus an address-value builder with a Canadian postcode check): dropped. The town matching is unnecessary and unreliable (above), and the address builder is not needed because no node-eligible place has a street. For Listings, businesses do have addresses, so a shared builder with a postcode guard may be worth building then: the Address field requires a postal code once an address is entered, so an address without one would probably block an editor's save.
- `GeoPoint` (one shared distance and circle-filter helper): set aside by the project owner. The distance formula still exists separately in `ChangeDetector`, `TownBoundaryResolver` and `PoiNodeUpdater`; consolidation is not scheduled.

**Known issue, not fixed:** `PoiNodeCreator` builds the address from the Places data's house number and street even when the address was verified through the reverse-geocode fallback, where those fields would not be present. It only matters if `reverse_geocode_enabled` is turned on (default off).

**Spec gap corrected:** the boundary-then-circle plan had been recorded only for the search area; the link from the search area to the node's town was not recorded until this revision.

## Working Agreements

- Check this spec and the repository before proposing work. Do not add code that the data or the repository do not show is needed; use read-only `drush php:eval` checks to find out first.
- One step per message: one file or one command, then wait for the output before the next. Deliver whole files, not fragments.
- Shared logic belongs in shared infrastructure (`src/Service/`, not `Poi/`) so Listings can reuse it. Decide where logic lives before writing it.
- Confirm a file is actually in place on the server (`ls`, `php -l`, a `grep -c` for a known string) before testing anything that depends on it.

## POI Detail Fields — BUILT AND VERIFIED

**Fields on `point_of_interest`** (created by `_geoapify_importer_create_detail_fields()`, called from `hook_install` and `hook_update_10002`; idempotent; skipped if the POI type or, for amenities, the `poi_amenities` vocabulary is missing):

| Field | Type | Holds |
|---|---|---|
| `field_poi_website` | link (external only, no title) | website |
| `field_poi_phone` | telephone | phone |
| `field_poi_email` | email | email |
| `field_poi_opening_hours` | long plain text | the raw OpenStreetMap hours string |
| `field_poi_operator` | plain text (255) | who runs the place |
| `field_poi_amenities` | entity reference to `poi_amenities`, unlimited | amenity terms |

Plus `field_geoapify_facilities` (plain text, unlimited) on the `poi_amenities` terms: the Geoapify facility names that mean a place has that amenity.

**Where the data comes from:** the stored Places records already carry these values wherever OpenStreetMap has them, so no extra API call is made and no Place Details credit is spent. Scan of devtop's stored places:
- All 1,115 stored places: website 42, phone 18, email 3, opening hours 18, operator 41 (identical whether read from the field or the raw OSM tag), at least one facility 12.
- The 257 that would become nodes: website 4, phone 1, email 1, opening hours 0, operator 7, facility 1. OpenStreetMap is thin on these for parks.
- Facility values seen: wheelchair true (9), toilets true (3) and false (2), air conditioning true (2), internet access true (1) and false (1), dogs true (1), and a `wheelchair_details` object (1).
- All 42 stored websites passed validation.

**`PlaceInfo`** (`src/Service/PlaceInfo.php`, service `geoapify_importer.place_info`, shared so Listings can use it): `fromProperties()` returns only usable values: a website only if it starts with http(s) and validates, the first phone and first valid email of several, the raw hours string, the operator (cut to 255), and the facilities whose value is exactly true. `amenityTermIds()` returns the terms in a vocabulary whose mapping field lists any of those facilities (no term IDs in code; returns nothing if the vocabulary lacks the field).

**Wiring and ownership:** `PoiImportProcessor` (which owns the POI field names) builds a field-name-to-value list and passes it to the creator and updater. `PoiNodeCreator` sets them on a new node. `PoiNodeUpdater` fills each only when the node has that field and it is empty, so an editor's value is never overwritten; it never replaces a managed field. A dry run reports the same fills without saving.

**Amenity mapping (data, per environment):** on devtop, Wheelchair Accessible = `wheelchair`, WiFi = `internet_access`, Pet Friendly = `dogs`. Parking is not a Geoapify facility, so it stays manual. Production's mappings are NOT yet entered.

**Opening hours decision:** Geoapify returns hours as a plain string in OpenStreetMap's own syntax. The Office Hours module (8.x-1.29, enabled on devtop and production, supports Drupal 11) stores structured weekday slots with exceptions, seasons, an open-now indicator and schema.org markup. For POIs that would mean parsing OSM syntax, which can include public holidays and sunrise-based times that weekday slots cannot hold, for data that is almost always absent. So POIs keep the raw string in plain text, and Office Hours is for Listings, where owners enter hours themselves. The Listing Office Hours field is not created yet.

**Verified on devtop:**
- A dry run on Taber reported 10 nodes in sync (none of Taber's places has details).
- Sending the 90 stored places that have details through the real processor as a dry run reported 8 existing nodes to update, none to create (24 needs_review, 57 unmapped, 1 ignored).
- The real run updated those 8 with 0 errors. Brant Park (node 1426) got website, phone and email; Smelt Brook Park (node 1364) got an operator and the Pet Friendly amenity (from the `dogs` facility). Each change is a revision with the log "Updated by Geoapify Importer: <fields>".
- Data quirk, not a bug: Smelt Brook's operator reads "Twon of Trenton"; that typo is in OpenStreetMap and an editor can correct it (the importer will not overwrite it).

**Vocabularies:** production has `amenities` (Listing Amenities, 12 terms) and `poi_amenities` (POI Amenities, 4 terms: Parking, Pet Friendly, Wheelchair Accessible, WiFi), with no custom fields. Devtop's `poi_amenities` matched; devtop's `amenities` held 1,823 terms with numbers as names, which were removed and replaced with production's 12.

**Not done:** the new fields are not on any form or view display; nothing formats the raw hours string for display; the `PlaceDetails` service (paid Place Details call) is unused by the importer.

## Production Field Deployment — NEW, BUILT (not yet applied to production)

**Update — what the module creates on install:** the fields it OWNS: `field_source_storage_key` (POI and Listing) and `field_geoapify_categories` (via `config/install`, plus `hook_update_10001` for existing sites), and the seven detail fields (via `hook_install` and `hook_update_10002`). It does NOT create the fields it only DEPENDS ON (`field_poi_location`, `field_poi_address`, `field_poi_category`, `field_canadian_towns`), because they predate it and carry site-specific settings. `hook_requirements()` checks the first three and reports an error if one is missing; **it does not yet check `field_canadian_towns`**, so on a site without it the town would silently not be set. Open decision: whether the install should also create those four when absent (never altering one that exists).

**Two different treatments for two different kinds of fields, deliberately:**

- **Fields this module OWNS** (`field_source_storage_key`, `field_geoapify_categories`) — shipped as real `config/install/` YAML (field storage + field instance definitions, standard Drupal pattern), so they install automatically and identically on any fresh install of this module. Since `config/install` does NOT retroactively apply to an already-installed site, `geoapify_importer.install` also ships `hook_update_10001()`, which creates both fields programmatically (idempotent — checks existence before creating) for sites where the module is already installed, run via `drush updatedb`.
- **Fields this module DEPENDS ON but does NOT own** (`field_poi_location`, `field_poi_address`, `field_poi_category` — pre-existing on `point_of_interest`, created independently before this module existed) — deliberately NOT bundled into `config/install`. Bundling a guessed-at definition risks either an install failure (config name collision with what already exists) or silently installing a narrower version that loses real settings on a site where the field is already correctly configured. Instead, `hook_requirements()` checks these exist and fails loudly and specifically (visible at `/admin/reports/status`) if any are missing, rather than letting the module appear to install successfully while actually broken.

**`field_source_storage_key` is intentionally ONE field shared across TWO bundles** (`point_of_interest` and `listing`), not two separate fields — it's a node-level concept ("which geoapify_importer place produced this node"), not POI-specific, matching the spec's own architecture goal that a future Listing importer should reuse `TownImportRunner`'s output. Attaching the instance to `listing` now, ahead of any Listing-side mapper/creator existing, costs nothing and avoids a second migration later. **Nothing currently writes to it on the Listing side** — `PoiNodeCreator` only ever creates `point_of_interest` nodes; this is groundwork, not active functionality yet.

**`field_geoapify_categories` was NOT extended to Listing's own category vocabulary** (`listing_category`) — that depends on a Listing-side mapper that doesn't exist yet, and would be speculative to build ahead of it.

**Status: built, verified on devtop via `drush updatedb`, NOT YET applied to production.** This is real, necessary groundwork for eventual production deployment, but production itself has not been touched.

## Original Specification Details (restored)

These parts of the original specification were dropped when this document was first rewritten (the initial mapping table was replaced by a "not reproduced here" note). They are restored here from the original document. Status notes mark where later decisions supersede them.

### POI Address (original)

- Field: `field_poi_address`; storage `node.field_poi_address`; type `address`; module `address`.
- Current Canadian configuration:
  - Canada available.
  - Address line 1 required.
  - Postal code required.
  - Locality is hidden, because town/province relationships are represented through the Canadian Towns taxonomy.
  - Other personal-name/address components are hidden as configured.
- *Status:* confirmed by a read-only inspection of production's field settings (also administrative area, address lines 2 and 3, dependent locality, sorting code and organization are hidden).

### POI Taxonomy (original)

- The POI Category taxonomy contains 72 terms: 8 parent categories and 64 child categories.
- Parent categories: Arts & Attractions, History & Heritage, Landmarks & Structures, Nature & Landscapes, Parks, Religious & Sacred Places, Trails & Routes, Unusual & Quirky.
- The taxonomy is user-facing and editorial. Do not treat taxonomy term IDs as permanent mapping logic. Geoapify category mapping must be data-driven and should use stable taxonomy identifiers such as vocabulary/term UUIDs, or another maintainable configuration strategy where appropriate.
- Schema.org mapping is a separate concern from the editorial taxonomy.
- *Status:* the 72-term structure has not been re-verified since the original; devtop's and production's taxonomy content differ. Mapping is now done by tagging terms with `field_geoapify_categories` (see "`PoiCategoryMapper`").

### Initial Geoapify Mapping Direction (original — historical starting point)

These mappings were a starting point and were to be verified against Geoapify's category documentation before being treated as authoritative:

```
entertainment.museum              -> History & Heritage / Museums
entertainment.aquarium            -> Arts & Attractions / Aquariums
entertainment.zoo                 -> Arts & Attractions / Zoos & Wildlife Attractions
entertainment.theme_park          -> Arts & Attractions / Amusement Parks
entertainment.culture.arts_centre -> Arts & Attractions / Cultural Centres
entertainment.culture.gallery     -> Arts & Attractions / Galleries
entertainment.culture.theatre     -> Arts & Attractions / Theatres
leisure.playground                -> Parks / Playgrounds
leisure.park.garden               -> Parks / Gardens
leisure.park.nature_reserve       -> Nature & Landscapes / Wildlife Areas or review
leisure.park                      -> Parks / conditional
man_made.bridge                   -> Landmarks & Structures / Bridges
man_made.lighthouse               -> Landmarks & Structures / Lighthouses
man_made.tower                    -> Landmarks & Structures / Towers & Observation Structures
man_made.pier                     -> Landmarks & Structures / Wharves & Piers
waterway.channels                 -> Landmarks & Structures / Canals & Locks
natural.mountain                  -> Nature & Landscapes / Mountains
natural.mountain.cave_entrance    -> Nature & Landscapes / Caves
natural.mountain.cliff            -> Nature & Landscapes / Geologic Features
natural.forest                    -> Nature & Landscapes / Forests
heritage.unesco                   -> History & Heritage / conditional
```

- Mappings were to support at least DIRECT, CONDITIONAL, IGNORE and UNMAPPED. Unmapped source categories must not silently become arbitrary POI categories.
- *Status:* all 21 category strings exist in Geoapify's live category list (pulled earlier, 833 categories). The table is superseded in two ways: (1) `PoiCategoryClassifier` now sends museum, zoo, aquarium, theme park, arts centre, gallery and theatre to `needs_review` (ownership decides), and commercial categories to `ignored`; (2) a static table was replaced by dynamic tagging of `poi_category` terms. Only DIRECT is implemented; CONDITIONAL is not. The table remains a useful guide to which term each category should be tagged to.

### Record Identity rules (original)

- Duplicate detection must be deterministic and logged.
- Do not create duplicate Drupal POIs merely because a source response was re-imported.
- *Status:* the preferred identity was originally Geoapify's place ID; that was disproved and replaced (see "Record Identity / Duplicate Detection").

### Architecture Goal (original)

Shared infrastructure should eventually include: API client, raw source-file storage, source record reader, validation, classification, mapping, duplicate detection, change detection, logging, queue processing and import status tracking. POI-specific mapping stays separate from shared ingestion infrastructure, so a future Listing importer can reuse the common framework without POI-specific rules being forced onto Listings.

### Development Rules (original, complete list)

- Work locally unless explicitly instructed otherwise.
- Never assume a `web/` directory exists.
- Never modify production while developing.
- Verify Drupal paths before giving commands.
- Use one controlled implementation step at a time.
- Verify each step before proceeding.
- Do not claim a Drupal, YAML or PHP behavior without verification.
- Preserve working code unless a change is necessary.
- Prefer non-destructive changes.
- Do not create SQL storage for raw source data.
- Do not put persistent raw source files inside the module directory.
- Do not hardcode taxonomy term IDs into business logic when a maintainable mapping mechanism can be used.
- Do not build the entire importer in one step.
- Separate verified facts from proposed design.
- When an assumption is required, identify it as an assumption and verify it before relying on it.

## Devtop vs Production Field Comparison

A read-only command (type, cardinality, required, and a fingerprint of the storage and field settings) was run on both environments. Display configuration (form and view modes) was not compared. Devtop was then brought in line for the 8 field objects below without hand-creating fields: a script with production's configuration (read with a read-only command) built in, run as a dry run first and then applied, after a database backup (saved one level above `public_html`, outside the web root). It never deletes anything, and leaves alone any field storage that exists but is configured differently. The re-check afterwards reported all 8 matching. Objects: storages `field_poi_tags` and `field_meta_description`; instances `field_canadian_towns`, `field_poi_tags` and `field_meta_description` on `point_of_interest`, `field_meta_description` on `listing`, and updated settings for `field_poi_address` and `field_poi_category`. Still different, by decision: the `canadian_towns` term fields (production has over 26,000 terms; devtop is a test set), form and view display settings, and fields that exist only on devtop.

- **`node.point_of_interest`:** devtop is missing `field_canadian_towns`, `field_poi_tags` and `field_meta_description`. `field_poi_address` and `field_poi_category` have the same storage but different field settings (created by hand with defaults). Devtop only: `field_location` (geofield) and the module's `field_source_storage_key`. `field_description`, `field_hero_image` and `field_poi_location` match.
- **`node.listing`:** devtop is missing `field_meta_description`. Devtop only: `field_final_score`, `field_local_employees`, `field_percent_sourced_locally`, `field_total_employees` and `field_source_storage_key`.
- **`taxonomy_term.poi_category`:** matches, apart from the module's `field_geoapify_categories` on devtop.
- **`taxonomy_term.canadian_towns`:** devtop is missing `field_description`, `field_hero_image`, `field_listing_category` and `field_meta_description`; it has `field_postcode_area` where production has `field_postalcode_area`; `field_city_id`, `field_county`, `field_province_code` and `field_type` have different storage settings.
- Devtop-only fields are to be left alone unless the project owner says otherwise.

## Current Verified State

### Verified, this stretch (in addition to everything verified previously)

- **POI detail fields** created on devtop by `hook_update_10002`, filled from the stored place data through the real processor on 8 existing nodes with 0 errors and a revision for each (see "POI Detail Fields").
- Devtop's amenity vocabularies matched to production's.
- **Node update logic** (`PoiNodeUpdater`) verified on real Taber data: location re-synced only past 50 m, category filled only when empty, an editor's title preserved, every change saved as a revision, a repeat run reporting 0 updates, and dry run reporting exactly what a real run does.
- **Data scan of 1,115 stored places** (257 node-eligible): address and town-by-name findings recorded under "Town and Address Connection".
- **Repository scan** (commit 085e15d): confirmed address building already existed in both the creator and the updater, that nothing set `field_canadian_towns`, and that the import already knows each place's town.
- **Production field configuration** for `field_canadian_towns` and the address fields inspected and recorded.
- **Town connection** verified on real Taber nodes (10 connected, repeat run stable, province reachable through the town's parent), and **devtop's POI and Listing fields matched to production's** for the 8 differing objects (re-check: 8 of 8 matching).
- **Full import pipeline complete end-to-end, at real scale:** `PoiCategoryMapper` (DIRECT mapping, live-tagged taxonomy), `PoiNodeCreator` (create-only, unpublished nodes), `CoordinateTransformer` (verified via actual write), `PoiImportProcessor` (orchestration) — all built, individually verified, and wired into `geoapify:import`. Real current scale: 139 POI nodes created, 1,115 raw places stored, across multiple towns.
- A real node-creation bug found and fixed: "no usable name" was miscounted as a generic error — now split into its own `no_name` outcome.
- `field_geoapify_categories`, `field_source_storage_key` added (dev-only stand-ins on devtop) and confirmed working for real mapping/dedup.
- **AnythingPOI (external 5GB dataset) evaluated in full and rejected** in favor of staying on Geoapify — real duplicate-record problem found, README-vs-data mismatches found twice, ODbL licensing researched in depth and confirmed NOT a blocker for either source.
- **Global `GeoapifyRateLimiter` built and verified**, enforced inside `GeoapifyClient` across all four of its methods — confirmed via a real test that a plain Places search call (not just Place Details) is correctly blocked once the daily cap is reached.
- **`GeoapifyClient::placeDetails()` and `PlaceDetails` enrichment built and verified** against real production-scale data (139 nodes) — confirmed the sparse-vs-rich data pattern reflects real OSM tagging behavior, not a broken lookup.
- Reverse-geocode fallback corrected to use real, confirmed signal (housenumber+street), replacing an invalid design based on fields the API doesn't return
- `PoiCategoryClassifier` expanded to three outcomes (ignored/needs_review/pending_mapping) with all business-model category decisions coded and verified against real data; a real `building`-tag misclassification bug found and fixed
- `TownBoundaryResolver` built and verified, including a real name-collision mitigation (plausibility distance check against a town's own known coordinates) — verified via a real deliberate-mismatch test (Taber's identity + Halifax's coordinates, correctly rejected at 3,646.6 km)
- `TownImportRunner` + `geoapify:import` built and verified at real scale (Taber's full 46 places, then multiple additional towns): per-town boundary/circle search, category-by-category fetch (avoiding the unexplained multi-category anomaly), a real `natural` category flooding bug found and fixed (narrowed to `natural.mountain`/`natural.forest`, matching the original spec's own mapping table)
- `canadian_towns` taxonomy structure clarified: province-parent terms, `field_geolocation` structure, `field_type` designations (Locality/Hamlet/Village/Town/City)
- Production's real field structures for `point_of_interest`, `listing`, and `canadian_towns` confirmed via direct, read-only SSH/Drush inspection
- Local vs. production taxonomy divergence confirmed as real and structural, directly shaping the classification/mapping design toward dynamic taxonomy-field-driven lookups over static config
- Geoapify `place_id` shown to be unstable across requests; `PlaceIdentity` (OSM type and ID) built and verified
- `ChangeDetector` built and verified (new/unchanged/changed; 50 m coordinate tolerance, verified necessary via a real false-positive)
- Two real devtop environment bugs found and fixed: PHP CLI resolving to 8.3.33 despite Herd's global 8.4 setting (fixed via `herd use 8.4`); `field_poi_address`'s database table missing a column vs. the installed Address module's schema (fixed via direct `ALTER TABLE`)

### Not yet implemented / not yet resolved

- **Production field deployment is BUILT but NOT YET APPLIED** — `config/install` YAML + `hook_update_10001()` exist and are verified working on devtop, but production itself has not had this module's fields deployed. This is the real, concrete next step before production use.
- Cron/queue wiring was never built despite being explicitly requested ("let it work away") — `geoapify:import` still requires manual invocation
- `field_canadian_towns`: built and verified on boundary searches; not yet exercised on the circle fallback. The four changed files (`TownImportRunner`, `PoiImportProcessor`, `PoiNodeCreator`, `PoiNodeUpdater`) are saved on devtop; their commit is pending.
- Cron/queue wiring for unattended operation — `geoapify:import` still requires manual invocation; the spec's original plan for Drupal cron/Queue API integration is unbuilt
- Whether Anzac/Gregoire are their own `canadian_towns` terms or expected to be covered under Fort McMurray — raised, not checked
- Whether `field_type` (Locality/Hamlet/Village/Town/City) should skip boundary resolution for `Locality` terms — plausible, not decided or built
- Whether/how a POI can be "upgraded" to a Listing (Option 1 vs. Option 2 from the business-model discussion) — not finalized, not built
- Listing's own field-ownership matrix; Listing-side mapper/creator (would reuse `TownImportRunner`'s output, per its own design)
- `field_is_paid` (or equivalent) flag/mechanism gating Listing's paid components
- Production private-filesystem path naming confirmation (`/home/townscanada/backup`)
- **Production deployment of ALL dev-only fields** (`field_poi_location`, `field_poi_address`, `field_poi_category`, `field_source_storage_key`, `field_geoapify_categories`) — none are synced copies of production's real field configuration; this is a real, necessary step before any production deployment
- Confirmation of whether production's Canadian Towns taxonomy content actually differs from local's (divergence was stated by project owner; not yet directly diffed term-by-term)
- Multi-category Places queries returning a subset (open item under "Places API") — worked around by querying categories one at a time, never explained
- Fallback duplicate detection (coordinates; normalized name plus context) for OSM objects that are recreated with a new ID
- Coordinate tolerance (50 m) tuning against real data at larger scale, especially for large outlines
- `operator`/`owner` enrichment fields as a possible automated resolver for `needs_review` ownership ambiguity — noted as promising, not built
- Remaining Drush commands (`geoapify:check-updates`, `geoapify:status`), import status persistence, production deployment of this module
- For Listings: a shared address builder with a postcode guard, if Listing creation is built (see "Town and Address Connection").
- Consolidating the duplicated distance code (`ChangeDetector`, `TownBoundaryResolver`, `PoiNodeUpdater`): discussed, set aside, not scheduled.
- Latent creator issue: address built from Places data after a reverse-geocode verification (only matters if that setting is on).
- **Scale on production:** over 26,000 `canadian_towns` terms. One full import pass makes about 40 requests per town (39 category searches plus a one-time boundary lookup), roughly a million requests, which at the default limit of 2,500 per day is more than a year. The project owner has said a slow pass is acceptable, so this is a note, not a requirement. It only determines how often each town's data refreshes (about once per pass) and how long a town late in the loop waits for its first content. Estimated from the code, not measured.
- **Production:** enter the amenity mappings on the `poi_amenities` terms (`field_geoapify_facilities`); add the new fields to the POI form and view displays; Parking has no Geoapify facility.
- **Install check gap:** `field_canadian_towns` is not in `hook_requirements()`. Decide whether the install should create missing dependency fields.
- Listing: Office Hours field not created; no Listing detail wiring.
- Commit of the detail-fields work (`PlaceInfo`, the install file, the processor, creator and updater) is pending.
