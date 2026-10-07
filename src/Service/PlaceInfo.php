<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Clean contact and detail values for a place, from its Places properties.
 *
 * Shared infrastructure, not POI-specific: Listings can use the same values.
 * Reads only what the Places search already returned (no extra API call),
 * since the stored records carry website, phone, email, hours, operator and
 * facilities wherever OpenStreetMap has them.
 *
 * Only usable values are returned; a key is simply absent when there is
 * nothing good to give. Nothing is guessed: a website without an http(s)
 * scheme, or an email that does not validate, is dropped.
 *
 * Amenities: a vocabulary's terms list, in a mapping field, the Geoapify
 * facility names that mean a place has that amenity. Like the category
 * mapping, this is data-driven: no term IDs in code.
 */
class PlaceInfo {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the usable values found in a place's Geoapify properties.
   *
   * @return array
   *   Any of: website (string URL), phone, email, opening_hours (the raw OSM
   *   string), operator, facilities (list of facility names that are true).
   */
  public function fromProperties(array $properties): array {
    $info = [];

    $website = $this->firstValue($properties['website'] ?? NULL);
    if ($website !== '' && preg_match('#^https?://#i', $website) === 1 && filter_var($website, FILTER_VALIDATE_URL) !== FALSE) {
      $info['website'] = $website;
    }

    $phone = $this->firstValue($properties['contact']['phone'] ?? NULL);
    if ($phone !== '') {
      $info['phone'] = $phone;
    }

    $email = $this->firstValue($properties['contact']['email'] ?? NULL, '/[;,]/');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE) {
      $info['email'] = $email;
    }

    $hours = trim((string) ($properties['opening_hours'] ?? ''));
    if ($hours !== '') {
      $info['opening_hours'] = $hours;
    }

    $operator = trim((string) ($properties['operator'] ?? ''));
    if ($operator !== '') {
      $info['operator'] = mb_substr($operator, 0, 255);
    }

    $facilities = [];
    foreach (($properties['facilities'] ?? []) as $name => $value) {
      if ($value === TRUE) {
        $facilities[] = (string) $name;
      }
    }
    if ($facilities !== []) {
      $info['facilities'] = $facilities;
    }

    return $info;
  }

  /**
   * IDs of the terms in a vocabulary that match any of the given facilities.
   *
   * @param string[] $facilities
   *   Geoapify facility names that are true for the place.
   * @param string $vocabulary
   *   The vocabulary holding the amenity terms.
   * @param string $field
   *   The term field listing the facility names each term matches.
   *
   * @return int[]
   *   Matching term IDs; empty if none, or if the vocabulary has no such field.
   */
  public function amenityTermIds(array $facilities, string $vocabulary = 'poi_amenities', string $field = 'field_geoapify_facilities'): array {
    if ($facilities === []) {
      return [];
    }
    try {
      $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vocabulary)
        ->condition($field, $facilities, 'IN')
        ->execute();
    }
    catch (\Throwable $e) {
      return [];
    }
    return array_map('intval', array_values($ids));
  }

  /**
   * First non-empty value of a possibly multi-value string, trimmed.
   */
  protected function firstValue(mixed $value, string $split = '/;/'): string {
    if (is_string($value) === FALSE) {
      return '';
    }
    $parts = preg_split($split, $value);
    return trim((string) ($parts[0] ?? ''));
  }

}
