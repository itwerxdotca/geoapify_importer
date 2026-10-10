<?php

namespace Drupal\geoapify_importer\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\geoapify_importer\Service\TownQueueManager;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for the Geoapify Importer module.
 */
class GeoapifyImporterHooks {

  /**
   * How many towns one fill adds to the queue.
   *
   * About 1.5 days of work at the default 2,500-request limit, so the queue
   * is never short while cron is running, yet a changed setting or a newly
   * imported batch of towns takes effect within a couple of days.
   */
  public const FILL_SIZE = 100;

  public function __construct(
    protected TownQueueManager $queueManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Keeps the town queue topped up, when scheduled import is switched on.
   *
   * Core runs hook_cron before it works through the queues, so towns added
   * here are processed in the same cron run.
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!$this->queueManager->isScheduledImportEnabled()) {
      return;
    }
    if ($this->queueManager->queueSize() > 0) {
      return;
    }
    // Filling a queue nobody can work through today only creates a backlog
    // of "queued" marks; wait for the budget.
    if (!$this->queueManager->hasBudgetForTown()) {
      return;
    }

    $result = $this->queueManager->enqueueDue(self::FILL_SIZE);
    if ($result['queued'] > 0) {
      $this->logger->info('Queued @count town(s) for scheduled import.', ['@count' => $result['queued']]);
    }
  }

}
