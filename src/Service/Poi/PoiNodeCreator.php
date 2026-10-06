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
 * - CREATE ONLY. This does not update existing nodes.
 * - Only call this for a place that PoiCategoryMapper has already
 *   resolved to a real term.
 * - field_canadian_towns is set from the town the import was searching,
 *   when the caller passes $town_tid (boundary search first, 15 km circle
 *   fallback; see TownImportRunner). The node holds ONE term, the town;
 *   the province is that term's parent. With no $town_tid, no town is set.
 * - field_poi_address is populated only when AddressVerifier reports
 *   STATUS_VERIFIED.
 * - Every created node is UNPUBLISHED.
 */
class PoiNodeCreator {

  /**
   * The field storing the PlaceIdentity/storage key a node came from.
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
   * @param int|null $town_tid
   *   The canadian_towns term the import was searching when it found this
   *   place. When given, it is set as the node's town.
   *
   * @return array
   *   - status: 'created', 'skipped_existing', or 'error'.
   *   - nid: the node ID, if created or already existing.
   *   - address_verified: bool, whether field_poi_address was populated.
   *   - message: present on 'error'.
   */
  public function create(string $storage_key, array $feature, TermInterface $category_term, ?int $town_tid = NULL): array {
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

    if ($town_tid !== NULL) {
      $values['field_canadian_towns'] = ['target_id' => $town_tid];
    }

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
   *
   * Public so callers (notably PoiImportProcessor's dry-run path) can
   * check existence without triggering an actual write.
   */
  public function findExistingNodeId(string $storage_key): ?int {
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
