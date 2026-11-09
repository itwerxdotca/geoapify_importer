<?php

namespace Drupal\geoapify_importer\Service;

/**
 * Converts OpenStreetMap opening_hours text into Office Hours slots.
 *
 * Geoapify returns hours as OpenStreetMap syntax, for example
 * "Mo-Fr 09:00-17:00; Sa 10:00-14:00". This parser handles the common weekly
 * forms exactly and refuses everything else. It never guesses: if any part of
 * the string is something it cannot convert exactly (months, week numbers,
 * dates, sunrise/sunset, open-ended times, comments), parse() returns NULL and
 * the Listing simply gets no hours.
 *
 * Handled:
 * - "24/7"
 * - day lists and ranges: Mo, Mo-Fr, Mo,We,Fr, Mo-Fr,Su (also wrapping, Fr-Mo)
 * - several time ranges per rule: 09:00-12:00,13:00-17:00
 * - rules with no day part (every day)
 * - "off" / "closed" for the named days
 * - ranges over midnight (22:00-02:00), split at midnight
 * - a later rule replaces an earlier one for the same days (OSM semantics)
 * - rules that only say a public holiday is closed ("PH off") are skipped:
 *   the weekly hours stay correct, the holiday exception is simply not stored.
 *
 * Output slots match the Office Hours field: day 0 (Sunday) to 6 (Saturday),
 * starthours/endhours as HHMM integers (900, 1730, 2400 for midnight).
 */
class OpeningHoursParser {

  protected const DAYS = ['Su' => 0, 'Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6];

  /**
   * Parses an OpenStreetMap opening_hours string.
   *
   * @return array<int, array{day: int, starthours: int, endhours: int, comment: string}>|null
   *   Slots sorted by day then start time, or NULL when the string is empty or
   *   cannot be converted exactly.
   */
  public function parse(?string $text): ?array {
    $text = trim((string) $text);
    if ($text === '') {
      return NULL;
    }
    if (strcasecmp($text, '24/7') === 0) {
      return $this->everyDay([[0, 2400]]);
    }

    // Per day: list of [start, end] in minutes-as-HHMM, or [] for closed.
    $week = [];
    $rules = preg_split('/\s*;\s*/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$rules) {
      return NULL;
    }
    foreach ($rules as $rule) {
      $rule = trim($rule);
      if (preg_match('/^(PH|SH)(\s*,\s*(PH|SH))*\s+(off|closed)$/i', $rule)) {
        continue;
      }
      $parsed = $this->parseRule($rule);
      if ($parsed === NULL) {
        return NULL;
      }
      [$days, $ranges] = $parsed;
      foreach ($days as $day) {
        $week[$day] = $ranges;
      }
    }

    // Move the part of any range that runs past midnight onto the next day.
    $slots = [];
    foreach ($week as $day => $ranges) {
      foreach ($ranges as [$start, $end]) {
        if ($end > $start) {
          $slots[] = $this->slot($day, $start, $end);
        }
        else {
          $slots[] = $this->slot($day, $start, 2400);
          if ($end > 0) {
            $slots[] = $this->slot(($day + 1) % 7, 0, $end);
          }
        }
      }
    }
    if (!$slots) {
      return NULL;
    }
    usort($slots, static fn(array $a, array $b): int => [$a['day'], $a['starthours']] <=> [$b['day'], $b['starthours']]);
    return $slots;
  }

  /**
   * Parses one rule into [days, ranges], or NULL if it is not understood.
   *
   * @return array{0: int[], 1: array<int, array{0: int, 1: int}>}|null
   */
  protected function parseRule(string $rule): ?array {
    // Optional day selector, then times or off/closed.
    if (!preg_match('/^(?:([A-Za-z]{2}(?:\s*[-,]\s*[A-Za-z]{2})*)\s+)?(.+)$/', $rule, $m)) {
      return NULL;
    }
    $day_part = $m[1] ?? '';
    $rest = trim($m[2]);

    if ($day_part === '') {
      // A rule that starts with letters but is not a day list (months, PH...)
      // would have matched the group above or fails the time test below.
      $days = range(0, 6);
    }
    else {
      $days = $this->parseDays($day_part);
      if ($days === NULL) {
        return NULL;
      }
    }

    if (preg_match('/^(off|closed)$/i', $rest)) {
      return [$days, []];
    }
    $ranges = [];
    foreach (preg_split('/\s*,\s*/', $rest) as $part) {
      if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $t)) {
        return NULL;
      }
      $start = $this->time((int) $t[1], (int) $t[2], FALSE);
      $end = $this->time((int) $t[3], (int) $t[4], TRUE);
      if ($start === NULL || $end === NULL || $start === $end) {
        return NULL;
      }
      $ranges[] = [$start, $end];
    }
    return $ranges ? [$days, $ranges] : NULL;
  }

  /**
   * Expands "Mo-Fr,Su" into day numbers.
   *
   * @return int[]|null
   */
  protected function parseDays(string $text): ?array {
    $days = [];
    foreach (preg_split('/\s*,\s*/', trim($text)) as $item) {
      if (preg_match('/^([A-Za-z]{2})\s*-\s*([A-Za-z]{2})$/', $item, $m)) {
        $from = self::DAYS[ucfirst(strtolower($m[1]))] ?? NULL;
        $to = self::DAYS[ucfirst(strtolower($m[2]))] ?? NULL;
        if ($from === NULL || $to === NULL) {
          return NULL;
        }
        for ($d = $from, $n = 0; $n < 7; $n++, $d = ($d + 1) % 7) {
          $days[] = $d;
          if ($d === $to) {
            break;
          }
        }
      }
      elseif (preg_match('/^[A-Za-z]{2}$/', $item)) {
        $d = self::DAYS[ucfirst(strtolower($item))] ?? NULL;
        if ($d === NULL) {
          return NULL;
        }
        $days[] = $d;
      }
      else {
        return NULL;
      }
    }
    return array_values(array_unique($days)) ?: NULL;
  }

  /**
   * HH, MM to an HHMM integer; 24:00 is allowed only as an end time.
   */
  protected function time(int $hour, int $minute, bool $is_end): ?int {
    if ($minute > 59) {
      return NULL;
    }
    if ($hour === 24 && $minute === 0 && $is_end) {
      return 2400;
    }
    if ($hour > 23) {
      return NULL;
    }
    return $hour * 100 + $minute;
  }

  protected function slot(int $day, int $start, int $end): array {
    return ['day' => $day, 'starthours' => $start, 'endhours' => $end, 'comment' => ''];
  }

  /**
   * The same ranges on all seven days.
   */
  protected function everyDay(array $ranges): array {
    $slots = [];
    foreach (range(0, 6) as $day) {
      foreach ($ranges as [$start, $end]) {
        $slots[] = $this->slot($day, $start, $end);
      }
    }
    return $slots;
  }

}
