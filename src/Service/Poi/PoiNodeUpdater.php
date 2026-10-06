<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\geoapify_importer\Service\CoordinateTransformer;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Updates an EXISTING POI node from a fresh Geoapify feature.
 *
 * Applies the project's field ownership matrix, field by field:
 *
 * - Title: SOURCE_INITIAL. Set once at creation, NEVER touched here.
 *   Editors rename places; the importer must not undo that.
 * - field_poi_location: SOURCE_AUTHORITATIVE. Re-synced from the source,
 *   but only when the place has moved more than LOCATION_TOLERANCE_METERS
 *   (same 50 m used by ChangeDetector — Geoapify reports the same place at
 *   slightly different coordinates depending on the query, so exact
 *   comparison would rewrite locations constantly for no reason).
 * - field_poi_category: SOURCE_ASSISTED_REVIEW. Filled ONLY if currently
 *   empty. A value already on the node — especially one an editor chose —
 *   is never overwritten, even if the mapper would now pick differently.
 * - field_poi_address: SOURCE_ASSISTED_REVIEW. Filled ONLY if currently
 *   empty, and only when the Places data itself carries a housenumber and
 *   street. Deliberately does NOT use AddressVerifier's reverse-geocode
 *   fallback: on an update run that would re-spend a metered API call, on
 *   every run, for every place that has no address and never will (most
 *   parks). The fallback still applies at creation time.
 * - Everything else (description, hero image, meta description, tags, and
 *   anything else on the node): EDITORIAL_LOCKED / unmanaged. Never touched.
 * - field_canadian_towns: SOURCE_ASSISTED_REVIEW. Filled ONLY if currently
 *   empty, from the town the import found the place under ($town_tid: the
 *   boundary search, or the circle fallback). A town already on the node,
 *   especially one an editor chose, is never overwritten. The node holds
 *   ONE term, the town; its province is that term's parent.
 *
 * Unpublished or published, status is never changed.
 *
 * Every real change is saved as a NEW REVISION with a log message, so an
 * editor can see exactly what the importer changed and revert it.
 *
 * LIMITATION: the caller (PoiImportProcessor) only reaches this for places
 * that currently classify as pending_mapping AND map to a term. A node whose
 * place has since become needs_review/unmapped is not updated.
 */
class PoiNodeUpdater {

  /**
   * Distance a place must move before its stored location is re-synced.
   */
  protected const LOCATION_TOLERANCE_METERS = 50.0;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CoordinateTransformer $coordinateTransformer,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Updates one existing node, or reports that nothing needed changing.
   *
   * @param int $nid
   *   The existing node's ID (from PoiNodeCreator::findExistingNodeId()).
   * @param array $feature
   *   The full, current Geoapify feature (properties + geometry).
   * @param \Drupal\taxonomy\TermInterface $category_term
   *   The term PoiCategoryMapper resolved for this place.
   * @param bool $dry_run
   *   If TRUE, work out what WOULD change but save nothing.
   * @param int|null $town_tid
   *   The town the import found this place under. Filled in only if the
   *   node has no town yet.
   *
   * @return array
   *   - status: 'updated', 'unchanged', or 'error'.
   *   - nid: the node ID.
   *   - title: the node's current title (not on 'error' for a missing node).
   *   - fields: names of the fields changed (or, in a dry run, that would be).
   *   - message: present on 'error'.
   */
  public function update(int $nid, array $feature, TermInterface $category_term, bool $dry_run = FALSE, ?int $town_tid = NULL): array {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);

    if ($node === NULL || $node->bundle() !== 'point_of_interest') {
      return [
        'status' => 'error',
        'nid' => $nid,
        'message' => "Node {$nid} was not found or is not a point_of_interest.",
      ];
    }

    $properties = $feature['properties'] ?? [];

    $changes = array_filter([
      'field_poi_location' => $this->locationChange($node, $feature),
      'field_poi_category' => $this->categoryChange($node, $category_term),
      'field_canadian_towns' => $this->townChange($node, $town_tid),
      'field_poi_address' => $this->addressChange($node, $properties),
    ], static fn($value) => $value !== NULL);

