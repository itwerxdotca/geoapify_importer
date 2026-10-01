<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\geoapify_importer\Service\Poi\PoiCategoryClassifier;
use Psr\Log\LoggerInterface;

/**
 * Runs the per-town, per-category Geoapify fetch/identity/dedupe/store loop.
 *
 * SCOPE: this is the ingestion stage only — fetch, PlaceIdentity, change
 * detection, classification (reported, not acted on), and storage. It does
 * NOT assign taxonomy terms or create Drupal nodes; the DIRECT-mapping
 * taxonomy mapper and a node-creation service are separate, not-yet-built
 * pieces. "Production ready" describes this scope, not the full pipeline.
 *
 * Built as a service, not command-only logic, so cron and queue workers
 * (per the spec's ingestion design) can reuse it later without duplicating
 * the loop.
 *
 * KNOWN, DELIBERATE LIMITATIONS (not oversights — see inline notes):
 * - Categories are queried ONE AT A TIME per town, never comma-combined.
 *   A combined query (entertainment,tourism) was observed returning an
 *   unexplained, much smaller result set than querying either category
 *   alone; querying separately avoids relying on that unverified
 *   behavior, at the cost of more API calls.
 * - No pagination beyond Geoapify's own per-request limit (max 500). No
 *   verified pagination parameter exists in this codebase yet. A result
 *   count that exactly equals the requested limit is logged as a warning
 *   (likely truncation), rather than silently accepted as complete.
 */
class TownImportRunner {

  /**
   * Geoapify category branches this importer searches for, one at a time.
   *
   * Curated from this session's classification decisions — NOT the full
   * 833-category list. Two groups:
   * - Categories PoiCategoryClassifier will mark needs_review (searched
   *   so they reach a human, even though they'll never auto-classify).
   * - Categories already known to be POI-eligible (never on any ignore
   *   list): the original spec's natural/park/landmark mapping targets,
   *   plus this session's confirmed exceptions (historic, government,
   *   hospital, police, fire, tourism info, cemeteries, places of
   *   worship).
   *
   * Deliberately excludes every branch PoiCategoryClassifier would mark
   * ignored — no point spending an API call fetching places that can
   * never become a POI.
   *
   * @var string[]
   */
  public const POI_SEARCH_CATEGORIES = [
    // needs_review branches (see PoiCategoryClassifier).
    'entertainment.museum',
    'entertainment.zoo',
    'entertainment.aquarium',
    'entertainment.theme_park',
    'entertainment.culture.arts_centre',
    'entertainment.culture.gallery',
    'entertainment.culture.theatre',
    'beach.beach_resort',
    'camping',
    'maritime.marina',
    'ski.lift',
    'sport.stadium',
    'sport.golf_course',
    'production.brewery',
    'production.winery',
    'production.distillery',

    // Confirmed POI-eligible (never ignored).
    'heritage.unesco',
    'tourism.sights',
    'tourism.information',
    'memorial.cemetery',
    'memorial.graveyard',
    'office.government',
    'healthcare.hospital',
    'service.police',
    'service.fire_station',
    'religion.place_of_worship',
    // 'natural' alone was tried and found too broad: on a real circle
    // fallback (Table Bay, BC), it returned 200/200 results dominated by
    // natural.wetland (122), natural.coastal (39), and natural.water/
    // .inland (34/13) — minor geographic features, not attractions.
    // natural.mountain/.peak was only 5 of the 200. Narrowed to the
    // specific branches the original spec's mapping table actually named.
    'natural.mountain',
    'natural.forest',
    'national_park',
    'leisure.park',
    'leisure.park.garden',
    'leisure.park.nature_reserve',
    'leisure.playground',
    'man_made.bridge',
    'man_made.lighthouse',
    'man_made.tower',
    'man_made.pier',
    'waterway.channels',
    'waterway.hydraulic_structures',
  ];

  /**
   * Fallback search radius in metres, used only when boundary resolution
   * fails (see TownBoundaryResolver). Hardcoded, not UI-configurable, per
   * project preference for hardcoded pipeline constants. A judgment call,
   * not measured per-town — a real town's true extent varies (Calgary's
   * bounding box alone was ~32km x 41km); this exists only as a fallback
   * for when the real boundary can't be resolved at all.
   */
  protected const FALLBACK_RADIUS_METERS = 15000;

  /**
   * Maximum results requested per category, per town, per run.
   *
   * Geoapify's own documented ceiling for the `limit` parameter is 500.
   * A result count that equals this value is logged as a likely-truncated
   * warning, since no pagination beyond one request is implemented.
   */
  protected const MAX_RESULTS_PER_CATEGORY = 200;

  /**
   * Courtesy delay between API calls, in microseconds.
   *
   * Not derived from any confirmed Geoapify rate limit — a conservative
   * default to avoid hammering the API during a multi-town, multi-category
   * run. Adjust if a real rate limit is confirmed.
   */
  protected const API_CALL_DELAY_MICROSECONDS = 150000;

  public function __construct(
    protected GeoapifyClient $client,
    protected SourceFileWriter $writer,
    protected ChangeDetector $detector,
    protected PlaceIdentity $identity,
    protected PoiCategoryClassifier $classifier,
    protected TownBoundaryResolver $boundaryResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Loads every canadian_towns term that has usable coordinates.
   *
   * Terms without field_geolocation set (e.g. province-level parent
   * terms — see project notes on the canadian_towns vocabulary
   * structure) are skipped, not treated as errors.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   Keyed by term ID.
   */
  public function loadImportableTowns(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => 'canadian_towns']);

