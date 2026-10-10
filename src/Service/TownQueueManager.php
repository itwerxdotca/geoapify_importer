<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Decides which towns get imported next, and remembers how each one went.
 *
 * This is the scheduling half of the unattended import. The other half is
 * the TownImportWorker queue worker, which imports one town per queue item.
 * Drupal cron fills this queue (see GeoapifyImporterHooks) and works through
 * it, so the importer keeps going with nobody running a command.
 *
 * WHY A QUEUE: a full pass is about 26,000 towns at roughly 40 API requests
 * each. At the default limit of 2,500 requests a day that is around 60 towns
 * a day, so a pass takes months. One long-running command cannot do that; a
 * queue that resumes where it stopped, and pauses when the day's request
 * budget is used up, can.
 *
 * PER-TOWN STATE lives in the key-value store, not in a custom SQL table
 * (the project does not use custom tables for importer data). One small
 * record per town id:
 * - last_success: timestamp of the last import in which every category
 *   request succeeded. A town with failed category requests is NOT counted
 *   as done, so it is picked up again.
 * - last_attempt: timestamp of the last time a worker tried it.
 * - queued: timestamp set when queued, cleared when a worker finishes with
 *   it. Stops the same town being queued twice. A mark older than
 *   QUEUED_STALE_AFTER is ignored, so a queue that was emptied by hand
 *   does not leave towns stuck.
 * - failures: consecutive failed attempts, reset on success.
 * - not_importable: set for terms without coordinates (e.g. province-level
 *   parents) so they are not loaded again on every fill.
 * - the last result's counts, for the status report.
 *
 * ORDER: towns never successfully imported go first (those never tried
 * before those that failed), then the stalest. There is no population or
 * importance field on canadian_towns to rank by; if one is added, this is
 * the one place to use it.
 */
class TownQueueManager {

  /**
   * The queue name. Must equal the queue worker plugin id.
   */
  public const QUEUE_NAME = 'geoapify_importer_town';

  /**
   * Key-value collection holding the per-town records.
   */
  public const STATE_COLLECTION = 'geoapify_importer.towns';

  /**
   * A town counts as up to date this long after a successful import.
   *
   * Hardcoded, per the project preference for hardcoded pipeline constants.
   */
  public const REFRESH_AFTER_SECONDS = 90 * 86400;

  /**
   * A "queued" mark older than this is treated as stale and ignored.
   */
  public const QUEUED_STALE_AFTER_SECONDS = 3 * 86400;

  /**
   * Requests assumed for the boundary lookup of a town not seen before.
   *
   * The boundary result is cached on disk (TownBoundaryResolver), so only
   * the first import of a town pays for it. Place-search requests are
   * counted separately, from the category list.
   */
  public const BOUNDARY_REQUEST_ALLOWANCE = 3;

  /**
   * How many term ids are loaded at once while looking for importable towns.
   */
  protected const LOAD_CHUNK_SIZE = 50;

