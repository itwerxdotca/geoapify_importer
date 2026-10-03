<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\geoapify_importer\Service\AddressVerifier;
use Drupal\geoapify_importer\Service\CoordinateTransformer;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates POI nodes from classified, mapped Geoapify features.
 *
 * SCOPE, deliberately narrow:
 * - CREATE ONLY. This does not update existing nodes. Updating requires
 *   applying the field ownership matrix per field (title never changes
 *   after creation; location always re-syncs; category/address need
 *   review-aware logic) — that is a distinct, not-yet-built piece.
 * - Only call this for a place that PoiCategoryMapper has already
 *   resolved to a real term. A place still at 'needs_review' or
 *   'UNMAPPED' should not get a node yet — it stays as a raw file until
 *   a human resolves its classification. This service has no opinion on
 *   classification; that decision must already be made before calling it.
 * - field_canadian_towns is NOT set by this version. Geoapify's
 *   properties.city is a free-text string; resolving it to a real
 *   canadian_towns taxonomy term is a separate, not-yet-built piece
 *   (distinct from TownBoundaryResolver, which goes the other direction
 *   — town-to-search-area, not place-to-town). Left unset, not guessed.
 * - field_poi_address is populated only when AddressVerifier reports
 *   STATUS_VERIFIED (housenumber + street present — see AddressVerifier).
 *   Otherwise left empty rather than populated with an unverified guess,
 *   consistent with the field ownership matrix's SOURCE_ASSISTED_REVIEW
 *   default for this field.
 * - Every created node is UNPUBLISHED. This is new, automatically
 *   generated content; nothing here decides it's ready for a visitor to
 *   see. Publishing is an editorial decision, not an import one.
 * - The Address module's field value structure (country_code,
 *   address_line1, postal_code, etc.) is the module's standard,
 *   documented shape, but has NOT been confirmed by an actual write on
 *   this install the way field_poi_location was. Verify with a real
 *   test write before trusting this in production, the same way the
 *   Geolocation shape was confirmed earlier this session.
 */
class PoiNodeCreator {

  /**
   * The field storing the PlaceIdentity/storage key a node came from.
   *
   * Used for duplicate detection: before creating, check whether a node
   * already exists with this value. A dev-only field as of this writing
   * — see project notes on syncing local field config with production.
   */
  protected const STORAGE_KEY_FIELD = 'field_source_storage_key';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CoordinateTransformer $coordinateTransformer,
    protected AddressVerifier $addressVerifier,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Creates a POI node, or reports why one wasn't created.
   *
   * @param string $storage_key
   *   The PlaceIdentity storage key for this place (e.g. 'osm-w-...').
   * @param array $feature
   *   The full Geoapify feature (properties + geometry).
   * @param \Drupal\taxonomy\TermInterface $category_term
   *   The POI Category term already resolved by PoiCategoryMapper.
   *
   * @return array
   *   - status: 'created', 'skipped_existing', or 'error'.
   *   - nid: the node ID, if created or already existing.
   *   - address_verified: bool, whether field_poi_address was populated.
   *   - message: present on 'error'.
   */
  public function create(string $storage_key, array $feature, TermInterface $category_term): array {
    $existing_nid = $this->findExistingNodeId($storage_key);
    if ($existing_nid !== NULL) {
      return [
        'status' => 'skipped_existing',
        'nid' => $existing_nid,
      ];
    }

    $properties = $feature['properties'] ?? [];
    $title = $properties['name'] ?? NULL;

    if (empty($title)) {
      return [
        'status' => 'error',
        'message' => 'Feature has no name to use as a title.',
      ];
    }

    $values = [
      'type' => 'point_of_interest',
      'title' => $title,
      'status' => 0,
      self::STORAGE_KEY_FIELD => $storage_key,
      'field_poi_category' => ['target_id' => $category_term->id()],
    ];

    $coordinates = $feature['geometry']['coordinates'] ?? NULL;
    if (is_array($coordinates)) {
      try {
        $values['field_poi_location'] = $this->coordinateTransformer->toGeolocationValue($coordinates);
      }
      catch (\InvalidArgumentException $e) {
        $this->logger->warning('Could not set location for "@title" (@key): @message', [
          '@title' => $title,
          '@key' => $storage_key,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $address_verification = $this->addressVerifier->verify($properties);
    $address_verified = $address_verification['status'] === AddressVerifier::STATUS_VERIFIED;

    if ($address_verified) {
      $values['field_poi_address'] = [
        'country_code' => 'CA',
        'address_line1' => $properties['housenumber'] . ' ' . $properties['street'],
        'postal_code' => $properties['postcode'] ?? NULL,
      ];
    }

    try {
      $node = Node::create($values);
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create POI node for "@title" (@key): @message', [
        '@title' => $title,
        '@key' => $storage_key,
        '@message' => $e->getMessage(),
      ]);
      return [
        'status' => 'error',
        'message' => $e->getMessage(),
      ];
    }

    return [
      'status' => 'created',
      'nid' => (int) $node->id(),
      'address_verified' => $address_verified,
    ];
  }

  /**
   * Finds an existing node's ID for a storage key, if one exists.
   */
  protected function findExistingNodeId(string $storage_key): ?int {
    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'point_of_interest')
      ->condition(self::STORAGE_KEY_FIELD, $storage_key)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (empty($nids)) {
      return NULL;
    }

    return (int) reset($nids);
  }

}
