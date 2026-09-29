<?php

namespace Drupal\geoapify_importer\Service\Poi;

/**
 * Classifies a Geoapify place's categories for the POI import pipeline.
 *
 * SCOPE: this class answers "should this become a POI, be sent for manual
 * review, or be left for the (not yet built) taxonomy-driven DIRECT mapper?"
 * It does NOT perform DIRECT/CONDITIONAL mapping to actual taxonomy terms —
 * that mapper must query the live taxonomy on whichever environment it
 * runs on, since devtop and production do not share taxonomy content. See
 * project spec, "Local vs. Production Data Divergence".
 *
 * Matching is hierarchy-aware: a category matches a listed branch if it is
 * EXACTLY that branch, or a dot-separated child of it. Real data has shown
 * Geoapify's categories array already includes the full ancestor chain for
 * every feature, so exact-matching against any array element is usually
 * sufficient; prefix-walking is kept as a safety net for a bare category
 * with no listed ancestor.
 *
 * PROVENANCE, IMPORTANT: the IGNORED_BRANCHES and NEEDS_REVIEW_BRANCHES
 * lists below are NOT all derived from real fetched data the way the
 * original tourism.attraction.artwork entry was. They were built from
 * Geoapify's full published category list (833 categories, pulled live via
 * list_place_categories) plus a business-model policy discussion with the
 * project owner. Treat this as a first, reviewable pass, not a
 * data-verified fact for every branch. Expand/correct as real fetched data
 * turns up categories that don't fit, the same way the artwork rule
 * originally was.
 *
 * THE UNDERLYING POLICY (confirmed with the project owner):
 * - Default: a category representing a commercial operation is never a
 *   POI. It belongs to the separate Listing content type/pipeline
 *   (not built by this class). Reason recorded as 'commercial'.
 * - Explicit exceptions, POI regardless of any commercial activity:
 *   historic/heritage sites, government buildings, hospitals, police,
 *   fire stations, tourism information centres, cemeteries. These are
 *   NOT in any ignore list here — they simply aren't ignored, so they
 *   fall through to PENDING_MAPPING (a human/mapper still has to assign
 *   the actual taxonomy term; this class only clears them for POI-hood).
 * - A third group cannot be resolved by category alone, because the
 *   decision depends on information Geoapify does not provide (mainly:
 *   is this specific place publicly or privately owned/operated). These
 *   ALWAYS go to manual review, regardless of what a future taxonomy
 *   mapper might otherwise resolve automatically. Confirmed cases:
 *   museums, zoos, aquariums, theme parks, galleries, theatres; beach
 *   resorts, campgrounds, marinas, ski lifts, stadiums, golf courses,
 *   and brewery/winery/distillery tours.
 */
class PoiCategoryClassifier {

  public const STATUS_IGNORED = 'ignored';
  public const STATUS_NEEDS_REVIEW = 'needs_review';
  public const STATUS_PENDING_MAPPING = 'pending_mapping';

  public const REASON_NOT_A_PLACE = 'not_a_place';
  public const REASON_COMMERCIAL = 'commercial';
  public const REASON_INFRASTRUCTURE = 'infrastructure';
  public const REASON_ADMINISTRATIVE_AREA = 'administrative_area';

