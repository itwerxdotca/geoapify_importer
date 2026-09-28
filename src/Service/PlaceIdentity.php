<?php

namespace Drupal\geoapify_importer\Service;

/**
 * Derives the stable storage key for a Geoapify feature.
 *
 * Why this exists: Geoapify's `properties.place_id` is NOT stable across
 * requests. It embeds the feature's coordinates as binary doubles, and those
 * coordinates shift slightly between requests (observed: ~15 m for a
 * building polygon, ~1 mm float noise for a node), so the same place gets a
 * different place_id and would be stored, and later imported, twice.
 *
 * The OpenStreetMap type and ID (`properties.datasource.raw.osm_type` and
 * `.osm_id`) were identical across those same requests, so they are used as
 * the primary identity. Features without a usable OSM reference fall back to
 * the place_id, which is stable only if the coordinates are; such cases are
 * prefixed differently so they are easy to find and review.
 *
 * Shared ingestion infrastructure: nothing here is POI-specific.
 *
 * Known limitation: if someone deletes and recreates an OSM object it gets a
 * new OSM ID and will look like a new place. The spec's fallback duplicate
 * detection (coordinates, normalized name + geographic context) is the
 * intended safety net for that and is not yet implemented.
 */
class PlaceIdentity {

  /**
   * OSM element types Geoapify reports: node, way, relation.
   */
  protected const OSM_TYPES = ['n', 'w', 'r'];

  /**
   * Returns a filesystem-safe storage key for a feature.
   *
   * Keys look like `osm-w-306707925` or, as a fallback, `gid-<place_id>`.
   * Only [a-zA-Z0-9_-] appear, which SourceFileWriter accepts unchanged.
   *
   * @param array $feature
   *   A single Geoapify Places feature.
   *
   * @return string
   *   The storage key.
   *
   * @throws \InvalidArgumentException
   *   If the feature has neither an OSM reference nor a place_id.
   */
  public function keyFor(array $feature): string {
    $properties = $feature['properties'] ?? [];
    $raw = $properties['datasource']['raw'] ?? [];

    $osm_type = $raw['osm_type'] ?? NULL;
    $osm_id = $raw['osm_id'] ?? NULL;

    if (is_string($osm_type) && in_array($osm_type, self::OSM_TYPES, TRUE)
      && (is_int($osm_id) || (is_string($osm_id) && ctype_digit($osm_id)))) {
      return 'osm-' . $osm_type . '-' . $osm_id;
    }

    $place_id = $properties['place_id'] ?? NULL;
    if (is_string($place_id) && $place_id !== '') {
      return 'gid-' . $place_id;
    }

    throw new \InvalidArgumentException('Feature has neither a usable OSM reference nor a place_id.');
  }

}
