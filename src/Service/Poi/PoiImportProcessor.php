<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\geoapify_importer\Service\PlaceInfo;
use Psr\Log\LoggerInterface;

/**
 * Turns TownImportRunner's classified places into POI nodes.
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
 * - pending_mapping: run PoiCategoryMapper. If it does not resolve to a
 *   term (UNMAPPED), do nothing — an unmapped category must never silently
 *   become an arbitrary POI category. If it does:
 *     - no node exists yet for this place: PoiNodeCreator creates one
 *       (unpublished).
 *     - a node already exists: PoiNodeUpdater brings it up to date,
 *       applying the field ownership matrix (see PoiNodeUpdater).
 *
 * A future Listing importer would consume the SAME processed list from
 * TownImportRunner but run its own Listing-specific mapper/creator/updater
 * here instead — this is why TownImportRunner itself knows nothing about
 * taxonomy mapping or node handling.
 */
class PoiImportProcessor {

  public function __construct(
    protected PoiCategoryMapper $mapper,
    protected PoiNodeCreator $creator,
    protected PoiNodeUpdater $updater,
    protected PlaceInfo $placeInfo,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The POI detail field values for a place, from its Places properties.
   *
   * The POI field names live here because this class is the POI-specific
   * orchestrator; the creator and updater only apply what they are handed.
   */
  protected function detailFieldValues(array $properties): array {
    $info = $this->placeInfo->fromProperties($properties);
    $values = [];
    if (isset($info['website'])) {
      $values['field_poi_website'] = ['uri' => $info['website']];
    }
    if (isset($info['phone'])) {
      $values['field_poi_phone'] = $info['phone'];
    }
    if (isset($info['email'])) {
      $values['field_poi_email'] = $info['email'];
    }
    if (isset($info['opening_hours'])) {
      $values['field_poi_opening_hours'] = $info['opening_hours'];
    }
    if (isset($info['operator'])) {
      $values['field_poi_operator'] = $info['operator'];
    }
    $tids = $this->placeInfo->amenityTermIds($info['facilities'] ?? []);
    if ($tids !== []) {
      $values['field_poi_amenities'] = array_map(static fn($tid) => ['target_id' => $tid], $tids);
    }
    return $values;
  }

  /**
   * Processes every place TownImportRunner collected for one town.
   *
   * @param array $processed
   *   The 'processed' array from a TownImportRunner::importTown() result
   *   — each entry has 'key', 'feature', 'classification', and 'town_tid'
   *   (the town the place was found under; passed on to node creation and
   *   updating, which connect the node to that town).
   * @param bool $dry_run
   *   If TRUE, everything is worked out and reported exactly as a real run
   *   would, but nothing is created or saved.
   *
   * @return array
   *   Counts: ignored, needs_review, unmapped, nodes_created, nodes_updated,
   *   nodes_unchanged, no_name, errors. Plus 'updates': a list of
   *   ['nid', 'title', 'fields'] for every node updated (or, in a dry run,
   *   that would be).
   */
  public function process(array $processed, bool $dry_run = FALSE): array {
    $counts = [
      'ignored' => 0,
      'needs_review' => 0,
      'unmapped' => 0,
      'nodes_created' => 0,
      'nodes_updated' => 0,
      'nodes_unchanged' => 0,
      'no_name' => 0,
      'errors' => 0,
      'updates' => [],
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

      $field_values = $this->detailFieldValues($entry['feature']['properties'] ?? []);

      // Existing node: update it. Works the same in a dry run — the updater
      // is told not to save, so the report matches what a real run does.
      $existing_nid = $this->creator->findExistingNodeId($entry['key']);
      if ($existing_nid !== NULL) {
        try {
          $result = $this->updater->update($existing_nid, $entry['feature'], $mapped['term'], $dry_run, $entry['town_tid'] ?? NULL, $field_values);
        }
        catch (\Throwable $e) {
          $counts['errors']++;
          $this->logger->error('Node update failed for @key: @message', [
            '@key' => $entry['key'],
            '@message' => $e->getMessage(),
          ]);
          continue;
        }

        if ($result['status'] === 'updated') {
          $counts['nodes_updated']++;
          $counts['updates'][] = [
            'nid' => $result['nid'],
            'title' => $result['title'] ?? '',
            'fields' => $result['fields'] ?? [],
          ];
        }
        elseif ($result['status'] === 'unchanged') {
          $counts['nodes_unchanged']++;
        }
        else {
          $counts['errors']++;
          $this->logger->error('Node update failed for @key: @message', [
            '@key' => $entry['key'],
            '@message' => $result['message'] ?? 'unknown error',
          ]);
        }
        continue;
      }

      // No node yet. In a dry run, mirror create()'s own "no name" check so
      // the report matches what a real run would do.
      if ($dry_run) {
        if (empty($entry['feature']['properties']['name'] ?? NULL)) {
          $counts['no_name']++;
        }
        else {
          $counts['nodes_created']++;
        }
        continue;
      }

      try {
        $result = $this->creator->create($entry['key'], $entry['feature'], $mapped['term'], $entry['town_tid'] ?? NULL, $field_values);
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
        // Only reachable if a node appeared between the existence check above
        // and create() — treat as already in place.
        $counts['nodes_unchanged']++;
      }
      elseif (($result['message'] ?? NULL) === 'Feature has no name to use as a title.') {
        // Expected, not a failure: some real OSM features (a school field,
        // an unnamed park segment) have no name at all.
        $counts['no_name']++;
      }
      else {
        $counts['errors']++;
      }
    }

    return $counts;
  }

}
