<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class GeoapifyClient {

  /**
   * Geoapify Places API endpoint.
   */
  private const PLACES_ENDPOINT = 'https://api.geoapify.com/v2/places';

  /**
   * Geoapify Reverse Geocoding API endpoint.
   */
  private const REVERSE_GEOCODE_ENDPOINT = 'https://api.geoapify.com/v1/geocode/reverse';

  /**
   * Geoapify Forward Geocoding API endpoint.
   */
  private const FORWARD_GEOCODE_ENDPOINT = 'https://api.geoapify.com/v1/geocode/search';

  /**
   * Geoapify Place Details API endpoint.
   */
  private const PLACE_DETAILS_ENDPOINT = 'https://api.geoapify.com/v2/place-details';

  /**
   * Constructs the Geoapify client.
   */
  public function __construct(
  private ClientInterface $httpClient,
  private LoggerInterface $logger,
  private StateInterface $state,
  private GeoapifyRateLimiter $rateLimiter,
  ) {}

  /**
   * Checks the global daily rate limit before an outgoing request.
   *
   * @throws \RuntimeException
   *   If the daily limit has been reached.
   */
  private function enforceRateLimit(): void {
  if (!$this->rateLimiter->canMakeRequest()) {
    $this->logger->warning('Geoapify daily request limit reached; request blocked.');
    throw new \RuntimeException('Geoapify daily request limit has been reached for today.');
  }
  }

  /**
   * Makes a request to the Geoapify Places API.
   *
   * @param array<string, string|int> $query
   *   Query parameters, excluding the API key.
   *
   * @return array
   *   Decoded Geoapify response.
   *
   * @throws \RuntimeException
   *   If the API key is not configured or the request fails.
   */
  public function request(array $query = []): array {
  $this->enforceRateLimit();

  $apiKey = $this->state->get('geoapify_importer.api_key');

  if (empty($apiKey)) {
    throw new \RuntimeException('Geoapify API key has not been configured.');
  }

  $query['apiKey'] = $apiKey;

  try {
    $response = $this->httpClient->request('GET', self::PLACES_ENDPOINT, [
    'query' => $query,
    'headers' => [
      'Accept' => 'application/json',
    ],
    'timeout' => 30,
    ]);
  }
  catch (GuzzleException $e) {
    $this->logger->error('Geoapify API request failed: @message', [
    '@message' => $e->getMessage(),
    ]);

    throw new \RuntimeException(
    'The Geoapify API request failed.',
    0,
    $e
    );
  }

  $this->rateLimiter->recordRequest();

  $statusCode = $response->getStatusCode();

  if ($statusCode < 200 || $statusCode >= 300) {
    $this->logger->error(
    'Geoapify API returned HTTP status @status.',
    [
      '@status' => $statusCode,
    ]
    );

    throw new \RuntimeException(
    sprintf('Geoapify API returned HTTP status %d.', $statusCode)
    );
  }

  $data = json_decode(
    $response->getBody()->getContents(),
    TRUE,
    512,
    JSON_THROW_ON_ERROR
  );

  return $data;
  }

  /**
   * Reverse-geocodes a coordinate pair into address components.
   *
   * Used as a fallback confidence check when the Places API response for
   * a location does not itself include a verified civic street address
   * (no housenumber). See AddressVerifier for the calling logic.
   *
   * @param float $lat
   *   Latitude.
   * @param float $lon
   *   Longitude.
   *
   * @return array
   *   Decoded Geoapify reverse-geocode response.
   *
   * @throws \RuntimeException
   *   If the API key is not configured or the request fails.
   */
  public function reverseGeocode(float $lat, float $lon): array {
  $this->enforceRateLimit();

  $apiKey = $this->state->get('geoapify_importer.api_key');

  if (empty($apiKey)) {
    throw new \RuntimeException('Geoapify API key has not been configured.');
  }

  try {
    $response = $this->httpClient->request('GET', self::REVERSE_GEOCODE_ENDPOINT, [
    'query' => [
      'lat' => $lat,
      'lon' => $lon,
      'format' => 'json',
      'apiKey' => $apiKey,
    ],
    'headers' => [
      'Accept' => 'application/json',
    ],
    'timeout' => 30,
    ]);
  }
  catch (GuzzleException $e) {
    $this->logger->error('Geoapify reverse-geocode request failed: @message', [
    '@message' => $e->getMessage(),
    ]);

    throw new \RuntimeException(
    'The Geoapify reverse-geocode request failed.',
    0,
    $e
    );
  }

  $this->rateLimiter->recordRequest();

  $statusCode = $response->getStatusCode();

  if ($statusCode < 200 || $statusCode >= 300) {
    $this->logger->error(
    'Geoapify reverse-geocode API returned HTTP status @status.',
    [
      '@status' => $statusCode,
    ]
    );

    throw new \RuntimeException(
    sprintf('Geoapify reverse-geocode API returned HTTP status %d.', $statusCode)
    );
  }

  $data = json_decode(
    $response->getBody()->getContents(),
    TRUE,
    512,
    JSON_THROW_ON_ERROR
  );

  return $data;
  }
  /**
   * Forward-geocodes free text (e.g. a town name) to a place.
   *
   * Used to resolve a town's Geoapify boundary place_id for use as
   * filter=place:{id} in Places API searches, as an alternative to a
   * fixed-radius circle. See TownBoundaryResolver for the calling logic
   * and how the result is evaluated for confidence before being trusted.
   *
   * @param string $text
   *   The text to geocode, e.g. "Fort McMurray, Alberta, Canada".
   * @param array<string, string> $options
   *   Optional extra query parameters, e.g. ['type' => 'city'].
   *
   * @return array
   *   Decoded Geoapify forward-geocode response.
   *
   * @throws \RuntimeException
   *   If the API key is not configured or the request fails.
   */
  public function forwardGeocode(string $text, array $options = []): array {
  $this->enforceRateLimit();

  $apiKey = $this->state->get('geoapify_importer.api_key');

  if (empty($apiKey)) {
    throw new \RuntimeException('Geoapify API key has not been configured.');
  }

  $query = $options;
  $query['text'] = $text;
  $query['format'] = 'json';
  $query['apiKey'] = $apiKey;

  try {
    $response = $this->httpClient->request('GET', self::FORWARD_GEOCODE_ENDPOINT, [
    'query' => $query,
    'headers' => [
      'Accept' => 'application/json',
    ],
    'timeout' => 30,
    ]);
  }
  catch (GuzzleException $e) {
    $this->logger->error('Geoapify forward-geocode request failed: @message', [
    '@message' => $e->getMessage(),
    ]);

    throw new \RuntimeException(
    'The Geoapify forward-geocode request failed.',
    0,
    $e
    );
  }

  $this->rateLimiter->recordRequest();

  $statusCode = $response->getStatusCode();

  if ($statusCode < 200 || $statusCode >= 300) {
    $this->logger->error(
    'Geoapify forward-geocode API returned HTTP status @status.',
    [
      '@status' => $statusCode,
    ]
    );

    throw new \RuntimeException(
    sprintf('Geoapify forward-geocode API returned HTTP status %d.', $statusCode)
    );
  }

  $data = json_decode(
    $response->getBody()->getContents(),
    TRUE,
    512,
    JSON_THROW_ON_ERROR
  );

  return $data;
  }

  /**
   * Fetches enrichment details for a place by its OSM reference.
   *
   * Used to enrich an already-identified place (contact info, ownership
   * signals, category-specific detail) beyond what the Places search
   * endpoint returns. Looking up by osm_type/osm_id means no separate
   * place_id lookup is needed — PlaceIdentity already derives these for
   * every stored place.
   *
   * NOTE: Geoapify's own documentation is inconsistent about the
   * `features` parameter's separator — prose examples show comma
   * ("details,building"), the newer API reference says pipe
   * ("details|building"). This method uses comma, matching the worked
   * pricing examples; verify against a real response if adding features
   * beyond the default 'details'.
   *
   * @param string $osm_type
   *   OSM element type: 'n', 'w', or 'r'.
   * @param int $osm_id
   *   The numeric OSM element ID.
   * @param string[] $features
   *   Requested feature groups. Defaults to ['details'] — 1 credit.
   *   See https://apidocs.geoapify.com/docs/place-details/#api for the
   *   full feature list and credit costs; most non-default features
   *   add cost, some substantially (radius/isoline place-count features).
   *
   * @return array
   *   Decoded Geoapify Place Details response (a GeoJSON FeatureCollection).
   *
   * @throws \RuntimeException
   *   If the API key is not configured or the request fails.
   */
  public function placeDetails(string $osm_type, int $osm_id, array $features = ['details']): array {
  $this->enforceRateLimit();

  $apiKey = $this->state->get('geoapify_importer.api_key');

  if (empty($apiKey)) {
    throw new \RuntimeException('Geoapify API key has not been configured.');
  }

  try {
    $response = $this->httpClient->request('GET', self::PLACE_DETAILS_ENDPOINT, [
    'query' => [
      'osm_type' => $osm_type,
      'osm_id' => $osm_id,
      'features' => implode(',', $features),
      'apiKey' => $apiKey,
    ],
    'headers' => [
      'Accept' => 'application/json',
    ],
    'timeout' => 30,
    ]);
  }
  catch (GuzzleException $e) {
    $this->logger->error('Geoapify place-details request failed: @message', [
    '@message' => $e->getMessage(),
    ]);

    throw new \RuntimeException(
    'The Geoapify place-details request failed.',
    0,
    $e
    );
  }

  $this->rateLimiter->recordRequest();

  $statusCode = $response->getStatusCode();

  if ($statusCode < 200 || $statusCode >= 300) {
    $this->logger->error(
    'Geoapify place-details API returned HTTP status @status.',
    [
      '@status' => $statusCode,
    ]
    );

    throw new \RuntimeException(
    sprintf('Geoapify place-details API returned HTTP status %d.', $statusCode)
    );
  }

  $data = json_decode(
    $response->getBody()->getContents(),
    TRUE,
    512,
    JSON_THROW_ON_ERROR
  );

  return $data;
  }

}
