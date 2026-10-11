<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds the municipality (census subdivision) that contains a point.
 *
 * Reads Statistics Canada's 2021 census subdivision boundary file directly
 * (lcsd000a21a_e.shp/.shx/.dbf, the Digital Boundary File, which extends over
 * water so docks and lighthouses are inside a municipality). No database, no
 * API and no extra library: the .shp is read by file position, one polygon at
 * a time, so the 66 MB file is never held in memory.
 *
 * Files live in private://geoapify_importer/boundaries/csd/ . If they are not
 * there, isAvailable() is FALSE and find() returns NULL, so callers fall back
 * to their other rules.
 *
 * The file is in a Lambert conformal conic projection (NAD83 / GRS80, standard
 * parallels 49 and 77, origin 63.390675 N, -91.866667 E, false easting
 * 6,200,000, false northing 3,000,000: EPSG:3347). A point is projected into
 * that once; the polygons are not converted.
 *
 * Lookup: the 5,161 bounding boxes are held in memory, the few that contain
 * the point are read from the .shp, and an even-odd ray test decides (holes
 * and islands are handled by the even-odd rule).
 */
class CsdBoundaryLocator {

  protected const DIR = 'private://geoapify_importer/boundaries/csd';

  protected const BASE = 'lcsd000a21a_e';

  protected const PROVINCES = [
    10 => 'NL', 11 => 'PE', 12 => 'NS', 13 => 'NB', 24 => 'QC', 35 => 'ON',
    46 => 'MB', 47 => 'SK', 48 => 'AB', 59 => 'BC', 60 => 'YT', 61 => 'NT', 62 => 'NU',
  ];

  // GRS80.
  protected const A = 6378137.0;

  protected const INV_F = 298.257222101;

  /**
   * Record bounding boxes: [minx, miny, maxx, maxy, offset, bytes] per record.
   *
   * @var array<int, array>|null
   */
  protected ?array $boxes = NULL;

  /**
   * Attributes per record: [uid, name, type, province code].
   *
   * @var array<int, array>|null
   */
  protected ?array $attributes = NULL;

  /**
   * Recently read polygons: record number => list of rings.
   */
  protected array $polygonCache = [];

  /**
   * Resolved points: "lat,lng" => result.
   */
  protected array $pointCache = [];

  protected ?string $directory = NULL;

