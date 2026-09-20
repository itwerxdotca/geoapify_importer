<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Determines address-verification confidence for a Geoapify place.
 *
 * This is shared ingestion infrastructure, not specific to any one
 * content type (POI, Listing, etc.) — per the project's architecture
 * goal, callers are responsible for deciding what to do with the
 * verification result (e.g. skip editorial review vs. flag NEEDS_REVIEW).
 *
 * Verification strategy:
 *   1. If the Geoapify Places API response itself contains both a
 *      `housenumber` and a `street` property, the address is treated as
 *      VERIFIED with no extra API call (this is the cheap, default path).
 *   2. Otherwise, if reverse-geocode fallback is enabled in configuration,
 *      call the Reverse Geocoding API against the place's coordinates and
 *      evaluate the returned `rank.match_type` / `rank.confidence_*`
 *      fields against configurable acceptance criteria.
 *   3. If fallback is disabled, or the reverse-geocode result doesn't meet
 *      the configured criteria, the result is UNVERIFIED and the caller
 *      should route the record to editorial review rather than treat the
 *      address/town data as authoritative.
 *
 * IMPORTANT: the exact field paths this class reads from a reverse-geocode
 * response (rank.match_type, rank.confidence_building_level) are based on
 * Geoapify's published documentation and have NOT yet been confirmed
 * against a live reverse-geocode API response in this project. Run a real
 * test call and compare against getMatchType()/getBuildingConfidence()
 * before relying on this in production. See project notes.
 */
class AddressVerifier {

  public const STATUS_VERIFIED = 'verified';
  public const STATUS_UNVERIFIED = 'unverified';

  /**
   * The Geoapify client, used only for its reverseGeocode() method.
   *
   * @var \Drupal\geoapify_importer\Service\GeoapifyClient
   */
  protected GeoapifyClient $client;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The geoapify_importer logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the AddressVerifier.
   *
   * @param \Drupal\geoapify_importer\Service\GeoapifyClient $client
   *   The Geoapify client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The geoapify_importer logger channel.
   */
  public function __construct(GeoapifyClient $client, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
	$this->client = $client;
	$this->configFactory = $config_factory;
	$this->logger = $logger;
  }

  /**
   * Verifies the address confidence for a single Geoapify Places feature.
   *
   * @param array $properties
   *   The `properties` object from a single Geoapify Places API feature
   *   (i.e. $feature['properties'], not the whole feature).
   *
   * @return array
   *   An associative array:
   *   - status: self::STATUS_VERIFIED or self::STATUS_UNVERIFIED.
   *   - method: 'places_api', 'reverse_geocode', 'fallback_disabled',
   *     or 'reverse_geocode_error'.
   *   - details: array of supporting data for logging/debugging
   *     (e.g. the match_type and confidence seen, if a fallback call
   *     was made).
   */
  public function verify(array $properties): array {
	// Fast path: Places API already gave us a genuine civic address.
	if (!empty($properties['housenumber']) && !empty($properties['street'])) {
	  return [
		'status' => self::STATUS_VERIFIED,
		'method' => 'places_api',
		'details' => [
		  'housenumber' => $properties['housenumber'],
		  'street' => $properties['street'],
		],
	  ];
	}

	$config = $this->configFactory->get('geoapify_importer.settings');
	$fallback_enabled = (bool) $config->get('reverse_geocode_enabled');

	if (!$fallback_enabled) {
	  return [
		'status' => self::STATUS_UNVERIFIED,
		'method' => 'fallback_disabled',
		'details' => [],
	  ];
	}

	$lat = $properties['lat'] ?? NULL;
	$lon = $properties['lon'] ?? NULL;

	if ($lat === NULL || $lon === NULL) {
	  $this->logger->warning('Cannot run reverse-geocode fallback: missing lat/lon in properties for place_id @place_id.', [
		'@place_id' => $properties['place_id'] ?? 'unknown',
	  ]);

	  return [
		'status' => self::STATUS_UNVERIFIED,
		'method' => 'reverse_geocode_error',
		'details' => ['error' => 'missing_coordinates'],
	  ];
	}

	try {
	  $reverse_result = $this->client->reverseGeocode((float) $lat, (float) $lon);
	}
	catch (\RuntimeException $e) {
	  $this->logger->error('Reverse-geocode fallback request failed for place_id @place_id: @message', [
		'@place_id' => $properties['place_id'] ?? 'unknown',
		'@message' => $e->getMessage(),
	  ]);

	  return [
		'status' => self::STATUS_UNVERIFIED,
		'method' => 'reverse_geocode_error',
		'details' => ['error' => $e->getMessage()],
	  ];
	}

	$match_type = $this->extractMatchType($reverse_result);
	$confidence = $this->extractBuildingConfidence($reverse_result);

	$accepted_match_types = (array) ($config->get('reverse_geocode_accepted_match_types') ?? []);
	$threshold = (float) ($config->get('reverse_geocode_confidence_threshold') ?? 1.0);

	$meets_match_type = $match_type !== NULL && in_array($match_type, $accepted_match_types, TRUE);
	$meets_confidence = $confidence !== NULL && $confidence >= $threshold;

	$status = ($meets_match_type && $meets_confidence) ? self::STATUS_VERIFIED : self::STATUS_UNVERIFIED;

	$this->logger->info('Reverse-geocode fallback for place_id @place_id: match_type=@match_type confidence=@confidence -> @status', [
	  '@place_id' => $properties['place_id'] ?? 'unknown',
	  '@match_type' => $match_type ?? 'null',
	  '@confidence' => $confidence ?? 'null',
	  '@status' => $status,
	]);

	return [
	  'status' => $status,
	  'method' => 'reverse_geocode',
	  'details' => [
		'match_type' => $match_type,
		'confidence_building_level' => $confidence,
		'threshold_used' => $threshold,
		'accepted_match_types_used' => $accepted_match_types,
	  ],
	];
  }

  /**
   * Extracts rank.match_type from a decoded reverse-geocode response.
   *
   * NOT YET VERIFIED against a real response — see class docblock.
   * Adjust the array path here once a live response has been inspected,
   * if it turns out to differ from Geoapify's documented shape.
   *
   * @param array $reverse_result
   *   The decoded reverse-geocode API response.
   *
   * @return string|null
   *   The match type, or NULL if not present.
   */
  protected function extractMatchType(array $reverse_result): ?string {
	return $reverse_result['results'][0]['rank']['match_type'] ?? NULL;
  }

  /**
   * Extracts rank.confidence_building_level from a reverse-geocode response.
   *
   * NOT YET VERIFIED against a real response — see class docblock.
   *
   * @param array $reverse_result
   *   The decoded reverse-geocode API response.
   *
   * @return float|null
   *   The confidence value (0–1), or NULL if not present.
   */
  protected function extractBuildingConfidence(array $reverse_result): ?float {
	$value = $reverse_result['results'][0]['rank']['confidence_building_level'] ?? NULL;
	return $value === NULL ? NULL : (float) $value;
  }

}