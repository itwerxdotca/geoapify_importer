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
   * Constructs the Geoapify client.
   */
  public function __construct(
	private ClientInterface $httpClient,
	private LoggerInterface $logger,
	private StateInterface $state,
  ) {}

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

}