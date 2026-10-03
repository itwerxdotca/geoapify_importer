<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Psr\Log\LoggerInterface;

/**
 * Turns TownImportRunner's classified places into real POI nodes.
 *
 * SCOPE: this is the POI-specific second half of the import pipeline.
 * TownImportRunner (shared infrastructure, src/Service/) handles fetch,
 * identity, change detection, storage, and classification — it returns a
 * 'processed' list of every place it saw, each with its classification
 * result, but takes no action beyond storing the raw file. This class
 * consumes that list and decides what happens next, per place:
 *
 * - ignored: nothing. Never becomes a POI.
 * - needs_review: nothing, by design. Requires a human decision this
 *   pipeline cannot make (see PoiCategoryClassifier).
 * - pending_mapping: run PoiCategoryMapper. If it resolves to a real
 *   term, run PoiNodeCreator. If it does not (UNMAPPED), do nothing —
 *   per spec, an unmapped category must never silently become an
 *   arbitrary POI category. The place stays as a raw file, available
 *   for mapping once a term is tagged and this is re-run.
 *
 * A future Listing importer would consume the SAME processed list from
 * TownImportRunner but run its own Listing-specific mapper/creator here
 * instead — this is why TownImportRunner itself knows nothing about
 * taxonomy mapping or node creation.
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
   * @param array $processed
   *   The 'processed' array from a TownImportRunner::importTown() result
   *   — each entry has 'key', 'feature', and 'classification'.
   * @param bool $dry_run
   *   If TRUE, mapping is still attempted (for accurate reporting) but
   *   PoiNodeCreator is never called — nothing is persisted.
   *
   * @return array
   *   Counts: ignored, needs_review, unmapped, nodes_created,
   *   nodes_skipped_existing, errors.
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
        // Unrecognized status — treat conservatively as needing review
        // rather than silently skipping or guessing.
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
        // Would create, but nothing is persisted in a dry run.
        $counts['nodes_created']++;
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
        // Expected, not a failure: some real OSM features (a school
        // field, an unnamed park segment) have no name at all. Counted
        // separately so it isn't mistaken for a genuine error.
        $counts['no_name']++;
      }
      else {
        $counts['errors']++;
      }
    }

    return $counts;
  }

}
