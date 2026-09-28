# Towns Canada — Geoapify Importer Project Specification (Updated)

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

### Current module structure (as committed)

```
geoapify_importer/
├── config/
│   └── schema/
│       └── geoapify_importer.schema.yml
├── src/
│   ├── Drush/
│   │   └── Commands/
│   │       └── GeoapifyImporterCommands.php
│   ├── Form/
│   │   └── GeoapifyImporterSettingsForm.php
│   └── Service/
│       ├── GeoapifyClient.php
│       ├── SourceFileWriter.php
│       ├── AddressVerifier.php
│       └── Poi/
│           └── PoiCategoryClassifier.php
├── geoapify_importer.info.yml
├── geoapify_importer.services.yml
├── geoapify_importer.routing.yml
└── geoapify_importer.links.menu.yml
```

Note the `Service/Poi/` subdirectory — introduced this session specifically to keep POI-specific classification logic separate from shared ingestion infrastructure (`GeoapifyClient`, `SourceFileWriter`, `AddressVerifier`), per the Architecture Goal.

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
│   ├── {place_id}/
│   │   ├── latest.json
│   │   └── history/
│   │       └── {timestamp}.json
```

- `{place_id}`: Geoapify's own opaque place ID, used verbatim as directory name.
- `{timestamp}`: `gmdate('Ymd\THis\Z')`.
- Archive-on-rewrite confirmed working; atomic writes (temp + rename).

**Implemented as:** `Drupal\geoapify_importer\Service\SourceFileWriter` (`geoapify_importer.source_writer`). `write()` and `readLatest()` both verified against real Geoapify data, including a 7-feature batch fetch this session (see `geoapify:fetch` below).

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

**New implementation note (this session):** `field_poi_location` uses the **Geolocation module**, which expects a specific structure (lat/lng keyed), not a raw coordinate pair. The eventual POI-creation service will need a small transform step converting Geoapify's `geometry.coordinates` (`[lon, lat]` order) into the Geolocation module's expected field structure. Not yet implemented.

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

4. **Explicit exceptions carved out regardless of "sells something":** historic places, government buildings, medical centers/hospitals, police stations, fire stations — confirmed by project owner as staying POI regardless of any commercial activity. Two sub-cases flagged as still needing a decision: private medical/dental clinics and pharmacies (arguably commercial, unlike hospitals) — **NOT YET RESOLVED.**

5. **Investigated whether `field_business_ownership_types` could resolve the public/private ambiguity automatically.** **RULED OUT** — see Listing Content Type section above; that field is scoped for a different, future purpose and has no "not a business" term.

6. **Discussed real-world precedent: how does Google structure Places/Business Profiles?** Confirmed via research: Google uses **one record per place, free by default**, with paid features (e.g. "Google Guaranteed") as an add-on to the *same* record — not a separate listing type for paid vs. free. This directly informed the recommended direction below.

7. **Discussed merging POI and Listing into one content type** to avoid the classification problem entirely. **REJECTED** by project owner — explicitly wants to keep the two existing content types separate, citing the cost of rebuilding search/views/permissions/templates around a merged structure. Confirmed: **no merge; POI and Listing remain fully separate content types, unchanged in their existing structure.**

8. **Discussed "start as free POI, later upgrade to paid Listing" as a genuine product goal** (confirmed: yes, this is wanted, prioritizing thoroughness over minimizing build size). Two architectural options were presented:
   - **Option 1 (recommended, not yet built):** Listing is its own node with an entity-reference field pointing back to a POI node it "upgrades"; POI stays the canonical free record, Listing adds paid-only data on top. No duplicate name/address/coordinates.
   - **Option 2:** a shared underlying "Place" data structure referenced by both POI and Listing as siblings, rather than one being primary. Bigger to build, not currently justified given the confirmed POI-first flow.
   - **Not yet finalized** which option to build, though Option 1 fits the stated flow (POI first, then optionally upgraded) most directly. Also not yet resolved: whether a POI is ever retired/removed once upgraded, or permanently remains as the free base layer with the Listing purely additive (current assumption, unconfirmed: the latter).

9. **Applying the Google model to Listing's EXISTING structure (this session's most concrete conclusion):** Since Listing already has a real Paragraphs-based component system (`field_listing_components`), the "paid unlocks more" mechanism does not need to be invented — a paid Listing simply gets access to more/different paragraph types (or richer versions of `listing_gallery`/`listing_social`/`listing_amenities`) attached to this same existing field, likely gated by a new boolean flag (e.g. `field_is_paid`, not yet created) or a future real subscription/billing record. **This significantly de-risks the "paid components" part of the plan — it's additive to Listing's existing structure, not a rebuild.**

### Current working classification rule (synthesized from the discussion, NOT YET COMPLETE OR CODED beyond the IGNORE list)

- Default: a Geoapify category that represents a commercial operation → classify toward **Listing**.
- Explicit exceptions, regardless of commercial activity → stays **POI**: historic/heritage sites, government buildings, hospitals, police stations, fire stations, tourism information centres, cemeteries.
- **Still unresolved:** private medical/dental clinics, pharmacies; and the full museum/zoo/aquarium/theme_park category set (original spec said POI; business-model discussion suggests Listing; not finalized).
- **Still unresolved:** the mechanism for genuinely ambiguous individual places (e.g., is a specific zoo municipally-run or privately-owned) — current working assumption is these fall to `NEEDS_REVIEW` for manual, case-by-case human judgment, since Geoapify's category data alone cannot distinguish ownership structure, and `field_business_ownership_types` was ruled out as a shortcut for this (see above). This keeps the classification RULE simple and automatic for clear-cut cases, while accepting that some cases will always need a human decision — considered acceptable per project owner ("difficult... a lot of them cross over").

**This entire section needs a dedicated follow-up session to finalize the category-by-category IGNORE/DIRECT list before `PoiCategoryClassifier` can be meaningfully expanded beyond the single artwork rule.**

## Classification Engine — IN PROGRESS

### Geoapify's full category list — OBTAINED THIS SESSION (authoritative, live-pulled)

833 categories confirmed via Geoapify's own `list_place_categories` endpoint (called live, using the project's real API key, via `v1/mcp` JSON-RPC). This is the authoritative source for all future mapping/classification work — superior to piecing categories together from documentation pages, which was the previous approach. Full list obtained and reviewed; available in conversation history if needed again, or re-fetchable via the same method at any time for a refresh.

### `PoiCategoryClassifier` — BUILT AND VERIFIED THIS SESSION

- Class: `Drupal\geoapify_importer\Service\Poi\PoiCategoryClassifier`.
- Service: `geoapify_importer.poi_category_classifier` (no dependencies — pure logic).
- **Scope, deliberately narrow:** answers only "should this ever become a POI at all?" via a small, code-level (not taxonomy-field-based) IGNORE list. Does NOT yet perform DIRECT/CONDITIONAL mapping to actual taxonomy terms — that requires the dynamic, taxonomy-field-driven mapper described above, not yet built.
- **Why IGNORE is code, not a taxonomy field:** "should this category ever become a POI" is a data-quality/business rule, not an editorial taxonomy decision — kept separate from the (not-yet-built) DIRECT-mapping mechanism, which WILL be taxonomy-field-driven for the reasons in the "Local vs. Production Data Divergence" section.
- **Matching logic:** hierarchy-aware (exact match, or dot-separated child of an ignored branch). Real-data testing revealed Geoapify's `categories` array already includes the full ancestor chain for every feature (not just the most specific leaf), so exact-matching against any array element is sufficient in practice; prefix-walking is kept as a safety net.
- **Current IGNORE list:** `tourism.attraction.artwork` only (covers `.mural`, `.sculpture`, `.statue` children) — confirmed via the real Seven Grandfather Teachings data.
- **Verified:** tested against two real stored records — the "Love" artwork piece (correctly `ignored`) and "Heritage Village" museum (correctly `pending_mapping`, since DIRECT mapping isn't built yet).
- **Expansion pending:** the full IGNORE list expansion is blocked on finalizing the Business Model / Classification Strategy decisions above (see that section's "still unresolved" items) — deliberately not expanded further this session until those are settled, to avoid coding a rule that will need to be reversed.

### Confirmed exclusions from IGNORE, per explicit project owner instruction

- `tourism.information.*` (visitor/info centres) — people do seek these out; stays out of IGNORE regardless of the business-model discussion.
- `memorial.cemetery`, `memorial.graveyard` — same; stays out of IGNORE.

## Dev Tooling — NEW THIS SESSION

### `drush geoapify:fetch` — Drush command, built and verified

- File: `src/Drush/Commands/GeoapifyImporterCommands.php`.
- Discovered automatically by Drush 13 via `ContainerInjectionInterface` (no `drush.services.yml` needed).
- Usage: `drush geoapify:fetch <count> [--categories=] [--lat=] [--lon=] [--radius=]`.
- Defaults: `categories=entertainment,tourism`, Fort McMurray-area center, 15km radius.
- `count` is clamped to Geoapify's real documented range (1–500), with a warning if adjusted, rather than erroring.
- Fetches via `GeoapifyClient::request()`, writes each result via `SourceFileWriter::write()`, prints a results table (name / place_id / file path) and a success count.
- **Scope, explicitly narrow:** this is a manual dev/test tool for exercising fetch+store — it does NOT classify, map, or create nodes. The eventual full `geoapify:import` command (per original spec) is separate, not-yet-built, and will layer classification/mapping/node-creation on top of what this command already proves works.
- **Live-tested at `limit=20`** (returned 7 real features — see Classification Engine discovery above).

## Ingestion / Synchronization Design (mostly unchanged — not yet implemented)

- Drupal cron, Queue API, further Drush commands (`geoapify:import`, `geoapify:check-updates`, `geoapify:status`), pagination, rate limiting, error handling beyond what exists, duplicate detection, change detection, review workflows.
- `geoapify:fetch` (above) is a new, real, narrower precursor to `geoapify:import` — not a replacement for it.

## Record Identity / Duplicate Detection (unchanged)

Preferred identity: Geoapify place ID, confirmed as the file-level identity key. Node-level duplicate detection not yet implemented.

## Import Statuses (unchanged, not yet implemented)

`NEW`, `IMPORTED`, `UNCHANGED`, `CHANGED`, `NEEDS_REVIEW`, `IGNORED`, `ERROR`, `SOURCE_UPDATED`. `IGNORED` now has a concrete, coded first implementation via `PoiCategoryClassifier`, though not yet wired into a persisted per-record status.

## Field Ownership Matrix (unchanged from prior revision — see that document for the full table)

POI's matrix (title, location, category, description, etc.) stands as previously defined. **Not yet extended to Listing** — Listing's real field structure is now known (this session), but its own field-ownership matrix (which Listing fields are source-controlled vs. business-owner-controlled vs. platform-controlled-paid-features) has not yet been designed.

## Development Rules (reaffirmed, with one addition this session)

All prior rules stand. Additionally: **when local and production may structurally differ (taxonomy content, field structure, private file paths), verify against production directly (read-only, via SSH/Drush) rather than assuming local is representative.** This session's production research uncovered a materially more developed Listing content type than assumed, and confirmed a real local/production taxonomy divergence that directly changed the classification engine's design (static config rejected in favor of dynamic taxonomy-field mapping).

## Current Verified State

### Verified this session (in addition to everything verified previously)

- Reverse-geocode fallback corrected to use real, confirmed signal (housenumber+street), replacing an invalid design based on fields the API doesn't return
- Settings form/config fully consistent across local site, stored config, and GitHub (a real discrepancy between these three was found and fixed)
- `geoapify:fetch` Drush command built, verified working end-to-end at `limit=20` against real data
- `PoiCategoryClassifier` built and verified against real data (artwork → ignored; museum → pending_mapping)
- Geoapify's full 833-category list obtained live and authoritatively
- Production's real field structures for both `point_of_interest` and `listing` confirmed via direct, read-only SSH/Drush inspection
- Production/local version parity confirmed (Drupal 11.4.7, PHP 8.4.26, Drush 13.8.0.0 on both)
- Local vs. production taxonomy divergence confirmed as real and structural, directly shaping the classification engine's design toward dynamic taxonomy-field-driven mapping over static config

### Not yet implemented / not yet resolved

- **Business Model / Classification Strategy finalization** (see dedicated section above) — the single biggest open item, blocking further IGNORE-list expansion and all DIRECT-mapping work
- Dynamic taxonomy-field-driven mapper itself (`field_geoapify_categories` on POI Category terms; the actual `PoiCategoryMapper` service performing DIRECT/CONDITIONAL lookups) — designed in discussion, not yet built
- Whether/how a POI can be "upgraded" to a Listing (Option 1 vs. Option 2 from the discussion above) — not finalized, not built
- Listing's own field-ownership matrix
- `field_is_paid` (or equivalent) flag/mechanism gating Listing's paid components
- Production private-filesystem path naming confirmation (`/home/townscanada/backup`)
- Confirmation of whether production's Canadian Towns taxonomy content actually differs from local's (divergence was stated by project owner; not yet directly diffed term-by-term)
- Everything previously listed as not-yet-implemented in prior revisions: node-level duplicate detection, change detection logic, POI/Listing creation services, queue workers, cron, remaining Drush commands, import status persistence, production deployment of this module