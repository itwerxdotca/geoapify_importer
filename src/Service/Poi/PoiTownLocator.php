<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Decides which town a place belongs to.
 *
 * A boundary search is trustworthy: the place is inside the town's boundary,
 * so the town that was searched is the town.
 *
 * A 15 km circle search is not: it reaches into neighbouring towns, and the
 * first town searched used to claim every place near it (a Bamfield dock was
 * filed under the neighbouring locality Aa-at-sow-is). Nearest-centroid is no
 * better, because the vocabulary holds thousands of tiny localities and would
 * split cities into hamlets. Instead the place's own address is used: Geoapify
 * records the city / town / village / hamlet a place sits in, so the place is
 * filed under the town term of that NAME, in the same province, closest to the
 * place. Only when no name matches is the searched town used.
 *
 * The town index is read once per process from the canadian_towns vocabulary
 * (only terms that still exist). If it cannot be read, the searched town is used.
 */
class PoiTownLocator {

  public const METHOD_BOUNDARY = 'boundary';

  /**
   * Town index: tid => [lat, lng]. NULL until loaded.
   *
   * @var array<int, array{0: float, 1: float}>|null
   */
  protected ?array $index = NULL;

  /**
   * Name index: normalised name => list of [tid, lat, lng, province code].
   *
   * @var array<string, array<int, array{0: int, 1: float, 2: float, 3: string}>>|null
   */
  protected ?array $names = NULL;

  /**
   * Furthest a name match may be from the place, in metres.
   */
  protected const MAX_NAME_DISTANCE = 30000.0;

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
    $match = $this->matchByName($feature['properties'] ?? [], (float) $coordinates[1], (float) $coordinates[0]);
    return $match ?? $searched_tid;
  }

  /**
   * The names a place says it is in, most general first.
   *
   * @return string[]
   *   Display names: city, town, village, hamlet, then the bracketed part of
   *   the city ("Area A (Bamfield)" gives "Bamfield").
   */
  public function candidateNames(array $properties): array {
    $names = [];
    foreach (['city', 'town', 'village', 'hamlet'] as $key) {
      if (!empty($properties[$key]) && is_string($properties[$key])) {
        $names[] = $properties[$key];
      }
    }
    if (!empty($properties['city']) && is_string($properties['city']) && preg_match('/\(([^)]+)\)/', $properties['city'], $m)) {
      $names[] = $m[1];
    }
    return $names;
  }

  /**
   * The town term whose name the place's address gives, or NULL.
   *
   * The first candidate name that matches a term in the place's province
   * within MAX_NAME_DISTANCE wins; of several same-named terms the closest.
   */
  public function matchByName(array $properties, float $lat, float $lng): ?int {
    $names = $this->nameIndex();
    $province = strtoupper((string) ($properties['state_code'] ?? ''));
    foreach ($this->candidateNames($properties) as $name) {
      $best = NULL;
      foreach ($names[$this->normalise($name)] ?? [] as [$tid, $t_lat, $t_lng, $t_prov]) {
        if ($province !== '' && $t_prov !== '' && $t_prov !== $province) {
          continue;
        }
        $distance = $this->distance($lat, $lng, $t_lat, $t_lng);
        if ($distance <= self::MAX_NAME_DISTANCE && ($best === NULL || $distance < $best[1])) {
          $best = [$tid, $distance];
        }
      }
      if ($best !== NULL) {
        return $best[0];
      }
    }
    return NULL;
  }

  /**
   * True when a town's name is one of the names the place's address gives.
   */
  public function nameMatches(int $tid, array $properties): bool {
    $row = $this->index()[$tid] ?? NULL;
    if ($row === NULL || !isset($row[2])) {
      return FALSE;
    }
    $mine = $this->normalise((string) $row[2]);
    foreach ($this->candidateNames($properties) as $name) {
      if ($this->normalise($name) === $mine) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lower-case, accent-free, punctuation-free form used to compare names.
   */
  public function normalise(string $name): string {
    $name = mb_strtolower($name);
    if (function_exists('transliterator_transliterate')) {
      $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?: $name;
    }
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    $name = preg_replace(['/\bsaint\b/', '/\bsainte\b/', '/\bmount\b/', '/\bfort\b/'], ['st', 'ste', 'mt', 'ft'], $name);
    return trim(preg_replace('/\s+/', ' ', $name));
  }

  /**
   * Builds the name index from the town rows, once.
   */
  protected function nameIndex(): array {
    if ($this->names === NULL) {
      $this->names = [];
      foreach ($this->index() as $tid => $row) {
        if (isset($row[2]) && $row[2] !== '') {
          $this->names[$this->normalise((string) $row[2])][] = [(int) $tid, $row[0], $row[1], strtoupper((string) ($row[3] ?? ''))];
        }
      }
    }
    return $this->names;
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
   * Reads tid => [lat, lng, name, province code] for every town with coordinates.
   */
  protected function loadRows(): array {
    // Joined to the term table so a leftover location row whose term no longer
    // exists (deleted, or from a rolled-back bulk import) can never be chosen.
    $query = $this->database->select('taxonomy_term__field_geolocation', 'g');
    $query->innerJoin('taxonomy_term_field_data', 'd', 'd.tid = g.entity_id');
    $query->leftJoin('taxonomy_term__field_province_code', 'p', 'p.entity_id = g.entity_id AND p.deleted = 0');
    $result = $query
      ->fields('g', ['entity_id', 'field_geolocation_lat', 'field_geolocation_lng'])
      ->fields('d', ['name'])
      ->fields('p', ['field_province_code_value'])
      ->condition('g.bundle', 'canadian_towns')
      ->condition('g.deleted', 0)
      ->condition('d.vid', 'canadian_towns')
      ->condition('d.default_langcode', 1)
      ->execute();

    $rows = [];
    foreach ($result as $row) {
      if ($row->field_geolocation_lat !== NULL && $row->field_geolocation_lng !== NULL) {
        $rows[(int) $row->entity_id] = [
          (float) $row->field_geolocation_lat,
          (float) $row->field_geolocation_lng,
          (string) $row->name,
          (string) $row->field_province_code_value,
        ];
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
