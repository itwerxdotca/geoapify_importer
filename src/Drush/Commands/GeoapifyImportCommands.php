<?php

namespace Drupal\geoapify_importer\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geoapify_importer\Service\TownImportRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for running the per-town Geoapify import.
 *
 * SCOPE: ingestion only — fetch, identity, change detection,
 * classification (reported), and storage. Does NOT assign taxonomy terms
 * or create nodes. See TownImportRunner class docblock.
 */
final class GeoapifyImportCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly TownImportRunner $runner,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('geoapify_importer.town_import_runner'),
    );
  }

  /**
   * Imports places for every canadian_towns term with coordinates set.
   *
   * For each town: resolves a real administrative boundary via
   * TownBoundaryResolver, falling back to a 15km circle if that fails;
   * queries a curated set of POI-relevant categories one at a time
   * (never combined — see TownImportRunner); and stores new/changed
   * places via SourceFileWriter. Unchanged places are skipped. Every
   * place is classified for visibility, but classification never
   * prevents a write.
   *
   * @option town
   *   Only import a town whose name contains this text (case-insensitive).
   *   For testing a single town.
   * @option towns-limit
   *   Stop after this many towns. For testing/partial runs.
   * @option dry-run
   *   Run the full pipeline (fetch, identity, change detection,
   *   classification) but do not write anything to the private
   *   filesystem. Safe to use for a full trial run.
   *
   * @usage drush geoapify:import --town="Fort McMurray"
   *   Import a single town by name, for testing.
   * @usage drush geoapify:import --towns-limit=5 --dry-run
   *   Trial-run the first 5 importable towns without writing anything.
   * @usage drush geoapify:import
   *   Full run across every importable town. Long-running; makes one API
   *   call per category per town, with a courtesy delay between calls.
   */
  #[CLI\Command(name: 'geoapify:import', aliases: ['geo-import'])]
  #[CLI\Option(name: 'town', description: 'Only import a town whose name contains this text.')]
  #[CLI\Option(name: 'towns-limit', description: 'Stop after this many towns.')]
  #[CLI\Option(name: 'dry-run', description: 'Run the pipeline without writing any files.')]
  #[CLI\Usage(name: 'drush geoapify:import --town="Fort McMurray"', description: 'Import a single town by name, for testing.')]
  #[CLI\Usage(name: 'drush geoapify:import --towns-limit=5 --dry-run', description: 'Trial-run the first 5 towns without writing anything.')]
  public function import(
    array $options = [
      'town' => NULL,
      'towns-limit' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $town_filter = $options['town'] ? mb_strtolower($options['town']) : NULL;
    $towns_limit = $options['towns-limit'] !== NULL ? (int) $options['towns-limit'] : NULL;
    $dry_run = (bool) $options['dry-run'];

    if ($dry_run) {
      $this->io()->note('Dry run: no files will be written.');
    }

    $towns = $this->runner->loadImportableTowns();

    if ($town_filter !== NULL) {
      $towns = array_filter($towns, fn($town) => str_contains(mb_strtolower($town->label()), $town_filter));
    }

    if (empty($towns)) {
      $this->io()->warning('No matching towns with coordinates found.');
      return;
    }

    $this->io()->text(sprintf('Importing %d town(s)%s.', count($towns), $towns_limit !== NULL ? " (limited to {$towns_limit})" : ''));

    $totals = [
      'towns' => 0,
      'towns_failed' => 0,
      'new' => 0,
      'changed' => 0,
      'unchanged' => 0,
      'errors' => 0,
      'categories_failed' => 0,
      'boundary_used' => 0,
      'circle_used' => 0,
      'possibly_truncated' => 0,
    ];

    $count = 0;
    foreach ($towns as $town) {
      if ($towns_limit !== NULL && $count >= $towns_limit) {
        break;
      }

      $summary = $this->runner->importTown($town, $dry_run);
      $count++;
      $totals['towns']++;

      if (isset($summary['fatal_error'])) {
        $totals['towns_failed']++;
        $this->io()->error(sprintf('%s: FAILED — %s', $summary['town'], $summary['fatal_error']));
        continue;
      }

      $totals['new'] += $summary['new'];
      $totals['changed'] += $summary['changed'];
      $totals['unchanged'] += $summary['unchanged'];
      $totals['errors'] += $summary['errors'];
      $totals['categories_failed'] += $summary['categories_failed'];
      $totals[$summary['search_method'] . '_used']++;
      $totals['possibly_truncated'] += count($summary['possibly_truncated']);

      $this->io()->text(sprintf(
        '%s [%s]: %d new, %d changed, %d unchanged, %d errors (%d/%d categories ok)%s',
        $summary['town'],
        $summary['search_method'],
        $summary['new'],
        $summary['changed'],
        $summary['unchanged'],
        $summary['errors'],
        $summary['categories_queried'],
        $summary['categories_queried'] + $summary['categories_failed'],
        $summary['possibly_truncated'] ? ' — possibly truncated: ' . implode(', ', $summary['possibly_truncated']) : ''
      ));
    }

    $this->io()->table(
      ['Metric', 'Value'],
      [
        ['Towns processed', $totals['towns']],
        ['Towns failed entirely', $totals['towns_failed']],
        ['Search via boundary', $totals['boundary_used']],
        ['Search via circle fallback', $totals['circle_used']],
        ['New places', $totals['new']],
        ['Changed places', $totals['changed']],
        ['Unchanged (skipped)', $totals['unchanged']],
        ['Per-place errors', $totals['errors']],
        ['Failed category requests', $totals['categories_failed']],
        ['Category results possibly truncated', $totals['possibly_truncated']],
      ]
    );

    if ($totals['towns_failed'] > 0 || $totals['errors'] > 0 || $totals['categories_failed'] > 0) {
      $this->io()->warning('Run completed with errors. See above and the geoapify_importer log channel for detail.');
    }
    else {
      $this->io()->success('Run completed with no errors.');
    }
  }

}