  /**
   * Branches that never become a POI, with why.
   *
   * Order matters only for readability; matching checks every entry.
   *
   * @var array<string, string>
   *   Geoapify category branch => one of the REASON_* constants.
   */
  protected const IGNORED_BRANCHES = [
    // Individual art installations — confirmed via real data: Fort
    // McMurray's "Seven Grandfather Teachings" sculptures were returned
    // as seven separate features under this branch. Not standalone
    // tourist-attraction POIs.
    'tourism.attraction.artwork' => self::REASON_NOT_A_PLACE,

    // Commercial: sells something, belongs in Listings, not POI.
    // Policy confirmed with project owner: "any place that conducts
    // business is out" of POI, with the named exceptions handled by
    // simply never appearing in this list (see class docblock).
    'accommodation' => self::REASON_COMMERCIAL,
    // NOTE: no blanket 'building' entry here, by design. Geoapify tags
    // many places with a generic building.* category ALONGSIDE their
    // specific one (e.g. a museum gets both 'building.tourism' and
    // 'entertainment.museum'; a place of worship gets 'building.
    // place_of_worship' and 'religion.place_of_worship.*'). Ignoring
    // 'building' as a blanket parent would have silently ignored real
    // POI candidates — hospitals (building.healthcare), churches
    // (building.place_of_worship), historic sites (building.historic),
    // government buildings (building.public_and_civil) — whenever that
    // generic tag happened to be checked before their specific one.
    // Found via real data: Oil Sands Discovery Centre carries
    // 'building.tourism' and only avoided this because needs_review is
    // checked as a full pass before ignored. Every genuinely commercial
    // building.* case (accommodation, catering, offices, retail) already
    // matches independently via its own specific category, so this rule
    // was redundant for the cases it was meant to catch and dangerous
    // for everything else.
    'activity' => self::REASON_COMMERCIAL,
    'adult' => self::REASON_COMMERCIAL,
    'catering' => self::REASON_COMMERCIAL,
    'commercial' => self::REASON_COMMERCIAL,
    'education' => self::REASON_COMMERCIAL,
    'childcare' => self::REASON_COMMERCIAL,
    'healthcare.clinic_or_praxis' => self::REASON_COMMERCIAL,
    'healthcare.dentist' => self::REASON_COMMERCIAL,
    'healthcare.pharmacy' => self::REASON_COMMERCIAL,
    // entertainment.* EXCEPT the branches in NEEDS_REVIEW_BRANCHES below
    // (museum, zoo, aquarium, theme_park, culture.arts_centre,
    // culture.gallery, culture.theatre) — those need ownership review,
    // not an automatic commercial call.
    'entertainment.cinema' => self::REASON_COMMERCIAL,
    'entertainment.amusement_arcade' => self::REASON_COMMERCIAL,
    'entertainment.escape_game' => self::REASON_COMMERCIAL,
    'entertainment.miniature_golf' => self::REASON_COMMERCIAL,
    'entertainment.bowling_alley' => self::REASON_COMMERCIAL,
    'entertainment.flying_fox' => self::REASON_COMMERCIAL,
    'entertainment.water_park' => self::REASON_COMMERCIAL,
    'entertainment.activity_park' => self::REASON_COMMERCIAL,
    'entertainment.planetarium' => self::REASON_COMMERCIAL,
    'leisure.spa' => self::REASON_COMMERCIAL,
    'office' => self::REASON_COMMERCIAL,
    // office.government.* is a child of 'office' and would match by
    // prefix; it is deliberately carved back out — see IGNORE_EXCEPTIONS.
    'pet' => self::REASON_COMMERCIAL,
    // production.* EXCEPT brewery/winery/distillery (NEEDS_REVIEW below,
    // tours can be a genuine attraction).
    'production.factory' => self::REASON_COMMERCIAL,
    'production.pottery' => self::REASON_COMMERCIAL,
    'production.cheese' => self::REASON_COMMERCIAL,
    'production.beekeeper' => self::REASON_COMMERCIAL,
    'rental' => self::REASON_COMMERCIAL,
    // service.* EXCEPT service.police and service.fire_station, carved
    // back out via IGNORE_EXCEPTIONS.
    'service' => self::REASON_COMMERCIAL,
    // sport.* EXCEPT stadium/golf_course (NEEDS_REVIEW below).
    'sport.fitness' => self::REASON_COMMERCIAL,
    'sport.dive_centre' => self::REASON_COMMERCIAL,
    'sport.horse_riding' => self::REASON_COMMERCIAL,
    'sport.sports_centre' => self::REASON_COMMERCIAL,
    'sport.sports_hall' => self::REASON_COMMERCIAL,
    'sport.swimming_pool' => self::REASON_COMMERCIAL,

    // Infrastructure / utilities: not a place a visitor would look up,
    // not a business either — just noise for this pipeline's purposes.
    'airport' => self::REASON_INFRASTRUCTURE,
    'amenity' => self::REASON_INFRASTRUCTURE,
    'emergency' => self::REASON_INFRASTRUCTURE,
    'highway' => self::REASON_INFRASTRUCTURE,
    'parking' => self::REASON_INFRASTRUCTURE,
    'power' => self::REASON_INFRASTRUCTURE,
    'public_transport' => self::REASON_INFRASTRUCTURE,
    'railway' => self::REASON_INFRASTRUCTURE,
    'waterway.water_point' => self::REASON_INFRASTRUCTURE,

    // Administrative regions/boundaries, not point attractions.
    'administrative' => self::REASON_ADMINISTRATIVE_AREA,
    'postal_code' => self::REASON_ADMINISTRATIVE_AREA,
    'political' => self::REASON_ADMINISTRATIVE_AREA,
    'low_emission_zone' => self::REASON_ADMINISTRATIVE_AREA,
    'populated_place' => self::REASON_ADMINISTRATIVE_AREA,
  ];

