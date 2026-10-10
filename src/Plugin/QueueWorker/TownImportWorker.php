<?php

namespace Drupal\geoapify_importer\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\geoapify_importer\Service\Poi\PoiImportProcessor;
use Drupal\geoapify_importer\Service\TownImportRunner;
use Drupal\geoapify_importer\Service\TownQueueManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports one town per queue item: the unattended version of
 * `drush geoapify:import --town=...`.
 *
 * It does exactly what the command does for a town (TownImportRunner, then
 * PoiImportProcessor), so a scheduled run and a manual run cannot drift
 * apart. Only the surrounding behaviour differs:
 *
 * - Before starting a town it checks that today's request budget covers a
 *   whole town. If not it throws SuspendQueueException: Drupal releases the
 *   item untouched and stops working this queue for this cron run, and the
 *   next cron run after midnight UTC (when the counter resets) carries on.
 * - It never lets an exception escape for a town-specific problem. Drupal
 *   leaves an item whose worker threw an ordinary exception in the queue to
 *   be retried on every cron run, so one bad town would otherwise be hit
 *   again and again. The failure is logged and recorded instead, and the
 *   town is queued again by a later fill, not retried immediately.
 *
 * `cron: time` is how many seconds one cron run spends on this queue. A
 * town takes tens of seconds (about 40 requests plus node creation), so a
 * run handles a handful of towns. Cron needs to run often (every 5-15
 * minutes, from the system crontab via drush) to use the daily budget.
 */
#[QueueWorker(
  id: TownQueueManager::QUEUE_NAME,
  title: new TranslatableMarkup('Geoapify town import'),
  cron: ['time' => 120],
)]
class TownImportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected TownImportRunner $runner,
    protected PoiImportProcessor $poiProcessor,
    protected TownQueueManager $queueManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('geoapify_importer.town_import_runner'),
      $container->get('geoapify_importer.poi_import_processor'),
      $container->get('geoapify_importer.town_queue_manager'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.geoapify_importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $tid = (int) ($data['tid'] ?? 0);
    if ($tid <= 0) {
      // A malformed item can never succeed. Dropping it (returning) is right.
      $this->logger->warning('Dropped a queue item with no town id.');
      return;
    }

    if (!$this->queueManager->hasBudgetForTown()) {
      throw new SuspendQueueException('The daily Geoapify request budget has no room left for another town.');
    }

    $town = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if ($town === NULL || !$this->runner->isImportable($town)) {
      $this->logger->notice('Skipped town @tid: it no longer exists or has no coordinates.', ['@tid' => $tid]);
      $this->queueManager->markNotImportable($tid);
      return;
    }

    try {
      $summary = $this->runner->importTown($town);
      $poi_counts = $this->poiProcessor->process($summary['processed']);
      $complete = $this->queueManager->recordResult($tid, $summary, $poi_counts);

      $this->logger->info('Town @name (@tid) imported via @method: @new new, @changed changed, @unchanged unchanged places; POI @created created, @updated updated; @failed failed category requests@incomplete.', [
        '@name' => $town->label(),
        '@tid' => $tid,
        '@method' => $summary['search_method'],
        '@new' => $summary['new'],
        '@changed' => $summary['changed'],
        '@unchanged' => $summary['unchanged'],
        '@created' => $poi_counts['nodes_created'],
        '@updated' => $poi_counts['nodes_updated'],
        '@failed' => $summary['categories_failed'],
        '@incomplete' => $complete ? '' : ' (not marked complete, will be queued again)',
      ]);
    }
    catch (\Throwable $e) {
      $this->queueManager->recordFailure($tid);
      $this->logger->error('Importing town @name (@tid) failed: @message', [
        '@name' => $town->label(),
        '@tid' => $tid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
