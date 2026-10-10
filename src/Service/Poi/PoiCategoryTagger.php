<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Adds a Geoapify category to a POI Category term's mapping field.
 *
 * This is the same edit a person makes by hand (add a value to the term's
 * "Geoapify categories" field), made safe and repeatable from the command
 * line, so it can be applied identically on devtop and production.
 *
 * It only ever ADDS a value, and refuses rather than guesses:
 * - the term is found by its exact name; no match, or more than one, stops;
 * - a category already tagged on a DIFFERENT term is a conflict and stops
 *   (PoiCategoryMapper would otherwise pick one arbitrarily and log a
 *   warning);
 * - a category already on this term is reported, not duplicated.
 *
 * The category text is checked for shape only (lowercase letters, digits,
 * underscores, hyphens, dot-separated). It is NOT checked against Geoapify's
 * list, so a typo is stored as-is and simply never matches a place.
 */
class PoiCategoryTagger {

  public const STATUS_ADDED = 'added';
  public const STATUS_WOULD_ADD = 'would_add';
  public const STATUS_ALREADY = 'already';
  public const STATUS_CONFLICT = 'conflict';
  public const STATUS_TERM_NOT_FOUND = 'term_not_found';
  public const STATUS_TERM_AMBIGUOUS = 'term_ambiguous';
  public const STATUS_INVALID = 'invalid';
  public const STATUS_FIELD_MISSING = 'field_missing';

  protected const VOCABULARY = 'poi_category';
  protected const FIELD = 'field_geoapify_categories';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Tags a term with a Geoapify category.
   *
   * @param string $category
   *   The Geoapify category, e.g. memorial.cemetery.
   * @param string $term_name
   *   The exact name of a POI Category term, e.g. "Churches".
   * @param bool $dry_run
   *   If TRUE, reports what would happen and saves nothing.
   *
   * @return array
   *   - status: one of the STATUS_* constants.
   *   - term: the term's name, when one was found.
   *   - tid: the term id, when one was found.
   *   - other: for a conflict, the name of the term that already has it.
   *   - tids: for an ambiguous name, the matching term ids.
   */
  public function tag(string $category, string $term_name, bool $dry_run = FALSE): array {
    $category = trim($category);
    $term_name = trim($term_name);

    if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_-]+)*$/', $category)) {
      return ['status' => self::STATUS_INVALID];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $matches = $storage->loadByProperties(['vid' => self::VOCABULARY, 'name' => $term_name]);
    if (count($matches) === 0) {
      return ['status' => self::STATUS_TERM_NOT_FOUND];
    }
    if (count($matches) > 1) {
      return ['status' => self::STATUS_TERM_AMBIGUOUS, 'tids' => array_map('intval', array_keys($matches))];
    }

    $term = reset($matches);
    $result = ['term' => $term->label(), 'tid' => (int) $term->id()];

    if (!$term->hasField(self::FIELD)) {
      return $result + ['status' => self::STATUS_FIELD_MISSING];
    }

    $values = $term->get(self::FIELD)->getValue();
    foreach ($values as $item) {
      if (($item['value'] ?? NULL) === $category) {
        return $result + ['status' => self::STATUS_ALREADY];
      }
    }

    $holders = $storage->loadByProperties(['vid' => self::VOCABULARY, self::FIELD => $category]);
    foreach ($holders as $holder) {
      if ((int) $holder->id() !== (int) $term->id()) {
        return $result + ['status' => self::STATUS_CONFLICT, 'other' => $holder->label()];
      }
    }

    if ($dry_run) {
      return $result + ['status' => self::STATUS_WOULD_ADD];
    }

    $values[] = ['value' => $category];
    $term->set(self::FIELD, $values);
    $term->save();

    return $result + ['status' => self::STATUS_ADDED];
  }

}