  public function __construct(
    protected QueueFactory $queueFactory,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TownImportRunner $runner,
    protected GeoapifyRateLimiter $rateLimiter,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether the unattended, cron-driven import is switched on.
   *
   * Off unless explicitly enabled, so installing or updating the module can
   * never start spending API credits by itself.
   */
  public function isScheduledImportEnabled(): bool {
    return (bool) $this->configFactory->get('geoapify_importer.settings')->get('scheduled_import_enabled');
  }

  /**
   * How many requests importing one town is expected to need at most.
   */
  public function requestsPerTown(): int {
    return count(TownImportRunner::POI_SEARCH_CATEGORIES) + self::BOUNDARY_REQUEST_ALLOWANCE;
  }

  /**
   * Whether today's remaining request budget covers another whole town.
   *
   * Checking before a town starts, rather than letting the limiter stop it
   * part way, avoids half-imported towns. The limiter itself stays the hard
   * stop: if usage outside the importer, or address look-ups for new nodes,
   * push a town over the line anyway, the failed category requests are
   * counted and the town is retried later, not marked done.
   */
  public function hasBudgetForTown(): bool {
    $config = $this->configFactory->get('geoapify_importer.settings');
    if (!$config->get('geoapify_rate_limit_enabled')) {
      return TRUE;
    }
    return $this->rateLimiter->canMakeRequest()
      && $this->rateLimiter->remainingToday() >= $this->requestsPerTown();
  }

  /**
   * How many items are waiting in the queue.
   */
  public function queueSize(): int {
    return (int) $this->queueFactory->get(self::QUEUE_NAME)->numberOfItems();
  }

  /**
   * Queues towns that are due, up to a limit.
   *
   * @param int $limit
   *   The most towns to queue in this call.
   * @param string|null $name_filter
   *   Only towns whose name contains this text. For testing one town.
   * @param bool $force
   *   Ignore "imported recently" and "already queued", and queue even towns
   *   that are up to date. Does not override towns marked not importable.
   *
   * @return array
   *   Counts: queued, considered, skipped_recent, skipped_queued,
   *   skipped_not_importable, and the names of the towns queued.
   */
  public function enqueueDue(int $limit, ?string $name_filter = NULL, bool $force = FALSE): array {
    $result = [
      'queued' => 0,
      'considered' => 0,
      'skipped_recent' => 0,
      'skipped_queued' => 0,
      'skipped_not_importable' => 0,
      'names' => [],
    ];
    if ($limit <= 0) {
      return $result;
    }

    $now = $this->time->getRequestTime();
    $store = $this->keyValueFactory->get(self::STATE_COLLECTION);
    $records = $store->getAll();

    $tids = $this->townIds($name_filter);

    // Split into the order described in the class docblock. Each entry is
    // [sort bucket, sort value, tid]; lower sorts first.
    $candidates = [];
    foreach ($tids as $tid) {
      $record = $records[$tid] ?? [];
      $result['considered']++;

      // Even --force does not queue a term that was found to have no
      // coordinates; there is nothing for the importer to search around.
      if (!empty($record['not_importable'])) {
        $result['skipped_not_importable']++;
        continue;
      }

      $queued_at = (int) ($record['queued'] ?? 0);
      if (!$force && $queued_at > 0 && ($now - $queued_at) < self::QUEUED_STALE_AFTER_SECONDS) {
        $result['skipped_queued']++;
        continue;
      }

      $last_success = (int) ($record['last_success'] ?? 0);
      if (!$force && $last_success > 0 && ($now - $last_success) < self::REFRESH_AFTER_SECONDS) {
        $result['skipped_recent']++;
        continue;
      }

      if ($last_success === 0) {
        // Never imported successfully: never tried (0) before failed ones.
        $candidates[] = [0, (int) ($record['last_attempt'] ?? 0), $tid];
      }
      else {
        $candidates[] = [1, $last_success, $tid];
      }
    }

    usort($candidates, fn(array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queue->createQueue();
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $ordered = array_column($candidates, 2);
    foreach (array_chunk($ordered, self::LOAD_CHUNK_SIZE) as $chunk) {
      if ($result['queued'] >= $limit) {
        break;
      }
      $terms = $storage->loadMultiple($chunk);
      foreach ($chunk as $tid) {
        if ($result['queued'] >= $limit) {
          break;
        }
        $term = $terms[$tid] ?? NULL;
        $record = $records[$tid] ?? [];

        if ($term === NULL || !$this->runner->isImportable($term)) {
          $record['not_importable'] = TRUE;
          $store->set($tid, $record);
          $result['skipped_not_importable']++;
          continue;
        }

        $queue->createItem(['tid' => (int) $tid]);
        $record['queued'] = $now;
        $store->set($tid, $record);
        $result['queued']++;
        $result['names'][] = $term->label();
      }
    }

    return $result;
  }

  /**
   * Records that a town was imported, and how it went.
   *
   * @param int $tid
   *   The town term id.
   * @param array $summary
   *   The summary returned by TownImportRunner::importTown().
   * @param array $poi_counts
   *   The counts returned by PoiImportProcessor::process().
   *
   * @return bool
   *   TRUE if the town counts as done (every category request succeeded).
   */
  public function recordResult(int $tid, array $summary, array $poi_counts): bool {
    $store = $this->keyValueFactory->get(self::STATE_COLLECTION);
    $record = $store->get($tid, []);
    $now = $this->time->getRequestTime();

    $complete = empty($summary['fatal_error']) && ((int) ($summary['categories_failed'] ?? 0)) === 0;

    $record['last_attempt'] = $now;
    $record['queued'] = NULL;
    $record['search_method'] = $summary['search_method'] ?? NULL;
    $record['places_seen'] = (int) ($summary['places_seen'] ?? 0);
    $record['categories_failed'] = (int) ($summary['categories_failed'] ?? 0);
    $record['nodes_created'] = (int) ($poi_counts['nodes_created'] ?? 0);
    $record['nodes_updated'] = (int) ($poi_counts['nodes_updated'] ?? 0);
    $record['needs_review'] = (int) ($poi_counts['needs_review'] ?? 0);
    $record['unmapped'] = (int) ($poi_counts['unmapped'] ?? 0);

    if ($complete) {
      $record['last_success'] = $now;
      $record['failures'] = 0;
    }
    else {
      $record['failures'] = (int) ($record['failures'] ?? 0) + 1;
    }

    $store->set($tid, $record);
    return $complete;
  }

  /**
   * Records that trying a town threw, so it is not retried straight away.
   */
  public function recordFailure(int $tid): void {
    $store = $this->keyValueFactory->get(self::STATE_COLLECTION);
    $record = $store->get($tid, []);
    $record['last_attempt'] = $this->time->getRequestTime();
    $record['queued'] = NULL;
    $record['failures'] = (int) ($record['failures'] ?? 0) + 1;
    $store->set($tid, $record);
  }

  /**
   * Clears the queued mark of a town that no longer exists or has no
   * coordinates, and flags it so it is not queued again.
   */
  public function markNotImportable(int $tid): void {
    $store = $this->keyValueFactory->get(self::STATE_COLLECTION);
    $record = $store->get($tid, []);
    $record['queued'] = NULL;
    $record['not_importable'] = TRUE;
    $store->set($tid, $record);
  }

  /**
   * A summary of where the whole import stands.
   */
  public function status(): array {
    $now = $this->time->getRequestTime();
    $records = $this->keyValueFactory->get(self::STATE_COLLECTION)->getAll();

    $imported = 0;
    $stale = 0;
    $failing = 0;
    $not_importable = 0;
    $last_success = 0;
    $totals = ['nodes_created' => 0, 'nodes_updated' => 0, 'needs_review' => 0, 'unmapped' => 0];

    foreach ($records as $record) {
      if (!empty($record['not_importable'])) {
        $not_importable++;
        continue;
      }
      $success = (int) ($record['last_success'] ?? 0);
      if ($success > 0) {
        $imported++;
        $last_success = max($last_success, $success);
        if (($now - $success) >= self::REFRESH_AFTER_SECONDS) {
          $stale++;
        }
      }
      if ((int) ($record['failures'] ?? 0) > 0) {
        $failing++;
      }
      foreach (array_keys($totals) as $key) {
        $totals[$key] += (int) ($record[$key] ?? 0);
      }
    }

    $town_count = count($this->townIds(NULL));
    $config = $this->configFactory->get('geoapify_importer.settings');
    $limit_enabled = (bool) $config->get('geoapify_rate_limit_enabled');

    return [
      'scheduled_enabled' => $this->isScheduledImportEnabled(),
      'towns_total' => $town_count,
      'towns_not_importable' => $not_importable,
      'towns_imported' => $imported,
      'towns_stale' => $stale,
      'towns_failing' => $failing,
      'towns_remaining' => max(0, $town_count - $not_importable - $imported),
      'queue_size' => $this->queueSize(),
      'last_success' => $last_success ?: NULL,
      'requests_today' => $this->rateLimiter->getTodayCount(),
      'requests_limit' => $limit_enabled ? (int) $config->get('geoapify_daily_request_limit') : NULL,
      'requests_remaining' => $limit_enabled ? $this->rateLimiter->remainingToday() : NULL,
      'requests_per_town' => $this->requestsPerTown(),
      'totals' => $totals,
    ];
  }

  /**
   * Term ids of the canadian_towns vocabulary, in id order.
   *
   * @return int[]
   */
  protected function townIds(?string $name_filter): array {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'canadian_towns')
      ->sort('tid');
    if ($name_filter !== NULL && $name_filter !== '') {
      $query->condition('name', $name_filter, 'CONTAINS');
    }
    return array_map('intval', array_values($query->execute()));
  }

}
