<?php

namespace Drupal\geoapify_importer\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geoapify_importer\Service\Poi\UnmappedCategoryReport;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands that report on what the importer has stored.
 *
 * Read-only: nothing here calls the Geoapify API or changes any content.
 */
final class GeoapifyReportCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly UnmappedCategoryReport $report,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('geoapify_importer.unmapped_category_report'));
  }

  /**
   * Ranks the Geoapify categories that have no POI Category term yet.
   *
   * Reads every stored place, applies the importer's own classification and
   * mapping, and lists the unmapped categories with the most named places
   * first. Mapping those first turns the most waiting places into nodes. A
   * place without a name is counted separately: the importer cannot create
   * a node for it.
   *
   * To map one: tag a POI Category term with the category (or with a parent
   * such as tourism.sights.place_of_worship to cover everything beneath it).
   *
   * @option limit
   *   How many categories to list. Default 40.
   * @option places
   *   Only read this many stored places, for a quick look.
   * @option review
   *   Also list the places held for manual review, grouped by branch.
   *
   * @usage drush geoapify:unmapped-categories
   *   The 40 most worthwhile categories to map.
   * @usage drush geoapify:unmapped-categories --limit=100 --review
   *   A longer list, plus the review breakdown.
   */
  #[CLI\Command(name: 'geoapify:unmapped-categories', aliases: ['geo-unmapped'])]
  #[CLI\Option(name: 'limit', description: 'How many categories to list.')]
  #[CLI\Option(name: 'places', description: 'Only read this many stored places.')]
  #[CLI\Option(name: 'review', description: 'Also list places held for manual review.')]
  public function unmappedCategories(
    array $options = [
      'limit' => 40,
      'places' => NULL,
      'review' => FALSE,
    ],
  ): void {
    $limit = max(1, (int) $options['limit']);
    $max_places = $options['places'] !== NULL ? max(1, (int) $options['places']) : NULL;

    $r = $this->report->build($max_places);

    $this->io()->table(['Stored places', 'Count'], [
      ['Read', $r['scanned']],
      ['Could not be read', $r['unreadable']],
      ['Ignored (not POI-eligible)', $r['ignored']],
      ['Already mapped to a term', $r['mapped']],
      ['Held for manual review', $r['needs_review']['places']],
      ['Unmapped', $r['unmapped']['places'] . ' (' . $r['unmapped']['named'] . ' with a name)'],
    ]);

    $rows = [];
    $shown = 0;
    $hidden = 0;
    foreach ($r['unmapped']['categories'] as $category => $g) {
      if ($shown >= $limit) {
        $hidden++;
        continue;
      }
      $rows[] = [$category, $g['named'], $g['places'], implode('; ', $g['samples'])];
      $shown++;
    }
    if ($rows) {
      $this->io()->text('Unmapped categories, most named places first:');
      $this->io()->table(['Category', 'Named', 'Places', 'Examples'], $rows);
      if ($hidden > 0) {
        $this->io()->text(sprintf('%d more categories not shown (use --limit).', $hidden));
      }
    }
    else {
      $this->io()->success('Every stored place is mapped, ignored or held for review.');
    }

    if ($options['review'] && $r['needs_review']['branches']) {
      $review_rows = [];
      foreach ($r['needs_review']['branches'] as $branch => $g) {
        $review_rows[] = [$branch, $g['named'], $g['places'], implode('; ', $g['samples'])];
      }
      $this->io()->text('Held for manual review, by branch:');
      $this->io()->table(['Branch', 'Named', 'Places', 'Examples'], $review_rows);
    }
  }

}