  protected ?bool $available = NULL;

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
  ) {}

  /**
   * TRUE when the boundary files are in place and readable.
   */
  public function isAvailable(): bool {
    if ($this->available === NULL) {
      $dir = $this->directory();
      $this->available = $dir !== NULL
        && is_readable($dir . '/' . self::BASE . '.shp')
        && is_readable($dir . '/' . self::BASE . '.shx')
        && is_readable($dir . '/' . self::BASE . '.dbf');
    }
    return $this->available;
  }

  /**
   * The municipality containing a point.
   *
   * @return array{uid: string, name: string, type: string, province: string}|null
   *   NULL if the files are missing, the point is in no municipality (open
   *   sea, outside Canada) or the file cannot be read.
   */
  public function find(float $lat, float $lng): ?array {
    $key = round($lat, 5) . ',' . round($lng, 5);
    if (array_key_exists($key, $this->pointCache)) {
      return $this->pointCache[$key];
    }
    $result = NULL;
    if ($this->isAvailable()) {
      try {
        $result = $this->lookup($lat, $lng);
      }
      catch (\Throwable $e) {
        $this->logger->error('Census subdivision lookup failed: @message', ['@message' => $e->getMessage()]);
      }
    }
    if (count($this->pointCache) > 20000) {
      $this->pointCache = [];
    }
    return $this->pointCache[$key] = $result;
  }

  /**
   * Projects WGS84 degrees to Statistics Canada Lambert metres.
   *
   * @return array{0: float, 1: float}
   *   [x, y].
   */
  public static function project(float $lat, float $lng): array {
    $f = 1 / self::INV_F;
    $e2 = 2 * $f - $f * $f;
    $e = sqrt($e2);
    $m = static fn(float $phi): float => cos($phi) / sqrt(1 - $e2 * sin($phi) ** 2);
    $t = static fn(float $phi): float => tan(M_PI / 4 - $phi / 2) / (((1 - $e * sin($phi)) / (1 + $e * sin($phi))) ** ($e / 2));

    $phi1 = deg2rad(49.0);
    $phi2 = deg2rad(77.0);
    $phi0 = deg2rad(63.390675);
    $lam0 = deg2rad(-91.86666666666666);
    $n = (log($m($phi1)) - log($m($phi2))) / (log($t($phi1)) - log($t($phi2)));
    $big_f = $m($phi1) / ($n * $t($phi1) ** $n);
    $rho0 = self::A * $big_f * $t($phi0) ** $n;
    $rho = self::A * $big_f * $t(deg2rad($lat)) ** $n;
    $theta = $n * (deg2rad($lng) - $lam0);
    return [
      6200000.0 + $rho * sin($theta),
      3000000.0 + $rho0 - $rho * cos($theta),
    ];
  }

  /**
   * Resolves a point against the boundaries.
   */
  protected function lookup(float $lat, float $lng): ?array {
    [$x, $y] = self::project($lat, $lng);
    $boxes = $this->boxes();
    $dir = $this->directory();
    $handle = NULL;
    try {
      foreach ($boxes as $record => $box) {
        if ($x < $box[0] || $x > $box[2] || $y < $box[1] || $y > $box[3]) {
          continue;
        }
        if (!isset($this->polygonCache[$record])) {
          $handle ??= fopen($dir . '/' . self::BASE . '.shp', 'rb');
          if (count($this->polygonCache) >= 12) {
            array_shift($this->polygonCache);
          }
          $this->polygonCache[$record] = $this->readRings($handle, $box[4], $box[5]);
        }
        if ($this->inside($x, $y, $this->polygonCache[$record])) {
          $attr = $this->attributes()[$record] ?? NULL;
          return $attr === NULL ? NULL : [
            'uid' => $attr[0],
            'name' => $attr[1],
            'type' => $attr[2],
            'province' => $attr[3],
          ];
        }
      }
    }
    finally {
      if ($handle) {
        fclose($handle);
      }
    }
    return NULL;
  }

  /**
   * Reads one polygon record into rings of [x, y] pairs (flat float arrays).
   *
   * @return array<int, array<int, float>>
   *   Each ring is [x0, y0, x1, y1, ...].
   */
  protected function readRings($handle, int $offset, int $bytes): array {
    fseek($handle, $offset + 8);
    $data = fread($handle, $bytes);
    $type = unpack('V', substr($data, 0, 4))[1];
    if (!in_array($type, [5, 15, 25], TRUE)) {
      return [];
    }
    $parts_count = unpack('V', substr($data, 36, 4))[1];
    $points_count = unpack('V', substr($data, 40, 4))[1];
    $parts = array_values(unpack('V' . $parts_count, substr($data, 44, 4 * $parts_count)));
    $points_at = 44 + 4 * $parts_count;
    $rings = [];
    foreach ($parts as $i => $start) {
      $end = $parts[$i + 1] ?? $points_count;
      $count = $end - $start;
      if ($count < 3) {
        continue;
      }
      $rings[] = array_values(unpack('e' . (2 * $count), substr($data, $points_at + 16 * $start, 16 * $count)));
    }
    return $rings;
  }

  /**
   * Even-odd point-in-polygon over all rings.
   */
  protected function inside(float $x, float $y, array $rings): bool {
    $inside = FALSE;
    foreach ($rings as $ring) {
      $n = intdiv(count($ring), 2);
      $xj = $ring[2 * ($n - 1)];
      $yj = $ring[2 * ($n - 1) + 1];
      for ($i = 0; $i < $n; $i++) {
        $xi = $ring[2 * $i];
        $yi = $ring[2 * $i + 1];
        if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
          $inside = !$inside;
        }
        $xj = $xi;
        $yj = $yi;
      }
    }
    return $inside;
  }

  /**
   * Bounding boxes of every record, built once from the .shx/.shp.
   *
   * The result is cached in a file next to the data, keyed by the .shp size
   * and modification time, so later processes skip the 5,161 small reads.
   */
  protected function boxes(): array {
    if ($this->boxes !== NULL) {
      return $this->boxes;
    }
    $dir = $this->directory();
    $shp = $dir . '/' . self::BASE . '.shp';
    $cache = $dir . '/boxes.cache';
    $stamp = filesize($shp) . ':' . filemtime($shp);
    if (is_readable($cache)) {
      $stored = @unserialize((string) file_get_contents($cache), ['allowed_classes' => FALSE]);
      if (is_array($stored) && ($stored['stamp'] ?? '') === $stamp) {
        return $this->boxes = $stored['boxes'];
      }
    }

    $shx = fopen($dir . '/' . self::BASE . '.shx', 'rb');
    $handle = fopen($shp, 'rb');
    $boxes = [];
    try {
      fseek($shx, 100);
      $record = 0;
      while (($index = fread($shx, 8)) !== FALSE && strlen($index) === 8) {
        $offset = unpack('N', substr($index, 0, 4))[1] * 2;
        $length = unpack('N', substr($index, 4, 4))[1] * 2;
        fseek($handle, $offset + 8);
        $head = fread($handle, 36);
        $type = unpack('V', substr($head, 0, 4))[1];
        if (in_array($type, [5, 15, 25], TRUE)) {
          $box = unpack('e4', substr($head, 4, 32));
          $boxes[$record] = [$box[1], $box[2], $box[3], $box[4], $offset, $length];
        }
        $record++;
      }
    }
    finally {
      fclose($shx);
      fclose($handle);
    }
    @file_put_contents($cache, serialize(['stamp' => $stamp, 'boxes' => $boxes]));
    return $this->boxes = $boxes;
  }

  /**
   * Attributes per record from the .dbf, read once.
   */
  protected function attributes(): array {
    if ($this->attributes !== NULL) {
      return $this->attributes;
    }
    $handle = fopen($this->directory() . '/' . self::BASE . '.dbf', 'rb');
    $attributes = [];
    try {
      $head = fread($handle, 32);
      $count = unpack('V', substr($head, 4, 4))[1];
      $header_length = unpack('v', substr($head, 8, 2))[1];
      $record_length = unpack('v', substr($head, 10, 2))[1];
      $fields = [];
      $at = 1;
      while (($descriptor = fread($handle, 32)) !== FALSE && strlen($descriptor) === 32 && $descriptor[0] !== "\r") {
        $length = ord($descriptor[16]);
        $fields[rtrim(substr($descriptor, 0, 11), "\0")] = [$at, $length];
        $at += $length;
      }
      fseek($handle, $header_length);
      for ($i = 0; $i < $count; $i++) {
        $row = fread($handle, $record_length);
        $get = static fn(string $name): string => isset($fields[$name]) ? trim(substr($row, $fields[$name][0], $fields[$name][1])) : '';
        $province = self::PROVINCES[(int) $get('PRUID')] ?? '';
        $attributes[$i] = [$get('CSDUID'), self::utf8($get('CSDNAME')), $get('CSDTYPE'), $province];
      }
    }
    finally {
      fclose($handle);
    }
    return $this->attributes = $attributes;
  }

  /**
   * The .dbf text as UTF-8 (the file is UTF-8 already, but guard anyway).
   */
  protected static function utf8(string $value): string {
    return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
  }

  /**
   * Real path of the boundary folder, or NULL.
   */
  protected function directory(): ?string {
    if ($this->directory === NULL) {
      $path = $this->fileSystem->realpath(self::DIR);
      $this->directory = $path ?: '';
    }
    return $this->directory === '' ? NULL : $this->directory;
  }

}
