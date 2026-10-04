<?php

namespace Drupal\geoapify_importer\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Caps how many Geoapify API requests are made per day, across every
 * endpoint (Places search, reverse geocode, forward geocode, place
 * details) — not tracked separately per feature.
 *
 * WHY GLOBAL: Geoapify bills on one shared daily credit pool for the
 * whole account, regardless of which endpoint a request hits. A limiter
 * scoped to just one feature (e.g. only Place Details) would miss the
 * real risk: an unattended, cron-driven import making thousands of
 * Places/geocoding calls could exhaust the account's actual daily
 * allowance on its own, with no Place-Details-specific cap ever
 * noticing. This class is checked and incremented from inside
 * GeoapifyClient itself, once per outgoing request, so nothing that
 * calls the API can accidentally bypass it.
 *
 * Tracks usage via a date-keyed Drupal State entry
 * (geoapify_importer.requests_YYYY-MM-DD), which naturally resets each
 * day simply because the key itself changes — no cron job or cleanup
 * needed to "reset" the counter.
 *
 * SIMPLIFICATION, stated plainly: this counts REQUESTS, not CREDITS.
 * Geoapify's own documentation states most simple requests (Places,
 * Geocoding) cost 1 credit each, and Place Details' default `details`
 * feature also costs 1 credit — so request-count and credit-count are
 * usually the same number. Requesting additional Place Details features
 * beyond the default (radius/isoline place-count features, in
 * particular) can cost several credits per single request; this limiter
 * would under-count the real credit cost in that case. Not a concern
 * today, since only the default `details` feature is used anywhere in
 * this module, but worth knowing if that ever changes.
 */
class GeoapifyRateLimiter {

  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether another Geoapify API request can be made right now.
   *
   * TRUE if rate limiting is disabled entirely, or if today's usage is
   * still under the configured daily limit. FALSE only when limiting is
   * enabled AND the limit has been reached.
   */
  public function canMakeRequest(): bool {
    $config = $this->configFactory->get('geoapify_importer.settings');

    if (!$config->get('geoapify_rate_limit_enabled')) {
      return TRUE;
    }

    $limit = (int) ($config->get('geoapify_daily_request_limit') ?? 0);
    if ($limit <= 0) {
      // Limiting is enabled but no positive limit is configured — treat
      // as "block everything" rather than "unlimited", since a 0 or
      // unset limit with the toggle on almost certainly means
      // misconfiguration, not an intentional unlimited setting.
      return FALSE;
    }

    return $this->getTodayCount() < $limit;
  }

  /**
   * Records that one Geoapify API request was just made.
   *
   * Call this once a response has actually been received from Geoapify
   * (any status code) — a request that failed before reaching Geoapify
   * (e.g. a network error) was not billed and should not count.
   */
  public function recordRequest(): void {
    $key = $this->todayStateKey();
    $current = (int) ($this->state->get($key) ?? 0);
    $this->state->set($key, $current + 1);
  }

  /**
   * How many Geoapify API requests have been recorded today.
   */
  public function getTodayCount(): int {
    return (int) ($this->state->get($this->todayStateKey()) ?? 0);
  }

  /**
   * How many more requests are allowed today, given the configured limit.
   */
  public function remainingToday(): int {
    $config = $this->configFactory->get('geoapify_importer.settings');
    $limit = (int) ($config->get('geoapify_daily_request_limit') ?? 0);
    return max(0, $limit - $this->getTodayCount());
  }

  /**
   * The State key for today's counter. Changes automatically at UTC
   * midnight, which is how the daily reset works with no extra logic.
   */
  protected function todayStateKey(): string {
    return 'geoapify_importer.requests_' . gmdate('Y-m-d');
  }

}