    $importable = [];
    foreach ($terms as $tid => $term) {
      if (!$term->hasField('field_geolocation')) {
        continue;
      }
      $value = $term->get('field_geolocation')->getValue();
      if (!empty($value) && isset($value[0]['lat'], $value[0]['lng'])) {
        $importable[$tid] = $term;
      }
    }

    return $importable;
  }

  /**
   * Imports one town: resolves its search area, queries every category,
   * and stores new/changed places.
   *
   * @param \Drupal\taxonomy\TermInterface $town
   *   A canadian_towns term with field_geolocation set.
   * @param bool $dry_run
   *   If TRUE, runs identity/change-detection/classification but does
   *   NOT write anything to the private filesystem. Useful for a full
   *   trial run before committing to writing real files.
   *
   * @return array
   *   A per-town summary: town name, search method used ('boundary' or
   *   'circle'), per-category and aggregate counts, and any errors.
   */
  public function importTown(\Drupal\taxonomy\TermInterface $town, bool $dry_run = FALSE): array {
    $tid = (int) $town->id();
    $name = $town->label();

    $summary = [
      'tid' => $tid,
      'town' => $name,
      'search_method' => NULL,
      'categories_queried' => 0,
      'categories_failed' => 0,
      'places_seen' => 0,
      'new' => 0,
      'changed' => 0,
      'unchanged' => 0,
      'errors' => 0,
      'possibly_truncated' => [],
    ];

    $province_code = $town->hasField('field_province_code') ? $town->get('field_province_code')->value : NULL;
    $geo = $town->get('field_geolocation')->getValue()[0];
    $boundary_id = NULL;

    try {
      $boundary_id = $this->boundaryResolver->resolve($tid, $name, $province_code, (float) $geo['lat'], (float) $geo['lng']);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Boundary resolution threw for town @name: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
    }

    if ($boundary_id !== NULL) {
      $filter = 'place:' . $boundary_id;
      $summary['search_method'] = 'boundary';
    }
    else {
      $filter = sprintf('circle:%s,%s,%s', $geo['lng'], $geo['lat'], self::FALLBACK_RADIUS_METERS);
      $summary['search_method'] = 'circle';
    }

    foreach (self::POI_SEARCH_CATEGORIES as $category) {
      usleep(self::API_CALL_DELAY_MICROSECONDS);

      try {
        $response = $this->client->request([
          'categories' => $category,
          'filter' => $filter,
          'limit' => self::MAX_RESULTS_PER_CATEGORY,
        ]);
      }
      catch (\Throwable $e) {
        $summary['categories_failed']++;
        $this->logger->error('Category @category failed for town @name: @message', [
          '@category' => $category,
          '@name' => $name,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      $summary['categories_queried']++;
      $features = $response['features'] ?? [];

      if (count($features) === self::MAX_RESULTS_PER_CATEGORY) {
        $summary['possibly_truncated'][] = $category;
        $this->logger->warning('Town @name, category @category returned exactly the request limit (@limit) — results may be truncated. Pagination is not implemented.', [
          '@name' => $name,
          '@category' => $category,
          '@limit' => self::MAX_RESULTS_PER_CATEGORY,
        ]);
      }

      foreach ($features as $feature) {
        $summary['places_seen']++;

        try {
          $key = $this->identity->keyFor($feature);
        }
        catch (\InvalidArgumentException $e) {
          $summary['errors']++;
          continue;
        }

        try {
          $result = $this->detector->detect($feature, $this->writer->readLatest($key));
          $status = $result['status'];

          if ($status !== ChangeDetector::STATUS_UNCHANGED && !$dry_run) {
            $this->writer->write($key, $feature);
          }

          // Classification is computed for visibility/logging parity with
          // geoapify:fetch, but never gates a write — see class docblock.
          $this->classifier->classify($feature['properties']['categories'] ?? []);

          $summary[$status]++;
        }
        catch (\Throwable $e) {
          $summary['errors']++;
          $this->logger->error('Failed processing a place in town @name, category @category: @message', [
            '@name' => $name,
            '@category' => $category,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return $summary;
  }

  /**
   * Imports every importable town, yielding one summary at a time.
   *
   * A generator, not an array-returning method: a full run can cover many
   * towns and take a long time (each town makes one API call per category
   * in POI_SEARCH_CATEGORIES, with a courtesy delay between calls).
   * Yielding per-town lets a caller (e.g. a Drush command) report
   * progress as it happens, rather than appearing to hang.
   *
   * @param bool $dry_run
   *   Passed through to importTown().
   * @param int|null $town_limit
   *   If set, stops after this many towns. For testing/partial runs.
   *
   * @return \Generator<array>
   */
  public function importAll(bool $dry_run = FALSE, ?int $town_limit = NULL): \Generator {
    $towns = $this->loadImportableTowns();

    $count = 0;
    foreach ($towns as $town) {
      if ($town_limit !== NULL && $count >= $town_limit) {
        break;
      }

      try {
        yield $this->importTown($town, $dry_run);
      }
      catch (\Throwable $e) {
        $this->logger->error('Town @name failed entirely: @message', [
          '@name' => $town->label(),
          '@message' => $e->getMessage(),
        ]);
        yield [
          'tid' => (int) $town->id(),
          'town' => $town->label(),
          'search_method' => NULL,
          'fatal_error' => $e->getMessage(),
        ];
      }

      $count++;
    }
  }

}
