<?php

namespace Drupal\geoapify_importer\Service;

/**
 * Converts Geoapify coordinates into the Geolocation module's field shape.
 *
 * Shared ingestion infrastructure, not POI-specific: both POI
 * (field_poi_location) and Listing (field_street_location) use the
 * Geolocation module for their location field, confirmed via direct
 * inspection of both content types' real field definitions.
 *
 * WHY THIS EXISTS: Geoapify returns coordinates as GeoJSON
 * `geometry.coordinates`, an array in [longitude, latitude] order. The
 * Geolocation module's field setter expects an associative array with
 * 'lat' and 'lng' keys. Getting the order wrong silently produces a
 * plausible-looking but wrong location (swapped lat/lng still parses as
 * numbers, it just points somewhere else on Earth) — this class exists
 * so that conversion happens in exactly one place, tested, rather than
 * being repeated ad hoc wherever a node gets created or updated.
 *
 * VERIFIED FIELD SHAPE: confirmed by reading a real canadian_towns term's
 * field_geolocation value on this install: the module stores 'lat' and
 * 'lng' as plain decimal degrees, and computes 'lat_sin', 'lat_cos',
 * 'lng_rad', and 'value' itself on save. Only 'lat' and 'lng' need to be
 * set; the rest are the module's own derived cache, not ours to produce.
 */
class CoordinateTransformer {

  /**
   * Converts Geoapify GeoJSON coordinates to a Geolocation field value.
   *
   * @param array $geojson_coordinates
   *   A GeoJSON coordinates pair, [longitude, latitude], e.g. from a
   *   Geoapify feature's `geometry.coordinates`.
   *
   * @return array
   *   ['lat' => float, 'lng' => float], suitable for setting directly on
   *   a Geolocation field, e.g.:
   *   $node->set('field_poi_location', $transformer->toGeolocationValue($coords));
   *
   * @throws \InvalidArgumentException
   *   If the input is not a 2-element numeric array, or the values fall
   *   outside valid latitude/longitude ranges (a strong signal the
   *   caller passed [lat, lon] instead of GeoJSON's [lon, lat] order).
   */
  public function toGeolocationValue(array $geojson_coordinates): array {
    if (count($geojson_coordinates) < 2) {
      throw new \InvalidArgumentException('Expected a [longitude, latitude] pair, got an array with fewer than 2 elements.');
    }

    [$lon, $lat] = $geojson_coordinates;

    if (!is_numeric($lon) || !is_numeric($lat)) {
      throw new \InvalidArgumentException('Coordinates must be numeric.');
    }

    $lon = (float) $lon;
    $lat = (float) $lat;

    if ($lat < -90.0 || $lat > 90.0) {
      throw new \InvalidArgumentException(
        "Latitude {$lat} is out of range. This usually means [lat, lon] was passed instead of GeoJSON's [lon, lat] order."
      );
    }

    if ($lon < -180.0 || $lon > 180.0) {
      throw new \InvalidArgumentException("Longitude {$lon} is out of range.");
    }

    return [
      'lat' => $lat,
      'lng' => $lon,
    ];
  }

}
