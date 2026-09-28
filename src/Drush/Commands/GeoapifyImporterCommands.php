<?php

namespace Drupal\geoapify_importer\Drush\Commands;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\geoapify_importer\Service\ChangeDetector;
use Drupal\geoapify_importer\Service\GeoapifyClient;
use Drupal\geoapify_importer\Service\PlaceIdentity;
use Drupal\geoapify_importer\Service\SourceFileWriter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for manually fetching and storing Geoapify data.
 *
 * These are dev/test tools for exercising GeoapifyClient and
 * SourceFileWriter directly against the live API. They do NOT
 * classify, map, or create any Drupal nodes — see project spec,
 * "Architecture Goal". The full import pipeline (geoapify:import)
 * is a separate, not-yet-built command that will use these same
 * services plus classification/mapping/node-creation logic.
 */
final class GeoapifyImporterCommands extends DrushCommands implements ContainerInjectionInterface {

  /**
   * Geoapify's own documented maximum for the Places API `limit` parameter.
   */
  private const MAX_LIMIT = 500;

  public function __construct(
    private readonly GeoapifyClient $client,
    private readonly SourceFileWriter $writer,
    private readonly ChangeDetector $detector,
    private readonly PlaceIdentity $identity,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('geoapify_importer.client'),
      $container->get('geoapify_importer.source_writer'),
      $container->get('geoapify_importer.change_detector'),
      $container->get('geoapify_importer.place_identity'),
    );
  }

  /**
   * Fetches places from Geoapify and stores new or changed ones.
   *
   * Each place is compared against its stored latest.json via
   * ChangeDetector first. Unchanged places are skipped (no write, no new
   * history file); new and changed places are written via SourceFileWriter.
   *
   * @param int $count
   *   Number of places to fetch. Clamped to Geoapify's documented range
   *   of 1-500; values outside that range are adjusted with a warning
   *   rather than rejected outright.
   *
   * @option categories
   *   Comma-separated Geoapify category keys.
   * @option lat
   *   Latitude of the search center.
   * @option lon
   *   Longitude of the search center.
   * @option radius
   *   Search radius in meters.
   *
   * @usage drush geoapify:fetch 20
   *   Fetch up to 20 places using the default Fort McMurray area search.
   * @usage drush geoapify:fetch 50 --categories=commercial.supermarket --radius=5000
   *   Fetch up to 50 supermarkets within 5km of the default center.
   */
  #[CLI\Command(name: 'geoapify:fetch', aliases: ['geo-fetch'])]
  #[CLI\Argument(name: 'count', description: 'Number of places to fetch (1-500, default 20).')]
  #[CLI\Option(name: 'categories', description: 'Comma-separated Geoapify category keys.')]
  #[CLI\Option(name: 'lat', description: 'Latitude of the search center.')]
  #[CLI\Option(name: 'lon', description: 'Longitude of the search center.')]
  #[CLI\Option(name: 'radius', description: 'Search radius in meters.')]
  #[CLI\Usage(name: 'drush geoapify:fetch 20', description: 'Fetch up to 20 places using the default search area.')]
  #[CLI\Usage(name: 'drush geoapify:fetch 50 --categories=commercial.supermarket --radius=5000', description: 'Fetch up to 50 supermarkets within 5km.')]
  public function fetch(
    int $count = 20,
    array $options = [
      'categories' => 'entertainment,tourism',
      'lat' => 56.7264,
      'lon' => -111.3809,
      'radius' => 15000,
    ],
  ): void {
    $requested = $count;

    if ($count < 1) {
      $count = 1;
    }
    elseif ($count > self::MAX_LIMIT) {
      $count = self::MAX_LIMIT;
    }

    if ($count !== $requested) {
      $this->io()->warning(sprintf(
        'Requested count %d is outside Geoapify\'s supported range (1-%d). Using %d instead.',
        $requested,
        self::MAX_LIMIT,
        $count
      ));
    }

    $filter = sprintf('circle:%s,%s,%s', $options['lon'], $options['lat'], $options['radius']);

    $this->io()->text(sprintf(
      'Fetching up to %d place(s): categories=%s, %s',
      $count,
      $options['categories'],
      $filter
    ));

    try {
      $response = $this->client->request([
        'categories' => $options['categories'],
        'filter' => $filter,
        'limit' => $count,
      ]);
    }
    catch (\RuntimeException $e) {
      $this->io()->error('Geoapify request failed: ' . $e->getMessage());
      return;
    }

    $features = $response['features'] ?? [];

    $this->io()->text(sprintf('Fetched %d place(s) from Geoapify.', count($features)));

    if (empty($features)) {
      $this->io()->note('No places matched this query. Try a wider radius or different categories.');
      return;
    }

    $rows = [];
    $counts = [
      ChangeDetector::STATUS_NEW => 0,
      ChangeDetector::STATUS_CHANGED => 0,
      ChangeDetector::STATUS_UNCHANGED => 0,
      'error' => 0,
    ];

    foreach ($features as $feature) {
      $properties = $feature['properties'] ?? [];
      $name = $properties['name'] ?? '(no name)';

      // Storage key comes from the OSM reference, not place_id: place_id
      // embeds coordinates that shift between requests, so the same place
      // would otherwise be stored twice. See PlaceIdentity.
      try {
        $key = $this->identity->keyFor($feature);
      }
      catch (\InvalidArgumentException $e) {
        $this->io()->warning("Skipped {$name}: " . $e->getMessage());
        continue;
      }

      try {
        // Detection must happen BEFORE the write: write() archives and
        // replaces latest.json, after which stored and incoming are equal.
        $result = $this->detector->detect($feature, $this->writer->readLatest($key));
        $status = $result['status'];

        if ($status === ChangeDetector::STATUS_UNCHANGED) {
          $detail = 'skipped (no write)';
        }
        else {
          $this->writer->write($key, $feature);
          $detail = $status === ChangeDetector::STATUS_CHANGED
            ? 'written; changed: ' . implode(', ', array_keys($result['changed_fields']))
            : 'written';
        }

        $counts[$status]++;
        $rows[] = [$name, $status, $detail, $key];
      }
      catch (\Throwable $e) {
        $counts['error']++;
        $rows[] = [$name, 'error', $e->getMessage(), $key];
      }
    }

    $this->io()->table(['Name', 'Status', 'Action', 'Storage Key'], $rows);

    $summary = sprintf(
      '%d new, %d changed, %d unchanged (skipped), %d error(s) out of %d fetched place(s).',
      $counts[ChangeDetector::STATUS_NEW],
      $counts[ChangeDetector::STATUS_CHANGED],
      $counts[ChangeDetector::STATUS_UNCHANGED],
      $counts['error'],
      count($features)
    );

    if ($counts['error'] > 0) {
      $this->io()->warning($summary);
    }
    else {
      $this->io()->success($summary);
    }
  }

}
