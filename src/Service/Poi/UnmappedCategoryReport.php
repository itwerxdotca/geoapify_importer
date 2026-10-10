<?php

namespace Drupal\geoapify_importer\Service\Poi;

use Drupal\geoapify_importer\Service\SourceFileWriter;

/**
 * Reads every stored place and reports which Geoapify categories have no
 * POI Category term yet, so mapping effort goes where it pays off.
 *
 * Read-only: it makes no API calls and creates or changes no nodes, files or
 * terms. It applies the same decisions the importer does, in the same order
 * (PoiCategoryClassifier first, then PoiCategoryMapper), so what it calls
 * "unmapped" is exactly what the import counts as unmapped.
 *
 * RANKING: by places that have a NAME, not by all places. The importer
 * cannot create a node for a place without a name, so mapping a category
 * whose places are all unnamed produces nothing. Totals are still reported.
 *
 * Each place is grouped under its MOST SPECIFIC category (the last in the
 * place's list, e.g. tourism.sights.place_of_worship.church). A term can be
 * tagged with that, or with any ancestor (tourism.sights.place_of_worship)
 * to cover everything beneath it; the report shows the specific one because
 * it is the clearest thing to read, and the ancestor choice is an editorial
 * decision.
 */
class UnmappedCategoryReport {

  /**
   * The top-level branches of Geoapify's category tree.
   *
   * Taken from the full category list pulled live from Geoapify (833
   * categories, 46 branches). Geoapify also puts "conditions" such as
   * wheelchair.yes into a place's categories array (documented: the
   * categories field "can take values of supported categories and supported
   * conditions"); those are not place types and are not under any of these
   * branches, so they are skipped when choosing a place's main category.
   * If Geoapify adds a branch, places under it fall back to the last
   * category in their list (see primaryCategory()).
   *
   * @var string[]
   */
  protected const TREE_ROOTS = [
    'accommodation',
    'activity',
    'adult',
    'airport',
    'amenity',
    'beach',
    'administrative',
    'postal_code',
    'political',
    'low_emission_zone',
    'building',
    'camping',
    'catering',
    'commercial',
    'education',
    'childcare',
    'emergency',
    'entertainment',
    'healthcare',
    'heritage',
    'traffic_signals',
    'highway',
    'leisure',
    'man_made',
    'maritime',
    'memorial',
    'natural',
    'national_park',
    'office',
    'parking',
    'pet',
    'continent',
    'country',
    'island',
    'populated_place',
    'power',
    'production',
    'public_transport',
    'railway',
    'religion',
    'rental',
    'service',
    'ski',
    'sport',
    'tourism',
    'waterway',
  ];

  /**
   * How many example names are kept per category.
   */
  protected const SAMPLE_NAMES = 3;

  public function __construct(
    protected SourceFileWriter $writer,
    protected PoiCategoryClassifier $classifier,
    protected PoiCategoryMapper $mapper,
  ) {}

  /**
   * Scans the stored places and totals them up.
   *
   * @param int|null $max_places
   *   Stop after this many stored places (for a quick look). NULL scans all.
   *
   * @return array
   *   - scanned: stored places read.
   *   - unreadable: stored files that could not be read or decoded.
   *   - ignored: places the classifier marks not POI-eligible.
   *   - mapped: places that already have a term.
   *   - unmapped: ['places' => n, 'named' => n, 'categories' => [...]]
   *   - needs_review: ['places' => n, 'named' => n, 'branches' => [...]]
   *   Each categories/branches entry is keyed by the category (or branch)
   *   and holds places, named and up to three sample names. Sorted by named
   *   places, then all places, descending.
   */
  public function build(?int $max_places = NULL): array {
    $report = [
      'scanned' => 0,
      'unreadable' => 0,
      'ignored' => 0,
      'mapped' => 0,
      'unmapped' => ['places' => 0, 'named' => 0, 'categories' => []],
      'needs_review' => ['places' => 0, 'named' => 0, 'branches' => []],
    ];

    // The mapper queries the live taxonomy on every call; many places share
    // the same category list, so ask once per distinct list.
    $mapped_cache = [];

    foreach ($this->writer->listKeys() as $key) {
      if ($max_places !== NULL && $report['scanned'] >= $max_places) {
        break;
      }

      try {
        $feature = $this->writer->readLatest($key);
      }
      catch (\Throwable $e) {
        $report['unreadable']++;
        continue;
      }
      if (!is_array($feature)) {
        $report['unreadable']++;
        continue;
      }
      $report['scanned']++;

      $properties = $feature['properties'] ?? [];
      $categories = array_values((array) ($properties['categories'] ?? []));
      $name = trim((string) ($properties['name'] ?? ''));
      $named = $name !== '';

      $classification = $this->classifier->classify($categories);
      $status = $classification['status'] ?? NULL;

      if ($status === PoiCategoryClassifier::STATUS_IGNORED) {
        $report['ignored']++;
        continue;
      }

      if ($status !== PoiCategoryClassifier::STATUS_PENDING_MAPPING) {
        // needs_review, or anything unrecognised (the importer treats an
        // unrecognised status as needing review too).
        $branch = $classification['matched_branch'] ?? '(unclassified)';
        $this->tally($report['needs_review']['branches'], $branch, $name, $named);
        $report['needs_review']['places']++;
        $report['needs_review']['named'] += $named ? 1 : 0;
        continue;
      }

      $cache_key = implode('|', $categories);
      if (!array_key_exists($cache_key, $mapped_cache)) {
        $mapped_cache[$cache_key] = $this->mapper->map($categories) !== NULL;
      }
      if ($mapped_cache[$cache_key]) {
        $report['mapped']++;
        continue;
      }

      $category = $this->primaryCategory($categories);
      $this->tally($report['unmapped']['categories'], $category, $name, $named);
      $report['unmapped']['places']++;
      $report['unmapped']['named'] += $named ? 1 : 0;
    }

    $report['unmapped']['categories'] = $this->sorted($report['unmapped']['categories']);
    $report['needs_review']['branches'] = $this->sorted($report['needs_review']['branches']);

    return $report;
  }

  /**
   * The most specific real place type in a category list.
   *
   * The last category that sits under a known branch of the category tree,
   * so a trailing condition such as wheelchair.yes does not hide the real
   * type. Falls back to the last category if none is under a known branch.
   */
  public function primaryCategory(array $categories): string {
    $tree = array_values(array_filter($categories, function ($category): bool {
      return in_array(explode('.', (string) $category)[0], self::TREE_ROOTS, TRUE);
    }));
    if ($tree) {
      return (string) end($tree);
    }
    return $categories ? (string) end($categories) : '(no category)';
  }

  /**
   * Adds one place to a group.
   */
  protected function tally(array &$groups, string $label, string $name, bool $named): void {
    $groups[$label] ??= ['places' => 0, 'named' => 0, 'samples' => []];
    $groups[$label]['places']++;
    if ($named) {
      $groups[$label]['named']++;
      if (count($groups[$label]['samples']) < self::SAMPLE_NAMES) {
        $groups[$label]['samples'][] = $name;
      }
    }
  }

  /**
   * Sorts groups by named places, then all places, then label.
   */
  protected function sorted(array $groups): array {
    uksort($groups, function (string $a, string $b) use ($groups): int {
      return [$groups[$b]['named'], $groups[$b]['places'], $a] <=> [$groups[$a]['named'], $groups[$a]['places'], $b];
    });
    return $groups;
  }

}
