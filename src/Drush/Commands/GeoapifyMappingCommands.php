<?php

namespace Drupal\geoapify_importer\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geoapify_importer\Service\Poi\PoiCategoryTagger;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for mapping a Geoapify category to a POI Category term.
 */
final class GeoapifyMappingCommands extends DrushCommands implements ContainerInjectionInterface {

  public function __construct(
    private readonly PoiCategoryTagger $tagger,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('geoapify_importer.poi_category_tagger'));
  }

  /**
   * Maps a Geoapify category to a POI Category term.
   *
   * Adds the category to the term's Geoapify categories field, the same as
   * editing the term by hand. Only adds; refuses if the term name is not
   * found or is ambiguous, or if another term already has that category.
   * Tagging a parent category (such as memorial) covers everything beneath
   * it, unless a more specific category is tagged with a different term.
   *
   * @param string $category
   *   The Geoapify category, e.g. memorial.cemetery.
   * @param string $term
   *   The exact POI Category term name, e.g. "Cemeteries & Burial Grounds".
   *
   * @option dry-run
   *   Report what would happen and change nothing.
   *
   * @usage drush geoapify:map-category religion.place_of_worship.christianity "Churches"
   *   Map Christian places of worship to the Churches term.
   * @usage drush geoapify:map-category memorial "Cemeteries & Burial Grounds" --dry-run
   *   Check a mapping without saving it.
   */
  #[CLI\Command(name: 'geoapify:map-category', aliases: ['geo-map'])]
  #[CLI\Argument(name: 'category', description: 'The Geoapify category, e.g. memorial.cemetery.')]
  #[CLI\Argument(name: 'term', description: 'The exact POI Category term name.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would happen and change nothing.')]
  public function mapCategory(string $category, string $term, array $options = ['dry-run' => FALSE]): int {
    $r = $this->tagger->tag($category, $term, (bool) $options['dry-run']);
    $io = $this->io();

    switch ($r['status']) {
      case PoiCategoryTagger::STATUS_ADDED:
        $io->success(sprintf('%s -> "%s" (term %d) saved.', $category, $r['term'], $r['tid']));
        return self::EXIT_SUCCESS;

      case PoiCategoryTagger::STATUS_WOULD_ADD:
        $io->text(sprintf('Dry run: would map %s -> "%s" (term %d).', $category, $r['term'], $r['tid']));
        return self::EXIT_SUCCESS;

      case PoiCategoryTagger::STATUS_ALREADY:
        $io->text(sprintf('%s is already mapped to "%s". Nothing to do.', $category, $r['term']));
        return self::EXIT_SUCCESS;

      case PoiCategoryTagger::STATUS_CONFLICT:
        $io->error(sprintf('%s is already mapped to a different term, "%s". Not changed. Remove it there first if it should move.', $category, $r['other']));
        return self::EXIT_FAILURE;

      case PoiCategoryTagger::STATUS_TERM_NOT_FOUND:
        $io->error(sprintf('No POI Category term is named "%s". Names must match exactly.', $term));
        return self::EXIT_FAILURE;

      case PoiCategoryTagger::STATUS_TERM_AMBIGUOUS:
        $io->error(sprintf('More than one POI Category term is named "%s" (term ids %s). Not changed.', $term, implode(', ', $r['tids'])));
        return self::EXIT_FAILURE;

      case PoiCategoryTagger::STATUS_FIELD_MISSING:
        $io->error(sprintf('Term "%s" has no Geoapify categories field. The module install may be incomplete.', $r['term']));
        return self::EXIT_FAILURE;

      default:
        $io->error(sprintf('"%s" is not a valid category. Use Geoapify\'s lowercase dotted form, e.g. memorial.cemetery.', $category));
        return self::EXIT_FAILURE;
    }
  }

}
