<?php

namespace Drupal\geoapify_importer\Service;

/**
 * Detects whether a freshly fetched Geoapify feature differs from the stored one.
 *
 * Shared ingestion infrastructure (not POI-specific): it compares raw
 * Geoapify features only, so a future Listing importer can reuse it as-is.
 *
 * Only a hardcoded list of meaningful fields is compared. Geoapify also
 * returns volatile data (rank/popularity scores, key ordering) that changes
 * without any real-world meaning; comparing raw bytes would flag those as
 * changes. The list below is intentionally explicit and easy to edit.
 *
 * IMPORTANT ORDERING: detect() must run BEFORE SourceFileWriter::write(),
 * because write() archives and replaces latest.json. After a write, the
 * "stored" copy is the incoming copy and everything looks unchanged.
 */
class ChangeDetector {

  public const STATUS_NEW = 'new';
  public const STATUS_UNCHANGED = 'unchanged';
  public const STATUS_CHANGED = 'changed';

  /**
   * Scalar `properties.*` keys compared as plain values.
   *
   * @var string[]
   */
  protected const COMPARED_PROPERTIES = [
    'name',
    'street',
    'housenumber',
    'postcode',
    'city',
    'county',
    'state_code',
    'formatted',
    'address_line1',
    'address_line2',
    'website',
  ];

  /**
   * Distance in metres a place must move before it counts as changed.
   *
   * Geoapify reports slightly different coordinates for the same place
   * depending on the query. Observed: ~15 m for an OSM building outline
   * (Heritage Village), effectively 0 m for a single-point node. Comparing
   * exact values flags this as a change every time. 50 m sits well above the
   * observed shift and well below any real relocation. Large outlines (parks,
   * lakes) may need a higher value; tune from real data.
   */
  protected const COORDINATE_TOLERANCE_METERS = 50.0;

  /**
   * Compares an incoming feature with the stored one.
   *
   * @param array $incoming
   *   A single Geoapify Places feature, freshly fetched.
   * @param array|null $stored
   *   The feature from SourceFileWriter::readLatest(), or NULL if none.
   *
   * @return array
   *   - status: self::STATUS_NEW, STATUS_UNCHANGED or STATUS_CHANGED.
   *   - changed_fields: array of field name => ['old' => ..., 'new' => ...]
   *     (empty unless status is 'changed').
   */
  public function detect(array $incoming, ?array $stored): array {
    if ($stored === NULL) {
      return [
        'status' => self::STATUS_NEW,
        'changed_fields' => [],
      ];
    }

    $changed = [];

    $incoming_props = $incoming['properties'] ?? [];
    $stored_props = $stored['properties'] ?? [];

    foreach (self::COMPARED_PROPERTIES as $key) {
      $new = $incoming_props[$key] ?? NULL;
      $old = $stored_props[$key] ?? NULL;
      if ($new !== $old) {
        $changed[$key] = ['old' => $old, 'new' => $new];
      }
    }

    // Categories: order is irrelevant, so sort before comparing.
    $new_categories = $this->normalizeCategories($incoming_props['categories'] ?? []);
    $old_categories = $this->normalizeCategories($stored_props['categories'] ?? []);
    if ($new_categories !== $old_categories) {
      $changed['categories'] = ['old' => $old_categories, 'new' => $new_categories];
    }

    // Coordinates: GeoJSON order is [lon, lat]. Compared by distance moved,
    // not by exact value (see COORDINATE_TOLERANCE_METERS).
    $new_coords = $this->parseCoordinates($incoming['geometry']['coordinates'] ?? NULL);
    $old_coords = $this->parseCoordinates($stored['geometry']['coordinates'] ?? NULL);
    if ($new_coords === NULL || $old_coords === NULL) {
      if ($new_coords !== $old_coords) {
        $changed['coordinates'] = ['old' => $old_coords, 'new' => $new_coords];
      }
    }
    else {
      $moved = $this->distanceMeters($old_coords, $new_coords);
      if ($moved > self::COORDINATE_TOLERANCE_METERS) {
        $changed['coordinates'] = [
          'old' => $old_coords,
          'new' => $new_coords,
          'moved_meters' => round($moved, 1),
        ];
      }
    }

    return [
      'status' => empty($changed) ? self::STATUS_UNCHANGED : self::STATUS_CHANGED,
      'changed_fields' => $changed,
    ];
  }

  /**
   * Sorts and reindexes a categories array for order-insensitive comparison.
   */
  protected function normalizeCategories(array $categories): array {
    $categories = array_values(array_unique(array_map('strval', $categories)));
    sort($categories);
    return $categories;
  }

  /**
   * Returns a [lon, lat] float pair, or NULL if missing or malformed.
   */
  protected function parseCoordinates(mixed $coordinates): ?array {
    if (!is_array($coordinates) || count($coordinates) < 2) {
      return NULL;
    }
    return [(float) $coordinates[0], (float) $coordinates[1]];
  }

  /**
   * Great-circle distance in metres between two [lon, lat] pairs (haversine).
   */
  protected function distanceMeters(array $a, array $b): float {
    $earth_radius = 6371000.0;
    $lat1 = deg2rad($a[1]);
    $lat2 = deg2rad($b[1]);
    $d_lat = $lat2 - $lat1;
    $d_lon = deg2rad($b[0] - $a[0]);
    $h = sin($d_lat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($d_lon / 2) ** 2;
    return 2 * $earth_radius * asin(min(1.0, sqrt($h)));
  }

}