    $result = [
      'status' => $changes === [] ? 'unchanged' : 'updated',
      'nid' => $nid,
      'title' => $node->getTitle(),
      'fields' => array_keys($changes),
    ];

    if ($changes === [] || $dry_run) {
      return $result;
    }

    try {
      foreach ($changes as $field => $value) {
        $node->set($field, $value);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Updated by Geoapify Importer: ' . implode(', ', array_keys($changes)));
      $node->setRevisionCreationTime($this->time->getRequestTime());
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update POI node @nid ("@title"): @message', [
        '@nid' => $nid,
        '@title' => $node->getTitle(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'status' => 'error',
        'nid' => $nid,
        'title' => $node->getTitle(),
        'message' => $e->getMessage(),
      ];
    }

    $this->logger->info('Updated POI node @nid ("@title"): @fields', [
      '@nid' => $nid,
      '@title' => $node->getTitle(),
      '@fields' => implode(', ', array_keys($changes)),
    ]);

    return $result;
  }

  /**
   * New location value, or NULL if the stored one is still good enough.
   */
  protected function locationChange(object $node, array $feature): ?array {
    if (!$node->hasField('field_poi_location')) {
      return NULL;
    }

    $coordinates = $feature['geometry']['coordinates'] ?? NULL;
    if (!is_array($coordinates)) {
      return NULL;
    }

    try {
      $new = $this->coordinateTransformer->toGeolocationValue($coordinates);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Skipping location update for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $current = $node->get('field_poi_location')->getValue();
    if (!empty($current) && isset($current[0]['lat'], $current[0]['lng'])) {
      $moved = $this->distanceMeters(
        (float) $current[0]['lat'],
        (float) $current[0]['lng'],
        $new['lat'],
        $new['lng']
      );
      if ($moved <= self::LOCATION_TOLERANCE_METERS) {
        return NULL;
      }
    }

    return $new;
  }

  /**
   * Town value to fill in, or NULL if there is none to give or the node has one.
   */
  protected function townChange(object $node, ?int $town_tid): ?array {
    if ($town_tid === NULL || !$node->hasField('field_canadian_towns') || !$node->get('field_canadian_towns')->isEmpty()) {
      return NULL;
    }
    return ['target_id' => $town_tid];
  }

  /**
   * Category value to fill in, or NULL if the node already has one.
   */
  protected function categoryChange(object $node, TermInterface $category_term): ?array {
    if (!$node->hasField('field_poi_category') || !$node->get('field_poi_category')->isEmpty()) {
      return NULL;
    }
    return ['target_id' => $category_term->id()];
  }

  /**
   * Address value to fill in, or NULL.
   *
   * Only when the node's address is empty AND the Places data itself has
   * both a housenumber and a street (the same rule AddressVerifier applies
   * first, without its metered reverse-geocode fallback).
   */
  protected function addressChange(object $node, array $properties): ?array {
    if (!$node->hasField('field_poi_address') || !$node->get('field_poi_address')->isEmpty()) {
      return NULL;
    }

    $housenumber = $properties['housenumber'] ?? NULL;
    $street = $properties['street'] ?? NULL;
    if (empty($housenumber) || empty($street)) {
      return NULL;
    }

    return [
      'country_code' => 'CA',
      'address_line1' => $housenumber . ' ' . $street,
      'postal_code' => $properties['postcode'] ?? NULL,
    ];
  }

  /**
   * Great-circle distance in metres (haversine).
   *
   * Same formula as ChangeDetector and TownBoundaryResolver, which each keep
   * their own copy. Three copies is a reasonable cleanup candidate (one
   * shared helper), left alone so already-verified classes weren't touched.
   */
  protected function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth_radius = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $d_phi = $phi2 - $phi1;
    $d_lambda = deg2rad($lon2 - $lon1);
    $h = sin($d_phi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($d_lambda / 2) ** 2;
    return 2 * $earth_radius * asin(min(1.0, sqrt($h)));
  }

}
