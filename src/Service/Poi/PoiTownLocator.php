<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Database\Connection;
use Drupal\geoapify_importer\Service\CsdBoundaryLocator;
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
 * place (city / town / village first; then the most specific hamlet name; then
 * a municipality). Only when no name matches is the searched town used.
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
   * @var array<string, array<int, array{0: int, 1: float, 2: float, 3: string, 4: string}>>|null
   */
  protected ?array $names = NULL;

  /**
   * Furthest a name match may be from the place, in metres.
   */
  protected const MAX_NAME_DISTANCE = 30000.0;

  /**
   * Furthest a City term may be from its place: amalgamated cities such as
   * Ottawa (2,800 km2) have places 40+ km from the term's coordinates.
   */
  protected const MAX_CITY_DISTANCE = 100000.0;

  /**
   * Statistics Canada subdivision types that are a city, town or village in
   * their own right (C city, CV/CY city, T/TV town, V ville, VL village).
   * Everything else (municipality, township, regional district area, reserve,
   * county subdivision...) is an area that may contain named villages.
   */
  protected const CITY_LIKE = ['C', 'CV', 'CY', 'T', 'TV', 'V', 'VL'];

  /**
   * Furthest a village/hamlet term may be from the place, inside the same municipality.
   */
  protected const MAX_INSIDE_DISTANCE = 150000.0;

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
    protected ?CsdBoundaryLocator $boundaries = NULL,
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
    $properties = $feature['properties'] ?? [];
    $lat = (float) $coordinates[1];
    $lng = (float) $coordinates[0];
    $resolved = $this->resolveByBoundary($properties, $lat, $lng);
    if ($resolved !== NULL) {
      return $resolved['tid'];
    }
    $match = $this->matchByName($properties, $lat, $lng);
    if ($match !== NULL) {
      return $match;
    }
    // No name matched. The searched town stands, unless it is in a different
    // province from the place itself (a circle search crosses a provincial
    // border, e.g. Ottawa and Gatineau): then the nearest town in the
    // place's own province is used.
    if ($this->isWrongProvince($searched_tid, $properties)) {
      return $this->nearestInProvince($lat, $lng, (string) $properties['state_code'])['tid'] ?? $searched_tid;
    }
    return $searched_tid;
  }


  /**
   * The town for a point, by the official municipal boundaries.
   *
   * Finds the census subdivision (municipality) the point is inside, then:
   *  1. a city, town or village municipality: its own town term;
   *  2. otherwise (a township, regional district area, rural municipality...)
   *     the town, village or hamlet the place's address names, if that term is
   *     itself inside the same municipality;
   *  3. otherwise the municipality's own term, if the vocabulary has one;
   *  4. otherwise the address suburb, if inside the municipality;
   *  5. otherwise the nearest town term inside the municipality.
   * Province and municipality come from the boundary file, not the address.
   *
   * @return array{tid: int, how: string, municipality: string}|null
   *   NULL when the boundary files are not installed, the point is inside no
   *   municipality, or no town term fits; the caller then falls back to
   *   matchByName().
   */
  public function resolveByBoundary(array $properties, float $lat, float $lng): ?array {
    if ($this->boundaries === NULL || !$this->boundaries->isAvailable()) {
      return NULL;
    }
    $csd = $this->boundaries->find($lat, $lng);
    if ($csd === NULL) {
      return NULL;
    }
    $in = ['state_code' => $csd['province']];
    $municipality = $this->findTerm($this->cleanMunicipalityName($csd['name']), $in, $lat, $lng);
    if ($municipality !== NULL && in_array($csd['type'], self::CITY_LIKE, TRUE)) {
      return ['tid' => $municipality, 'how' => 'municipality', 'municipality' => $csd['name']];
    }
    foreach ($this->specificNames($properties) as $name) {
      if (($tid = $this->findTermInside($name, $csd, $lat, $lng)) !== NULL) {
        return ['tid' => $tid, 'how' => 'named village inside the municipality', 'municipality' => $csd['name']];
      }
    }
    if ($municipality !== NULL) {
      return ['tid' => $municipality, 'how' => 'municipality', 'municipality' => $csd['name']];
    }
    foreach ($this->suburbNames($properties) as $name) {
      if (($tid = $this->findTermInside($name, $csd, $lat, $lng)) !== NULL) {
        return ['tid' => $tid, 'how' => 'suburb inside the municipality', 'municipality' => $csd['name']];
      }
    }
    $nearest = $this->nearestInside($csd, $lat, $lng);
    return $nearest === NULL ? NULL : ['tid' => $nearest, 'how' => 'nearest town inside the municipality', 'municipality' => $csd['name']];
  }

  /**
   * The best town for a point: boundary first, then the address names.
   *
   * @return array{tid: int, how: string}|null
   */
  public function resolveTown(array $properties, float $lat, float $lng): ?array {
    $resolved = $this->resolveByBoundary($properties, $lat, $lng);
    if ($resolved !== NULL) {
      return $resolved;
    }
    $match = $this->matchByName($properties, $lat, $lng);
    return $match === NULL ? NULL : ['tid' => $match, 'how' => 'address name'];
  }

  /**
   * "Lloydminster (Part)" is "Lloydminster".
   */
  protected function cleanMunicipalityName(string $name): string {
    return trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $name));
  }

  /**
   * The closest term with this name whose coordinates are inside the municipality.
   */
  protected function findTermInside(string $name, array $csd, float $lat, float $lng): ?int {
    $best = NULL;
    foreach ($this->nameIndex()[$this->normalise($name)] ?? [] as [$tid, $t_lat, $t_lng, $t_prov]) {
      if ($t_prov !== '' && $t_prov !== $csd['province']) {
        continue;
      }
      $distance = $this->distance($lat, $lng, $t_lat, $t_lng);
      if ($distance > self::MAX_INSIDE_DISTANCE || ($best !== NULL && $distance >= $best[1])) {
        continue;
      }
      $at = $this->boundaries->find($t_lat, $t_lng);
      if ($at !== NULL && $at['uid'] === $csd['uid']) {
        $best = [$tid, $distance];
      }
    }
    return $best[0] ?? NULL;
  }

  /**
   * The nearest town term that lies inside the municipality, or NULL.
   */
  protected function nearestInside(array $csd, float $lat, float $lng): ?int {
    $candidates = [];
    foreach ($this->index() as $tid => $row) {
      if (strtoupper((string) ($row[3] ?? '')) !== $csd['province'] || abs($row[0] - $lat) > 1.0) {
        continue;
      }
      $candidates[(int) $tid] = $this->distance($lat, $lng, $row[0], $row[1]);
    }
    asort($candidates);
    $tries = 0;
    foreach ($candidates as $tid => $distance) {
      if ($distance > self::MAX_INSIDE_DISTANCE || ++$tries > 400) {
        break;
      }
      $row = $this->index()[$tid];
      $at = $this->boundaries->find($row[0], $row[1]);
      if ($at !== NULL && $at['uid'] === $csd['uid']) {
        return $tid;
      }
    }
    return NULL;
  }

  /**
   * The province code of a town term, or '' if unknown.
   */
  public function provinceOf(int $tid): string {
    return strtoupper((string) ($this->index()[$tid][3] ?? ''));
  }

  /**
   * True when the town is known to be in a different province from the place.
   */
  public function isWrongProvince(?int $tid, array $properties): bool {
    $place = strtoupper((string) ($properties['state_code'] ?? ''));
    $town = $tid === NULL ? '' : $this->provinceOf($tid);
    return $place !== '' && $town !== '' && $place !== $town;
  }

  /**
   * The nearest town whose province code is the given one.
   *
   * @return array{tid: int, distance: float}|null
   */
  public function nearestInProvince(float $lat, float $lng, string $province): ?array {
    $best = NULL;
    $province = strtoupper($province);
    foreach ($this->index() as $tid => $row) {
      if (strtoupper((string) ($row[3] ?? '')) !== $province) {
        continue;
      }
      if ($best !== NULL && abs($row[0] - $lat) * 110000.0 > $best['distance']) {
        continue;
      }
      $distance = $this->distance($lat, $lng, $row[0], $row[1]);
      if ($best === NULL || $distance < $best['distance']) {
        $best = ['tid' => (int) $tid, 'distance' => $distance];
      }
    }
    return $best;
  }

  /**
   * The names a place says it is in.
   *
   * @return string[]
   *   Display names: city, town, village, hamlet, then the bracketed part of
   *   the city ("Area A (Bamfield)" gives "Bamfield").
   */
  public function candidateNames(array $properties): array {
    return array_values(array_unique(array_merge(
      $this->cityNames($properties),
      $this->specificNames($properties),
      $this->suburbNames($properties),
    )));
  }

  /**
   * The city name, with and without its municipal prefix ("Township of Langley" gives "Langley").
   *
   * @return string[]
   */
  protected function cityNames(array $properties): array {
    if (empty($properties['city']) || !is_string($properties['city'])) {
      return [];
    }
    $city = $properties['city'];
    $stripped = trim(preg_replace('/^(?:Rural Municipality|Regional Municipality|Municipal District|Municipality|Township|City|Town|Village|District|County|Borough|Canton|Ville|Municipalit\X{00E9}|Municipalite)\s+(?:of|de|d\')\s*/iu', '', $city));
    return array_values(array_unique(array_filter([$stripped, $city])));
  }

  /**
   * The town, village, hamlet and bracketed names.
   *
   * @return string[]
   */
  protected function specificNames(array $properties): array {
    $names = [];
    foreach (['town', 'village', 'hamlet'] as $key) {
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
   * The suburb name, the weakest evidence (a neighbourhood of a bigger place).
   *
   * @return string[]
   */
  protected function suburbNames(array $properties): array {
    return (!empty($properties['suburb']) && is_string($properties['suburb'])) ? [$properties['suburb']] : [];
  }

  /**
   * The town term whose name the place's address gives, or NULL.
   *
   * Geoapify's "city" is often a whole amalgamated municipality (Pontiac,
   * Mississippi Mills, Lambton Shores) while the visitor expects the village
   * (Quyon, Almonte, Forest). So, in order:
   *  1. the city name, if it is a real city, town or village term;
   *  2. the town, village, hamlet and bracketed names (most specific place);
   *  3. the city name, whatever its type (e.g. a municipality);
   *  4. the suburb name (e.g. Murrayville, for a Township of Langley place).
   * Municipal prefixes are ignored ("Township of Langley" is Langley). A term
   * must be in the place's province (or within 10 km, for cities that
   * straddle a border such as Lloydminster) and within MAX_NAME_DISTANCE; of
   * several same-named terms the closest wins.
   */
  public function matchByName(array $properties, float $lat, float $lng): ?int {
    $city = $this->cityNames($properties);
    foreach ($city as $name) {
      if (($tid = $this->findTerm($name, $properties, $lat, $lng, ['City', 'Town', 'Village'])) !== NULL) {
        return $tid;
      }
    }
    foreach (array_merge($this->specificNames($properties), $city, $this->suburbNames($properties)) as $name) {
      if (($tid = $this->findTerm($name, $properties, $lat, $lng)) !== NULL) {
        return $tid;
      }
    }
    return NULL;
  }

  /**
   * The closest term with this name (optionally of given types), or NULL.
   */
  protected function findTerm(string $name, array $properties, float $lat, float $lng, ?array $types = NULL): ?int {
    $province = strtoupper((string) ($properties['state_code'] ?? ''));
    $best = NULL;
    foreach ($this->nameIndex()[$this->normalise($name)] ?? [] as [$tid, $t_lat, $t_lng, $t_prov, $t_type]) {
      if ($types !== NULL && !in_array($t_type, $types, TRUE)) {
        continue;
      }
      $distance = $this->distance($lat, $lng, $t_lat, $t_lng);
      if ($province !== '' && $t_prov !== '' && $t_prov !== $province && $distance > 10000.0) {
        continue;
      }
      $limit = $t_type === 'City' ? self::MAX_CITY_DISTANCE : self::MAX_NAME_DISTANCE;
      if ($distance <= $limit && ($best === NULL || $distance < $best[1])) {
        $best = [$tid, $distance];
      }
    }
    return $best[0] ?? NULL;
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
          $this->names[$this->normalise((string) $row[2])][] = [(int) $tid, $row[0], $row[1], strtoupper((string) ($row[3] ?? '')), (string) ($row[4] ?? '')];
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
   * Reads tid => [lat, lng, name, province code, type] for every town with coordinates.
   */
  protected function loadRows(): array {
    // Joined to the term table so a leftover location row whose term no longer
    // exists (deleted, or from a rolled-back bulk import) can never be chosen.
    $query = $this->database->select('taxonomy_term__field_geolocation', 'g');
    $query->innerJoin('taxonomy_term_field_data', 'd', 'd.tid = g.entity_id');
    $query->leftJoin('taxonomy_term__field_province_code', 'p', 'p.entity_id = g.entity_id AND p.deleted = 0');
    $query->leftJoin('taxonomy_term__field_type', 't', 't.entity_id = g.entity_id AND t.deleted = 0');
    $result = $query
      ->fields('g', ['entity_id', 'field_geolocation_lat', 'field_geolocation_lng'])
      ->fields('d', ['name'])
      ->fields('p', ['field_province_code_value'])
      ->fields('t', ['field_type_value'])
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
          (string) $row->field_type_value,
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