  /**
   * Branches carved back OUT of an ignored parent (exceptions).
   *
   * Checked before IGNORED_BRANCHES, so an exact/child match here wins
   * even though the category would otherwise match a broader ignored
   * parent by prefix (e.g. 'office.government.administrative' would
   * match ignored 'office' by prefix, but must stay out of ignore).
   *
   * These branches are simply NOT ignored — they fall through to
   * PENDING_MAPPING, meaning "confirmed POI-eligible, no taxonomy term
   * assigned yet" (see class docblock).
   *
   * @var string[]
   */
  protected const IGNORE_EXCEPTIONS = [
    'office.government',
    'service.police',
    'service.fire_station',
  ];

  /**
   * Branches that must always go to manual review, never auto-classified.
   *
   * These are known, real categories — the reason they can't be resolved
   * automatically is that the decision (usually public vs. private
   * ownership) depends on information Geoapify does not provide, not on
   * incomplete taxonomy mapping. A future DIRECT-mapping mapper must NOT
   * bypass this list even once taxonomy tagging exists for these
   * categories.
   *
   * @var string[]
   */
  protected const NEEDS_REVIEW_BRANCHES = [
    // Classic "attraction that is also a business" — confirmed with
    // project owner: split by public vs. private ownership, case by case.
    'entertainment.museum',
    'entertainment.zoo',
    'entertainment.aquarium',
    'entertainment.theme_park',
    'entertainment.culture.arts_centre',
    'entertainment.culture.gallery',
    'entertainment.culture.theatre',

    // Smaller gray-area group — confirmed with project owner: manual
    // review, case by case, rather than a blanket rule either way.
    'beach.beach_resort',
    'camping',
    'maritime.marina',
    'ski.lift',
    'sport.stadium',
    'sport.golf_course',
    'production.brewery',
    'production.winery',
    'production.distillery',
  ];

  /**
   * Classifies a place's categories.
   *
   * @param array $categories
   *   The `properties.categories` array from a Geoapify Places feature.
   *
   * @return array
   *   - status: self::STATUS_IGNORED, STATUS_NEEDS_REVIEW or
   *     STATUS_PENDING_MAPPING.
   *   - reason: a REASON_* constant (only set when status is IGNORED).
   *   - matched_branch: the branch that matched, or NULL.
   *   - matched_category: the actual category string that matched, or NULL.
   */
  public function classify(array $categories): array {
    // Exceptions win first, so they are never shadowed by a broader
    // ignored parent branch matching by prefix.
    foreach ($categories as $category) {
      foreach (self::IGNORE_EXCEPTIONS as $exception_branch) {
        if ($this->matchesBranch($category, $exception_branch)) {
          return $this->pendingMappingResult();
        }
      }
    }

    foreach ($categories as $category) {
      foreach (self::NEEDS_REVIEW_BRANCHES as $review_branch) {
        if ($this->matchesBranch($category, $review_branch)) {
          return [
            'status' => self::STATUS_NEEDS_REVIEW,
            'reason' => NULL,
            'matched_branch' => $review_branch,
            'matched_category' => $category,
          ];
        }
      }
    }

    foreach ($categories as $category) {
      foreach (self::IGNORED_BRANCHES as $ignored_branch => $reason) {
        if ($this->matchesBranch($category, $ignored_branch)) {
          return [
            'status' => self::STATUS_IGNORED,
            'reason' => $reason,
            'matched_branch' => $ignored_branch,
            'matched_category' => $category,
          ];
        }
      }
    }

    return $this->pendingMappingResult();
  }

  /**
   * Whether a category is exactly a branch, or a dot-separated child of it.
   */
  protected function matchesBranch(string $category, string $branch): bool {
    return $category === $branch || str_starts_with($category, $branch . '.');
  }

  /**
   * The result shape for "no ignore/review rule matched".
   */
  protected function pendingMappingResult(): array {
    return [
      'status' => self::STATUS_PENDING_MAPPING,
      'reason' => NULL,
      'matched_branch' => NULL,
      'matched_category' => NULL,
    ];
  }

}
