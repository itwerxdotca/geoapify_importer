<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\Exception\FileException;
use Psr\Log\LoggerInterface;

/**
 * Writes and archives raw Geoapify source-record files.
 *
 * Responsibility is deliberately narrow: given a Geoapify place ID and a
 * decoded response payload, persist it to the private filesystem and
 * archive whatever was there before. This service does not classify, map,
 * or create any Drupal entities — see project spec, "Architecture Goal".
 *
 * On-disk layout (private://geoapify_importer/):
 *   pois/{place_id}/latest.json
 *   pois/{place_id}/history/{timestamp}.json
 *
 * Timestamp format: gmdate('Ymd\THis\Z'), e.g. 20260920T143451Z.
 */
class SourceFileWriter {

  /**
   * Base URI for all Geoapify source-file storage.
   *
   * Uses the private:// stream wrapper, which requires
   * $settings['file_private_path'] to be configured in settings.php.
   * See project spec: private path has been verified via
   * \Drupal\Core\StreamWrapper\PrivateStream::basePath().
   */
  protected const BASE_URI = 'private://geoapify_importer';

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The geoapify_importer logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the SourceFileWriter.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The geoapify_importer logger channel.
   */
  public function __construct(FileSystemInterface $file_system, LoggerInterface $logger) {
	$this->fileSystem = $file_system;
	$this->logger = $logger;
  }

  /**
   * Writes a raw Geoapify payload for a place, archiving any prior version.
   *
   * @param string $place_id
   *   The Geoapify place ID. Used verbatim as a directory segment, so it
   *   is sanitized defensively even though Geoapify IDs are expected to
   *   already be filesystem-safe.
   * @param array $payload
   *   The decoded Geoapify response payload (a single feature, not the
   *   full FeatureCollection) to persist as the canonical "latest" record.
   *
   * @return string
   *   The URI of the newly written latest.json file.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If any directory or file operation fails.
   */
  public function write(string $place_id, array $payload): string {
	$place_id = $this->sanitizeId($place_id);

	$place_dir = self::BASE_URI . '/pois/' . $place_id;
	$history_dir = $place_dir . '/history';
	$latest_uri = $place_dir . '/latest.json';

	$this->prepareDirectory($place_dir);
	$this->prepareDirectory($history_dir);

	// Archive the existing latest.json, if one exists, before overwriting.
	if (file_exists($latest_uri)) {
	  $timestamp = gmdate('Ymd\THis\Z');
	  $archive_uri = $history_dir . '/' . $timestamp . '.json';

	  $moved = $this->fileSystem->move($latest_uri, $archive_uri, FileSystemInterface::EXISTS_ERROR);
	  if (!$moved) {
		throw new FileException("Failed to archive existing source file for place_id '{$place_id}' to {$archive_uri}.");
	  }

	  $this->logger->info('Archived prior source record for place @place_id to @uri.', [
		'@place_id' => $place_id,
		'@uri' => $archive_uri,
	  ]);
	}

	$this->writeAtomic($latest_uri, $payload);

	$this->logger->info('Wrote source record for place @place_id to @uri.', [
	  '@place_id' => $place_id,
	  '@uri' => $latest_uri,
	]);

	return $latest_uri;
  }

  /**
   * Reads the current latest.json payload for a place, if it exists.
   *
   * @param string $place_id
   *   The Geoapify place ID.
   *
   * @return array|null
   *   The decoded payload, or NULL if no source file exists yet for this
   *   place. Returning NULL (rather than throwing) lets callers treat
   *   "no prior record" as a normal case, e.g. for NEW vs CHANGED status
   *   determination during change detection.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the file exists but cannot be read or decoded.
   */
  public function readLatest(string $place_id): ?array {
	$place_id = $this->sanitizeId($place_id);
	$latest_uri = self::BASE_URI . '/pois/' . $place_id . '/latest.json';

	if (!file_exists($latest_uri)) {
	  return NULL;
	}

	$contents = file_get_contents($latest_uri);
	if ($contents === FALSE) {
	  throw new FileException("Failed to read source file at {$latest_uri}.");
	}

	$decoded = json_decode($contents, TRUE);
	if (json_last_error() !== JSON_ERROR_NONE) {
	  throw new FileException("Failed to decode JSON in source file at {$latest_uri}: " . json_last_error_msg());
	}

	return $decoded;
  }

  /**
   * Ensures a directory exists and is writable, creating it if needed.
   *
   * @param string $uri
   *   The directory URI to prepare.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the directory cannot be created or made writable.
   */
  protected function prepareDirectory(string $uri): void {
	$ready = $this->fileSystem->prepareDirectory(
	  $uri,
	  FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
	);

	if (!$ready) {
	  throw new FileException("Failed to prepare directory at {$uri}.");
	}
  }

  /**
   * Writes JSON to a URI via write-to-temp-then-rename, to avoid partial files.
   *
   * @param string $target_uri
   *   The final destination URI, e.g. .../latest.json.
   * @param array $payload
   *   The data to encode and write.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If encoding or either file operation fails.
   */
  protected function writeAtomic(string $target_uri, array $payload): void {
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === FALSE) {
	  throw new FileException('Failed to JSON-encode payload for ' . $target_uri . ': ' . json_last_error_msg());
	}

	$tmp_uri = $target_uri . '.tmp-' . uniqid('', TRUE);

	$written = file_put_contents($tmp_uri, $json);
	if ($written === FALSE) {
	  throw new FileException("Failed to write temporary file at {$tmp_uri}.");
	}

	$moved = $this->fileSystem->move($tmp_uri, $target_uri, FileSystemInterface::EXISTS_REPLACE);
	if (!$moved) {
	  throw new FileException("Failed to move temporary file into place at {$target_uri}.");
	}
  }

  /**
   * Defensively sanitizes a place ID for use as a filesystem path segment.
   *
   * Geoapify place IDs are expected to already be filesystem-safe, but
   * this guards against unexpected characters (path separators, etc.)
   * reaching the filesystem layer.
   *
   * @param string $place_id
   *   The raw place ID.
   *
   * @return string
   *   The sanitized place ID.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the place ID is empty after sanitization.
   */
  protected function sanitizeId(string $place_id): string {
	$sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($place_id));

	if ($sanitized === NULL || $sanitized === '') {
	  throw new FileException("Invalid or empty place_id provided to SourceFileWriter: '{$place_id}'.");
	}

	return $sanitized;
  }

}