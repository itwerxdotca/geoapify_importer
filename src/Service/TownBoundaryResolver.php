<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\Exception\FileException;
use Psr\Log\LoggerInterface;

/**
 * Resolves a town's Geoapify administrative boundary, for use as a
 * Places API search area instead of a fixed-radius circle.
 *
 * WHY THIS EXISTS: a circle around a town's centre point is a poor proxy
 * for its real shape. Confirmed with real data: for Calgary, a 20km-radius
 * circle and Calgary's actual administrative boundary disagreed on 5 of
 * roughly 198 supermarkets (3 caught by the circle but outside the real
 * boundary; 2 inside the real boundary but outside the circle's reach).
 * For a town spread out lengthwise, or with outlying communities like
 * Anzac and Gregoire near Fort McMurray, the mismatch could be larger and
 * in either direction.
 *
 * HOW IT WORKS: Geoapify's Places API accepts filter=place:{place_id} to
 * search within a real administrative boundary polygon (as opposed to
 * filter=circle:...). That place_id is a DIFFERENT thing from a town's
 * stored field_geolocation coordinates, and different again from
 * PlaceIdentity's storage keys (which identify individual POIs, not
 * areas) — it must be resolved separately, once per town, via Forward
 * Geocoding on the town's name.
 *
 * CACHING: the resolved boundary id rarely changes, so it is cached to a
 * file rather than re-resolved on every import run — cheap, avoids
 * needless API calls, and follows the existing pattern of storing
 * Geoapify data as files rather than in SQL (see SourceFileWriter).
 *
 * CONFIDENCE THRESHOLD: requires an exact match_type ('full_match') AND
 * confidence of 1, both together. Verified against three real towns
 * spanning the range: Tahsis, BC (Village, pop. ~250) and Taber, AB
 * (Town, pop. ~8,000) both resolved correctly; Tadmore, BC (Hamlet)
 * returned match_type='full_match' but confidence=0 and was correctly
 * rejected. That result is exactly why both conditions are required
 * together — match_type alone would have wrongly accepted Tadmore's
 * low-confidence match. Still not tested: a town whose name collides
 * with a much larger place elsewhere (wrong-city risk), or one entirely
 * absent from OpenStreetMap's data.
 *
 * FALLBACK: when resolution fails (low confidence, no match, or request
 * error), callers should fall back to a circle search around the town's
 * field_geolocation coordinates with a default radius. This resolver
 * does not perform that fallback itself — it only ever returns a
 * trustworthy boundary id, or NULL.
 */
class TownBoundaryResolver {

  /**
   * Base URI for cached town boundary resolutions.
   */
  protected const BASE_URI = 'private://geoapify_importer/towns';

  /**
   * Required match_type value(s) to trust a geocoding result as a real
   * administrative boundary, rather than a fuzzy or partial match.
   *
   * See class docblock: unverified against small/rural towns.
   *
   * @var string[]
   */
  protected const ACCEPTED_MATCH_TYPES = ['full_match'];

  /**
   * Minimum confidence (0-1) required, in addition to ACCEPTED_MATCH_TYPES.
   *
   * See class docblock: verified as necessary via a real case (Tadmore,
   * BC) where match_type alone ('full_match') was insufficient — that
   * result had confidence=0 and was correctly rejected only because both
   * conditions are required together.
   */
  protected const MINIMUM_CONFIDENCE = 1.0;

