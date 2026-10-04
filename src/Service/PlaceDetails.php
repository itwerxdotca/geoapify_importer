<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches Place Details enrichment data for a place, if the feature is
 * enabled.
 *
 * Shared ingestion infrastructure, not POI-specific — a future Listing
 * importer could use the same enrichment (contact info is exactly as
 * relevant to a business listing as to a POI, arguably more so).
 *
 * Looks up by OSM type/ID, taken directly from the feature's own
 * `properties.datasource.raw` — the same data PlaceIdentity already
 * reads, so no separate Geoapify place_id lookup is needed.
 *
 * RATE LIMITING: this class does NOT track its own request budget.
 * GeoapifyClient enforces one global daily limit across every endpoint
 * it calls (Places search, geocoding, and this). See
 * GeoapifyRateLimiter. This class only decides whether enrichment
 * should be ATTEMPTED at all (the place_details_enabled toggle) — a
 * separate concern from how many Geoapify requests are allowed today.
 *
 * Returns NULL, not an exception, for every "can't enrich this one"
 * case (disabled, rate-limited, no OSM reference, request failure) —
 * enrichment is additive and optional by design; its absence should
 * never block a place from being created or updated.
 */
class PlaceDetails {

  public function __construct(
    protected GeoapifyClient $client,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Fetches the 'details' feature for a place, if possible.
   *
   * @param array $properties
   *   The `properties` object from a Geoapify Places feature (the same
   *   shape AddressVerifier::verify() takes).
   *
   * @return array|null
   *   The decoded 'details' feature's own properties (contact info,
   *   ownership signals, etc.), or NULL if enrichment could not be
   *   performed for any reason.
   */
  public function get(array $properties): ?array {
    $config = $this->configFactory->get('geoapify_importer.settings');
    if (!$config->get('place_details_enabled')) {
      return NULL;
    }

    $raw = $properties['datasource']['raw'] ?? [];
    $osm_type = $raw['osm_type'] ?? NULL;
    $osm_id = $raw['osm_id'] ?? NULL;

    if ($osm_type === NULL || $osm_id === NULL) {
      return NULL;
    }

    try {
      $response = $this->client->placeDetails((string) $osm_type, (int) $osm_id);
    }
    catch (\Throwable $e) {
      // Covers both genuine request failures and the global daily rate
      // limit being reached (GeoapifyClient throws the same exception
      // type for both) — either way, enrichment is skipped, not fatal.
      $this->logger->info('Place Details lookup skipped for osm_@type_@id: @message', [
        '@type' => $osm_type,
        '@id' => $osm_id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    foreach ($response['features'] ?? [] as $feature) {
      if (($feature['properties']['feature_type'] ?? NULL) === 'details') {
        return $feature['properties'];
      }
    }

    return NULL;
  }

}
