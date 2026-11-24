<?php

namespace TarsusIlias\Support;

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Guardrail\Pipeline;

/**
 * The monthly AI-credit budget: what the brain charged this calendar
 * month, the limit the customer set, and the warnings on the way there.
 *
 * There is one figure: the brain's own usage, as Tarsus reports it (for
 * the whole brain, so it also covers other systems using it). The plugin
 * estimates nothing. The figure is fetched at most once a minute and kept,
 * by the cron job, the status pages and before a call that may cost
 * credits; one fetch runs at a time.
 *
 * The limit is in AI credits per calendar month; 0 means no limit.
 * Crossing 50, 75, 90 and 100 percent of it raises a banner for teachers
 * and administrators and sends one mail per threshold and month.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Budget
{
    /** Percentages of the limit that raise a warning. */
    public const THRESHOLDS = [50, 75, 90, 100];

    /** Seconds a fetched brain figure stays fresh. */
    public const REMOTE_TTL = 60;

    /** Seconds one fetch may take before another process may start its own. */
    private const FETCH_LOCK = 20;

    private const KEY_REMOTE = 'usage_remote';
    private const KEY_FETCHING = 'usage_fetching';
    private const KEY_NOTIFIED = 'usage_notified';

    // ------------------------------------------------------------------
    // Period
    // ------------------------------------------------------------------

    /**
     * Midnight of the first day of the month containing $time, in the
     * installation's time zone.
     */
    public static function monthStart(?int $time = null): int
    {
        $date = self::local($time ?? time());
        $date->modify('first day of this month midnight');
        return $date->getTimestamp();
    }

    public static function nextMonthStart(?int $time = null): int
    {
        $date = self::local($time ?? time());
        $date->modify('first day of next month midnight');
        return $date->getTimestamp();
    }

    public static function monthKey(?int $time = null): string
    {
        return self::local($time ?? time())->format('Y-m');
    }

    private static function local(int $time): \DateTime
    {
        $zone = null;
        try {
            if (class_exists('ilTimeZone')) {
                $zone = new \DateTimeZone((string) \ilTimeZone::_getDefaultTimeZone());
            }
        } catch (\Throwable $e) {
            $zone = null;
        }
        $date = new \DateTime('@' . $time);
        $date->setTimezone($zone ?? new \DateTimeZone(date_default_timezone_get()));
        return $date;
    }

    // ------------------------------------------------------------------
    // Limit and usage
    // ------------------------------------------------------------------

    /**
     * The customer's monthly limit in AI credits; 0 means no limit. Until
     * one is set, the plan's included credits (or the default amount on
     * Pay as you grow) bound the spending.
     */
    public static function limit(): int
    {
        $value = Config::fresh('monthly_limit');
        if ($value === null || $value === '') {
            $included = Plan::includedCredits();
            return $included > 0 ? $included : Plan::DEFAULT_CREDITS;
        }
        return max(0, (int) $value);
    }

    public static function hasLimit(): bool
    {
        return self::limit() > 0;
    }

    /**
     * The brain's figure for this month, if one was fetched this month.
     *
     * @return array{credits: float, fetched: int, items: array<int, array{key: string, name: string, service: string, credits: float}>}|null
     */
    public static function remote(): ?array
    {
        $raw = Config::fresh(self::KEY_REMOTE);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (string) ($data['month'] ?? '') !== self::monthKey()) {
            return null;
        }
        return [
            'credits' => round((float) ($data['credits'] ?? 0), 2),
            'fetched' => (int) ($data['fetched'] ?? 0),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
    }

    /**
     * Ask the brain what it charged this month and remember the answer.
     * Failures keep the previous figure; nothing is thrown.
     *
     * @return array{credits: float, fetched: int, items: array}|null The current figure.
     */
    public static function refreshRemote(bool $force = false): ?array
    {
        $current = self::remote();
        if (!$force && $current !== null && time() - $current['fetched'] < self::REMOTE_TTL) {
            return $current;
        }
        // One fetch at a time: another process fetching right now will
        // store the figure.
        $fetching = (int) Config::fresh(self::KEY_FETCHING);
        if (!$force && $current !== null && time() - $fetching < self::FETCH_LOCK) {
            return $current;
        }
        Config::set(self::KEY_FETCHING, (string) time());
        try {
            $usage = (new Pipeline())->execute(
                fn() => ClientFactory::getClient()->getUsage(self::monthStart(), time())
            );
        } catch (\Throwable $e) {
            Ilias::log('usage fetch failed: ' . $e->getMessage());
            return $current;
        } finally {
            Config::set(self::KEY_FETCHING, null);
        }
        $items = [];
        foreach ((array) ($usage['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'key' => (string) ($item['key'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'service' => (string) ($item['service'] ?? ''),
                'credits' => round((float) ($item['credits'] ?? 0), 2),
            ];
        }
        usort($items, static fn(array $a, array $b): int => $b['credits'] <=> $a['credits']);
        $figure = [
            'month' => self::monthKey(),
            'credits' => max(0.0, round((float) ($usage['total'] ?? 0), 2)),
            'fetched' => time(),
            'items' => $items,
        ];
        Config::set(self::KEY_REMOTE, (string) json_encode($figure));
        self::checkThresholds();
        return ['credits' => $figure['credits'], 'fetched' => $figure['fetched'], 'items' => $items];
    }

    /**
     * Credits the brain charged this month, as last fetched; 0 until a
     * figure exists for the month.
     */
    public static function used(): float
    {
        $remote = self::remote();
        return $remote === null ? 0.0 : $remote['credits'];
    }

    public static function percent(): float
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return 0.0;
        }
        return min(999.0, round(self::used() / $limit * 100, 1));
    }

    public static function remaining(): float
    {
        return max(0.0, self::limit() - self::used());
    }

    /**
     * Whether the month's limit is reached. With $refresh the brain's
     * figure is fetched first when it is older than a minute.
     */
    public static function exhausted(bool $refresh = false): bool
    {
        if (!self::hasLimit()) {
            return false;
        }
        if ($refresh) {
            self::refreshRemote();
        }
        return self::used() >= self::limit();
    }

    /**
     * Severity of the current usage against the limit.
     */
    public static function level(): string
    {
        if (!self::hasLimit()) {
            return 'ok';
        }
        $percent = self::percent();
        if ($percent >= 100) {
            return 'exhausted';
        }
        if ($percent >= 90) {
            return 'critical';
        }
        if ($percent >= 75) {
            return 'warning';
        }
        if ($percent >= 50) {
            return 'notice';
        }
        return 'ok';
    }

    // ------------------------------------------------------------------
    // Cost estimate
    // ------------------------------------------------------------------

    /**
     * AI credits this month beyond the package's included amount, and
     * what they cost at the agreed price per credit - both from the
     * brain's figure. On Pay as you grow every credit counts. A customer
     * with a license key is billed by Tarsus, so no partner price applies
     * and the cost is 0.
     *
     * @return array{used: float, cost: float, included: int, charged: float}
     */
    public static function overage(): array
    {
        return self::overageOf(self::used(), Plan::includedCredits(), Plan::pricePerRequest(), Plan::billedByTarsus());
    }

    /**
     * The arithmetic of {@see overage()}: credits beyond the included
     * amount, priced per credit.
     *
     * @return array{used: float, cost: float, included: int, charged: float}
     */
    public static function overageOf(float $used, int $included, float $price, bool $billedbytarsus = false): array
    {
        $charged = round(max(0.0, $used - max(0, $included)), 2);
        return [
            'used' => round($used, 2),
            'charged' => $charged,
            'included' => max(0, $included),
            'cost' => $billedbytarsus ? 0.0 : round($charged * $price, 2),
        ];
    }

    /**
     * A credit figure for people: whole numbers without decimals, fractions
     * with two (1,234.57 / 1.234,57).
     */
    public static function format(float $credits): string
    {
        $decimals = abs($credits - round($credits)) < 0.005 ? 0 : 2;
        $german = Ilias::userLanguage() === 'de';
        return number_format($credits, $decimals, $german ? ',' : '.', $german ? '.' : ',');
    }

    // ------------------------------------------------------------------
    // Warnings
    // ------------------------------------------------------------------

    /**
     * The banner for teachers and administrators, or null while usage is
     * below half of the limit.
     *
     * @return array{type: string, text: string}|null
     */
    public static function banner(): ?array
    {
        $level = self::level();
        if ($level === 'ok') {
            return null;
        }
        $params = [
            'used' => self::format(self::used()),
            'limit' => self::format(self::limit()),
            'percent' => number_format(self::percent(), 0),
            'reset' => Ilias::date(self::nextMonthStart()),
        ];
        if ($level === 'exhausted') {
            return ['type' => 'failure', 'text' => Ilias::txt('budget_banner_exhausted', $params)];
        }
        return ['type' => $level === 'notice' ? 'info' : 'warning', 'text' => Ilias::txt('budget_banner_warning', $params)];
    }

    /**
     * Send the mails for thresholds crossed this month that were not
     * announced yet. Cheap when nothing changed: one config read.
     */
    public static function checkThresholds(): void
    {
        if (!self::hasLimit()) {
            return;
        }
        $month = self::monthKey();
        $raw = Config::fresh(self::KEY_NOTIFIED);
        $notified = json_decode((string) $raw, true);
        $done = is_array($notified) && is_array($notified[$month] ?? null) ? array_map('intval', $notified[$month]) : [];

        $percent = null;
        $pending = [];
        foreach (self::THRESHOLDS as $threshold) {
            if (in_array($threshold, $done, true)) {
                continue;
            }
            if ($percent === null) {
                $percent = self::percent();
            }
            if ($percent >= $threshold) {
                $pending[] = $threshold;
            }
        }
        if ($pending === []) {
            return;
        }
        // Only the highest new threshold is announced; the lower ones are
        // implied and marked done.
        $highest = max($pending);
        self::notify($highest);
        Config::set(self::KEY_NOTIFIED, (string) json_encode([$month => array_values(array_unique(array_merge($done, $pending)))]));
    }

    /**
     * Recipients of the warnings: the configured addresses, else the
     * installation's administrator address.
     *
     * @return string[]
     */
    public static function recipients(): array
    {
        $raw = (string) Config::get('notify_emails', '');
        $addresses = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $address) {
            $address = trim($address);
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            $admin = trim((string) Ilias::setting('admin_email'));
            if ($admin !== '' && filter_var($admin, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $admin;
            }
        }
        return array_values(array_unique($addresses));
    }

    private static function notify(int $threshold): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }
        $params = [
            'percent' => (string) $threshold,
            'used' => self::format(self::used()),
            'limit' => self::format(self::limit()),
            'reset' => Ilias::date(self::nextMonthStart()),
            'site' => Ilias::siteTitle(),
        ];
        $subject = Ilias::txt($threshold >= 100 ? 'budget_mail_subject_exhausted' : 'budget_mail_subject', $params);
        $body = Ilias::txt($threshold >= 100 ? 'budget_mail_body_exhausted' : 'budget_mail_body', $params);
        Ilias::mail($recipients, $subject, $body);
    }

    /**
     * Forget the month's warning record (the limit was raised).
     */
    public static function resetWarnings(): void
    {
        Config::set(self::KEY_NOTIFIED, null);
    }
}
