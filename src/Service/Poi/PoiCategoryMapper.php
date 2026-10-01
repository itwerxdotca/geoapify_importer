<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Psr\Log\LoggerInterface;

/**
 * Maps a Geoapify place's categories to a real POI Category taxonomy term.
 *
 * SCOPE: only the "DIRECT" mapping type from the original spec — an exact
 * match between a Geoapify category string and a term explicitly tagged
 * with it. The spec also envisioned CONDITIONAL mappings (a category that
 * maps differently depending on other feature properties); that is NOT
 * implemented here, deliberately — no real conditional case has shown up
 * in fetched data yet, so there is nothing concrete to build against.
 *
 * ONLY CALL THIS for places PoiCategoryClassifier has already returned as
 * STATUS_PENDING_MAPPING. Places classified as 'ignored' should never
 * reach this mapper; places classified as 'needs_review' must go to a
 * human first, regardless of whether a tagged term happens to exist.
 *
 * WHY THIS QUERIES THE LIVE TAXONOMY, NOT A STATIC TABLE: devtop and
 * production do not share taxonomy content (confirmed this session) —
 * which terms exist, and what they're tagged with, can differ between
 * environments. This mapper has no built-in knowledge of any specific
 * term; it only knows how to ask "whatever taxonomy is live right now"
 * for a term tagged with a given category. The field itself
 * (field_geoapify_categories) is config and identical everywhere; its
 * VALUES are content, and are expected to differ.
 *
 * MATCHING: tries each of the place's categories, most specific first
 * (Geoapify lists categories general-to-specific; this reverses that
 * order). Relies on Geoapify's categories array already including the
 * full ancestor chain for every feature — confirmed real behaviour this
 * session (see PoiCategoryClassifier) — rather than independently
 * walking a single category's own dot-hierarchy. If that assumption
 * ever breaks for some category, the fix belongs in whichever class
 * depends on it, not duplicated speculatively here.
 */
class PoiCategoryMapper {

  /**
   * The POI Category vocabulary's machine name.
   *
   * Confirmed via direct inspection of the real field_poi_category
   * field's target_bundles on production.
   */
  protected const VOCABULARY = 'poi_category';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Finds a taxonomy term tagged with one of the given categories.
   *
   * @param array $categories
   *   The `properties.categories` array from a Geoapify Places feature.
   *
   * @return array|null
   *   NULL if no tagged term was found for any category — the caller
   *   should treat this as UNMAPPED (per spec: must not silently become
   *   an arbitrary category) and route to review. Otherwise:
   *   - term: the matched \Drupal\taxonomy\TermInterface.
   *   - matched_category: the specific category string that matched.
   */
  public function map(array $categories): ?array {
    foreach (array_reverse($categories) as $category) {
      $term = $this->findTermForCategory($category);
      if ($term !== NULL) {
        return [
          'term' => $term,
          'matched_category' => $category,
        ];
      }
    }

    return NULL;
  }

  /**
   * Looks up a single category against the live taxonomy.
   */
  protected function findTermForCategory(string $category): ?TermInterface {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $terms = $storage->loadByProperties([
      'vid' => self::VOCABULARY,
      'field_geoapify_categories' => $category,
    ]);

    if (empty($terms)) {
      return NULL;
    }

    if (count($terms) > 1) {
      $this->logger->warning('More than one @vocab term is tagged with Geoapify category @category; using the first one found (tid @tid). This is an editorial tagging conflict worth reviewing.', [
        '@vocab' => self::VOCABULARY,
        '@category' => $category,
        '@tid' => reset($terms)->id(),
      ]);
    }

    return reset($terms);
  }

}
