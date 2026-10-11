<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Decides which town a place belongs to.
 *
 * A boundary search is trustworthy: the place is inside the town's boundary,
 * so the town that was searched is the town. A 15 km circle search is not: it
 * reaches into neighbouring towns, and because towns are imported in id order
 * the first hamlet searched claimed every place near it, even places that
 * belong to a different town (a Bamfield dock was filed under the hamlet
 * Aa-at-sow-is). For places found by a circle search the town is therefore
 * the NEAREST town (by distance to the town's coordinates), not the town that
 * happened to be searched. That never depends on the order towns are imported.
 *
 * The town coordinates are read once per process straight from the
 * field_geolocation table of the canadian_towns vocabulary (only rows whose
 * term still exists). Province-level
 * terms have no coordinates and so are never chosen. If the table cannot be
 * read, the searched town is used, as before.
 */
class PoiTownLocator {

  public const METHOD_BOUNDARY = 'boundary';

  /**
   * Town index: tid => [lat, lng]. NULL until loaded.
   *
   * @var array<int, array{0: float, 1: float}>|null
   */
  protected ?array $index = NULL;

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The town tid a place should be connected to.
   *
   * @param array $feature
   *   The Geoapify feature (coordinates are read from its geometry).
   * @param int|null $searched_tid
   *   The town the import was searching when it found the place.
   * @param string|null $search_method
   *   'boundary' or 'circle' (anything but 'boundary' counts as circle).
   */
  public function townFor(array $feature, ?int $searched_tid, ?string $search_method): ?int {
    if ($search_method === self::METHOD_BOUNDARY || $searched_tid === NULL) {
      return $searched_tid;
    }
    $coordinates = $feature['geometry']['coordinates'] ?? NULL;
    if (!is_array($coordinates) || count($coordinates) < 2) {
      return $searched_tid;
    }
    $nearest = $this->nearest((float) $coordinates[1], (float) $coordinates[0]);
    return $nearest['tid'] ?? $searched_tid;
  }

  /**
   * The nearest town to a point.
   *
   * @return array{tid: int, distance: float}|null
   *   The town id and distance in metres, or NULL if no towns are known.
   */
  public function nearest(float $lat, float $lng): ?array {
    $best = NULL;
    foreach ($this->index() as $tid => [$town_lat, $town_lng]) {
      // Cheap rejection before the trigonometry: the north-south gap alone
      // is a lower bound on the distance (a degree of latitude is at least
      // 110.5 km), so a town whose gap already exceeds the best found cannot win.
      if ($best !== NULL && abs($town_lat - $lat) * 110000.0 > $best['distance']) {
        continue;
      }
      $distance = $this->distance($lat, $lng, $town_lat, $town_lng);
      if ($best === NULL || $distance < $best['distance']) {
        $best = ['tid' => (int) $tid, 'distance' => $distance];
      }
    }
    return $best;
  }

  /**
   * Distance in metres from a point to a known town, or NULL if unknown.
   */
  public function distanceTo(int $tid, float $lat, float $lng): ?float {
    $index = $this->index();
    if (!isset($index[$tid])) {
      return NULL;
    }
    return $this->distance($lat, $lng, $index[$tid][0], $index[$tid][1]);
  }

  /**
   * The town coordinates, loaded once.
   */
  protected function index(): array {
    if ($this->index === NULL) {
      try {
        $this->index = $this->loadRows();
      }
      catch (\Throwable $e) {
        $this->logger->error('Could not read the town coordinates, falling back to the searched town: @message', [
          '@message' => $e->getMessage(),
        ]);
        $this->index = [];
      }
    }
    return $this->index;
  }

  /**
   * Reads tid => [lat, lng] for every town that has coordinates.
   */
  protected function loadRows(): array {
    // Joined to the term table so a leftover location row whose term no longer
    // exists (deleted, or from a rolled-back bulk import) can never be chosen.
    $query = $this->database->select('taxonomy_term__field_geolocation', 'g');
    $query->innerJoin('taxonomy_term_field_data', 'd', 'd.tid = g.entity_id');
    $result = $query
      ->fields('g', ['entity_id', 'field_geolocation_lat', 'field_geolocation_lng'])
      ->condition('g.bundle', 'canadian_towns')
      ->condition('g.deleted', 0)
      ->condition('d.vid', 'canadian_towns')
      ->condition('d.default_langcode', 1)
      ->execute();

    $rows = [];
    foreach ($result as $row) {
      if ($row->field_geolocation_lat !== NULL && $row->field_geolocation_lng !== NULL) {
        $rows[(int) $row->entity_id] = [(float) $row->field_geolocation_lat, (float) $row->field_geolocation_lng];
      }
    }
    return $rows;
  }

  /**
   * Great-circle distance in metres (haversine).
   */
  protected function distance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $h = sin(($phi2 - $phi1) / 2) ** 2 + cos($phi1) * cos($phi2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
    return 2 * 6371000.0 * asin(min(1.0, sqrt($h)));
  }

}
