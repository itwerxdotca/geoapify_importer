<?php

namespace Drupal\geoapify_importer\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geoapify_importer\Service\TownQueueManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the unattended town import queue.
 *
 * Draining the queue by hand uses core's own command, not one of ours:
 * `drush queue:run geoapify_importer_town` (add --time-limit=N to cap it).
 */
final class GeoapifyQueueCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly TownQueueManager $queueManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('geoapify_importer.town_queue_manager'));
  }

  /**
   * Adds towns that are due to the import queue.
   *
   * Towns never imported successfully go first, then the ones imported
   * longest ago. Towns imported within the last 90 days and towns already
   * queued are skipped unless --force is given.
   *
   * @option limit
   *   The most towns to queue. Default 100.
   * @option town
   *   Only towns whose name contains this text.
   * @option force
   *   Queue towns even if imported recently or already queued.
   *
   * @usage drush geoapify:queue-towns --limit=60
   *   Queue the next 60 due towns.
   * @usage drush geoapify:queue-towns --town="Taber" --force
   *   Queue Taber again even though it is up to date.
   */
  #[CLI\Command(name: 'geoapify:queue-towns', aliases: ['geo-queue'])]
  #[CLI\Option(name: 'limit', description: 'The most towns to queue.')]
  #[CLI\Option(name: 'town', description: 'Only towns whose name contains this text.')]
  #[CLI\Option(name: 'force', description: 'Queue even if imported recently or already queued.')]
  public function queueTowns(
    array $options = [
      'limit' => 100,
      'town' => NULL,
      'force' => FALSE,
    ],
  ): void {
    $limit = max(1, (int) $options['limit']);
    $result = $this->queueManager->enqueueDue($limit, $options['town'] ?: NULL, (bool) $options['force']);

    $this->io()->table(['Result', 'Towns'], [
      ['Queued', $result['queued']],
      ['Considered', $result['considered']],
      ['Skipped: imported recently', $result['skipped_recent']],
      ['Skipped: already queued', $result['skipped_queued']],
      ['Skipped: no coordinates', $result['skipped_not_importable']],
    ]);

    if ($result['queued'] > 0 && $result['queued'] <= 10) {
      $this->io()->text('Queued: ' . implode(', ', $result['names']));
    }

    if ($result['queued'] === 0) {
      $this->io()->warning('Nothing was queued.');
      return;
    }
    $this->io()->success(sprintf('%d town(s) queued. Cron will import them, or run: drush queue:run %s', $result['queued'], TownQueueManager::QUEUE_NAME));
  }

  /**
   * Shows where the whole town import stands.
   */
  #[CLI\Command(name: 'geoapify:queue-status', aliases: ['geo-status'])]
  public function queueStatus(): void {
    $s = $this->queueManager->status();

    $fmt = fn(?int $t) => $t ? gmdate('Y-m-d H:i', $t) . ' UTC' : 'never';
    $this->io()->table(['Item', 'Value'], [
      ['Scheduled import (cron)', $s['scheduled_enabled'] ? 'ON' : 'off'],
      ['Towns in vocabulary', $s['towns_total']],
      ['Towns without coordinates (skipped)', $s['towns_not_importable']],
      ['Towns imported at least once', $s['towns_imported']],
      ['Towns due a refresh (90+ days)', $s['towns_stale']],
      ['Towns with failed attempts', $s['towns_failing']],
      ['Towns not yet imported', $s['towns_remaining']],
      ['Items waiting in the queue', $s['queue_size']],
      ['Last successful town import', $fmt($s['last_success'])],
      ['Requests used today', $s['requests_today'] . ($s['requests_limit'] !== NULL ? ' of ' . $s['requests_limit'] : ' (no limit set)')],
      ['Requests left today', $s['requests_remaining'] ?? 'unlimited'],
      ['Requests needed per town (at most)', $s['requests_per_town']],
      ['POI nodes created (last run per town, summed)', $s['totals']['nodes_created']],
      ['Places needing review (last run per town, summed)', $s['totals']['needs_review']],
      ['Places unmapped (last run per town, summed)', $s['totals']['unmapped']],
    ]);
  }

}
