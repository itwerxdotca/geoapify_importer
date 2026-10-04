<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Psr\Log\LoggerInterface;

/**
 * Turns TownImportRunner's classified places into real POI nodes.
 */
class PoiImportProcessor {

  public function __construct(
    protected PoiCategoryMapper $mapper,
    protected PoiNodeCreator $creator,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Processes every place TownImportRunner collected for one town.
   *
   * @return array
   *   Counts: ignored, needs_review, unmapped, nodes_created,
   *   nodes_skipped_existing, no_name, errors.
   */
  public function process(array $processed, bool $dry_run = FALSE): array {
    $counts = [
      'ignored' => 0,
      'needs_review' => 0,
      'unmapped' => 0,
      'nodes_created' => 0,
      'nodes_skipped_existing' => 0,
      'no_name' => 0,
      'errors' => 0,
    ];

    foreach ($processed as $entry) {
      $status = $entry['classification']['status'] ?? NULL;

      if ($status === PoiCategoryClassifier::STATUS_IGNORED) {
        $counts['ignored']++;
        continue;
      }

      if ($status === PoiCategoryClassifier::STATUS_NEEDS_REVIEW) {
        $counts['needs_review']++;
        continue;
      }

      if ($status !== PoiCategoryClassifier::STATUS_PENDING_MAPPING) {
        $counts['needs_review']++;
        continue;
      }

      try {
        $categories = $entry['feature']['properties']['categories'] ?? [];
        $mapped = $this->mapper->map($categories);
      }
      catch (\Throwable $e) {
        $counts['errors']++;
        $this->logger->error('Mapping failed for @key: @message', [
          '@key' => $entry['key'],
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      if ($mapped === NULL) {
        $counts['unmapped']++;
        continue;
      }

      if ($dry_run) {
        $existing_nid = $this->creator->findExistingNodeId($entry['key']);
        if ($existing_nid !== NULL) {
          $counts['nodes_skipped_existing']++;
        }
        elseif (empty($entry['feature']['properties']['name'] ?? NULL)) {
          $counts['no_name']++;
        }
        else {
          $counts['nodes_created']++;
        }
        continue;
      }

      try {
        $result = $this->creator->create($entry['key'], $entry['feature'], $mapped['term']);
      }
      catch (\Throwable $e) {
        $counts['errors']++;
        $this->logger->error('Node creation failed for @key: @message', [
          '@key' => $entry['key'],
          '@message' => $e->getMessage(),
        ]);
        continue;
      }

      if ($result['status'] === 'created') {
        $counts['nodes_created']++;
      }
      elseif ($result['status'] === 'skipped_existing') {
        $counts['nodes_skipped_existing']++;
      }
      elseif (($result['message'] ?? NULL) === 'Feature has no name to use as a title.') {
        $counts['no_name']++;
      }
      else {
        $counts['errors']++;
      }
    }

    return $counts;
  }

}
