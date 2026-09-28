<?php

namespace Drupal\geoapify_importer\Service\Poi;

/**
 * Classifies a Geoapify place's categories against a small IGNORE list.
 *
 * SCOPE (deliberately narrow): this class only answers one question —
 * "does this place's category tree match something we never want to
 * become a POI at all?" It does NOT attempt DIRECT/CONDITIONAL mapping
 * to taxonomy terms yet; that requires the dynamic, taxonomy-field-driven
 * mapper (still to be built), since devtop and production do not share
 * the same taxonomy content and any mapping logic must query the live
 * taxonomy on whichever environment it runs on.
 *
 * Matching is hierarchy-aware: a category matches an ignored branch if
 * it is EXACTLY that branch, or is a dot-separated child of it. E.g. an
 * ignored branch of 'tourism.attraction.artwork' matches
 * 'tourism.attraction.artwork.sculpture' (a real, confirmed Geoapify
 * category), 'tourism.attraction.artwork.mural', etc. — without needing
 * every leaf category listed individually.
 *
 * This IGNORE list is intentionally code, not a taxonomy field: "should
 * this category ever become a POI" is a data-quality/business rule, not
 * an editorial taxonomy decision — see project notes.
 */
class PoiCategoryClassifier {

  public const STATUS_IGNORED = 'ignored';
  public const STATUS_PENDING_MAPPING = 'pending_mapping';

  /**
   * Geoapify category branches that should never become a POI.
   *
   * Confirmed against Geoapify's real, current category list (833
   * categories, pulled live via list_place_categories). Start small;
   * add branches here only once confirmed against real fetched data,
   * not speculatively.
   *
   * @var string[]
   */
  protected const IGNORED_BRANCHES = [
    // Individual art installations (murals, sculptures, statues) —
    // confirmed via real data: Fort McMurray's "Seven Grandfather
    // Teachings" sculptures (Humility, Truth, Honesty, Wisdom, Courage,
    // Respect, Love) were each returned as separate features under this
    // branch. These are not standalone tourist-attraction POIs.
    'tourism.attraction.artwork',
  ];

  /**
   * Classifies a place's categories against the IGNORE list.
   *
   * @param array $categories
   *   The `properties.categories` array from a Geoapify Places feature
   *   (e.g. ['tourism', 'tourism.attraction', 'tourism.attraction.artwork',
   *   'tourism.attraction.artwork.sculpture']).
   *
   * @return array
   *   - status: self::STATUS_IGNORED or self::STATUS_PENDING_MAPPING.
   *   - matched_branch: the ignored branch that matched, or NULL.
   */
  public function classify(array $categories): array {
    foreach ($categories as $category) {
      foreach (self::IGNORED_BRANCHES as $ignored_branch) {
        if ($category === $ignored_branch || str_starts_with($category, $ignored_branch . '.')) {
          return [
            'status' => self::STATUS_IGNORED,
            'matched_branch' => $ignored_branch,
            'matched_category' => $category,
          ];
        }
      }
    }

    return [
      'status' => self::STATUS_PENDING_MAPPING,
      'matched_branch' => NULL,
      'matched_category' => NULL,
    ];
  }

}