  public function __construct(
    protected GeoapifyClient $client,
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Resolves a town's boundary place_id, using the cache when available.
   *
   * @param int $tid
   *   The canadian_towns taxonomy term ID. Used only as a cache key.
   * @param string $town_name
   *   The town's plain name, e.g. "Fort McMurray".
   * @param string|null $province_code
   *   Optional province/state code (e.g. "AB") to disambiguate towns
   *   with common names. Recommended when available.
   *
   * @return string|null
   *   The Geoapify place_id to use as filter=place:{id}, or NULL if no
   *   sufficiently confident boundary could be resolved. NULL is a
   *   normal, expected outcome for some towns, not necessarily an error
   *   — callers should fall back to a circle search.
   */
  public function resolve(int $tid, string $town_name, ?string $province_code = NULL): ?string {
    $cached = $this->readCache($tid);
    if ($cached !== NULL) {
      return $cached['place_id'];
    }

    $text = $province_code
      ? "{$town_name}, {$province_code}, Canada"
      : "{$town_name}, Canada";

    try {
      $response = $this->client->forwardGeocode($text, [
        'type' => 'city',
        'filter' => 'countrycode:ca',
        'limit' => 1,
      ]);
    }
    catch (\RuntimeException $e) {
      $this->logger->warning('Boundary resolution failed for town tid @tid (@name): @message', [
        '@tid' => $tid,
        '@name' => $town_name,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $result = $response['results'][0] ?? NULL;
    if ($result === NULL) {
      $this->logger->warning('Boundary resolution found no result for town tid @tid (@name).', [
        '@tid' => $tid,
        '@name' => $town_name,
      ]);
      return NULL;
    }

    if (!$this->isConfident($result)) {
      $this->logger->warning('Boundary resolution for tid @tid (@name) did not meet the confidence threshold: match_type=@match_type confidence=@confidence.', [
        '@tid' => $tid,
        '@name' => $town_name,
        '@match_type' => $result['rank']['match_type'] ?? 'null',
        '@confidence' => $result['rank']['confidence'] ?? 'null',
      ]);
      return NULL;
    }

    $place_id = $result['place_id'] ?? NULL;
    if ($place_id === NULL) {
      return NULL;
    }

    $this->writeCache($tid, [
      'place_id' => $place_id,
      'town_name' => $town_name,
      'result_type' => $result['result_type'] ?? NULL,
      'match_type' => $result['rank']['match_type'] ?? NULL,
      'confidence' => $result['rank']['confidence'] ?? NULL,
      'resolved_at' => gmdate('Ymd\THis\Z'),
    ]);

    return $place_id;
  }

  /**
   * Whether a geocoding result meets the confidence bar to be trusted.
   */
  protected function isConfident(array $result): bool {
    $match_type = $result['rank']['match_type'] ?? NULL;
    $confidence = $result['rank']['confidence'] ?? NULL;

    return $match_type !== NULL
      && in_array($match_type, self::ACCEPTED_MATCH_TYPES, TRUE)
      && $confidence !== NULL
      && (float) $confidence >= self::MINIMUM_CONFIDENCE;
  }

  /**
   * Reads a cached resolution for a town, if one exists.
   *
   * @return array|null
   *   The cached data, or NULL if nothing is cached yet.
   */
  protected function readCache(int $tid): ?array {
    $uri = self::BASE_URI . '/' . $tid . '/boundary.json';

    if (!file_exists($uri)) {
      return NULL;
    }

    $contents = file_get_contents($uri);
    if ($contents === FALSE) {
      return NULL;
    }

    $decoded = json_decode($contents, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Writes a resolution to the cache.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the directory or file cannot be prepared/written.
   */
  protected function writeCache(int $tid, array $data): void {
    $dir = self::BASE_URI . '/' . $tid;

    $ready = $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    if (!$ready) {
      throw new FileException("Failed to prepare directory at {$dir}.");
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      throw new FileException('Failed to JSON-encode boundary cache data for tid ' . $tid . '.');
    }

    $uri = $dir . '/boundary.json';
    $tmp_uri = $uri . '.tmp-' . uniqid('', TRUE);

    if (file_put_contents($tmp_uri, $json) === FALSE) {
      throw new FileException("Failed to write temporary file at {$tmp_uri}.");
    }

    if (!$this->fileSystem->move($tmp_uri, $uri, FileSystemInterface::EXISTS_REPLACE)) {
      throw new FileException("Failed to move temporary file into place at {$uri}.");
    }
  }

}
