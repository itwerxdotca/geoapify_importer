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
 *      check whether THAT response also contains both `housenumber` and
 *      `street` — i.e. an independent, coordinate-based confirmation of
 *      a genuine civic address, using the same signal as step 1.
 *   3. If fallback is disabled, or the reverse-geocode result also lacks
 *      a housenumber, the result is UNVERIFIED and the caller should
 *      route the record to editorial review rather than treat the
 *      address/town data as authoritative.
 *
 * NOTE: an earlier version of this class attempted to use
 * `rank.match_type` and `rank.confidence_building_level` from the
 * reverse-geocode response. Live testing (two real API calls, including
 * a remote site with no civic address) confirmed the Reverse Geocoding
 * endpoint does not return those fields at all — they are documented
 * under Geoapify's Forward Geocoding and Address Autocomplete APIs,
 * which match a *requested* address against candidates; Reverse
 * Geocoding has no requested address to match against, so it has no
 * match_type. The `reverse_geocode_confidence_threshold` and
 * `reverse_geocode_accepted_match_types` config values were removed
 * accordingly, since they had no real data to evaluate.
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
   *     (e.g. the housenumber/street seen, if a fallback call was made).
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

	$result_housenumber = $reverse_result['results'][0]['housenumber'] ?? NULL;
	$result_street = $reverse_result['results'][0]['street'] ?? NULL;

	$status = (!empty($result_housenumber) && !empty($result_street))
	  ? self::STATUS_VERIFIED
	  : self::STATUS_UNVERIFIED;

	$this->logger->info('Reverse-geocode fallback for place_id @place_id: housenumber=@housenumber street=@street -> @status', [
	  '@place_id' => $properties['place_id'] ?? 'unknown',
	  '@housenumber' => $result_housenumber ?? 'null',
	  '@street' => $result_street ?? 'null',
	  '@status' => $status,
	]);

	return [
	  'status' => $status,
	  'method' => 'reverse_geocode',
	  'details' => [
		'housenumber' => $result_housenumber,
		'street' => $result_street,
	  ],
	];
  }

}