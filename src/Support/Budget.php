<?php

namespace TarsusIlias\Support;

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Guardrail\Pipeline;

/**
 * The monthly budget of AI interactions: what was used this calendar
 * month, the limit the customer set, and the warnings on the way there.
 *
 * Two figures describe the month. The local ledger counts the AI
 * interactions of every request this installation made, by the rules of
 * the partner agreement (Interactions), and is available at once. The
 * brain's own usage endpoint reports what Tarsus counted - for the whole
 * brain, so it also covers other systems using it - and is fetched by the
 * cron job and on the status pages. The gate uses the larger of the two,
 * so a limit holds even when only one side knows about some usage.
 *
 * The limit is in AI interactions per calendar month (the unit Tarsus
 * bills; the brain reports it as AI credits); 0 means no limit. Crossing
 * 50, 75, 90 and 100 percent of it raises a banner for teachers and
 * administrators and sends one mail per threshold and month.
 *
 * Usage is billed through the partner - unless the customer is
 * identified by a License-Key: such customers are billed by Tarsus
 * directly, and the cost estimate says so instead of naming the
 * partner's price.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Budget
{
    /** Percentages of the limit that raise a warning. */
    public const THRESHOLDS = [50, 75, 90, 100];

    /** Seconds a fetched brain figure stays fresh. */
    public const REMOTE_TTL = 600;

    private const KEY_REMOTE = 'usage_remote';
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
     * The customer's monthly limit in AI interactions; 0 means no limit.
     * Until one is set, the package's included interactions (or the
     * default amount on Pay-As-You-Grow) bound the usage.
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
     * AI interactions this installation recorded this month.
     */
    public static function localCredits(): int
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT COALESCE(SUM(credits), 0) total FROM ' . UsageMeter::TABLE . ' WHERE created >= %s',
            ['integer'],
            [self::monthStart()]
        );
        $row = $db->fetchAssoc($res);
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Requests that counted at least one AI interaction this month.
     */
    public static function localRequests(): int
    {
        $db = Ilias::db();
        $res = $db->queryF(
            'SELECT COUNT(*) cnt FROM ' . UsageMeter::TABLE . ' WHERE created >= %s AND credits > 0',
            ['integer'],
            [self::monthStart()]
        );
        $row = $db->fetchAssoc($res);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * The brain's figure for this month, if one was fetched this month.
     *
     * @return array{credits: int, fetched: int, items: array<int, array{key: string, name: string, service: string, credits: int}>}|null
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
            'credits' => (int) ($data['credits'] ?? 0),
            'fetched' => (int) ($data['fetched'] ?? 0),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
    }

    /**
     * Ask the brain what it counted this month and remember the answer.
     * Failures keep the previous figure; nothing is thrown.
     *
     * @return array{credits: int, fetched: int, items: array}|null The current figure.
     */
    public static function refreshRemote(bool $force = false): ?array
    {
        $current = self::remote();
        if (!$force && $current !== null && time() - $current['fetched'] < self::REMOTE_TTL) {
            return $current;
        }
        try {
            $usage = (new Pipeline())->execute(
                fn() => ClientFactory::getClient()->getUsage(self::monthStart(), time()),
                0
            );
        } catch (\Throwable $e) {
            Ilias::log('usage fetch failed: ' . $e->getMessage());
            return $current;
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
                'credits' => (int) ($item['credits'] ?? 0),
            ];
        }
        $figure = [
            'month' => self::monthKey(),
            'credits' => max(0, (int) ($usage['total'] ?? 0)),
            'fetched' => time(),
            'items' => $items,
        ];
        Config::set(self::KEY_REMOTE, (string) json_encode($figure));
        self::checkThresholds();
        return ['credits' => $figure['credits'], 'fetched' => $figure['fetched'], 'items' => $items];
    }

    /**
     * AI interactions used this month: the larger of the local and the
     * brain's figure.
     */
    public static function used(): int
    {
        $remote = self::remote();
        return max(self::localCredits(), $remote === null ? 0 : $remote['credits']);
    }

    public static function percent(): float
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return 0.0;
        }
        return min(999.0, round(self::used() / $limit * 100, 1));
    }

    public static function remaining(): int
    {
        return max(0, self::limit() - self::used());
    }

    public static function exhausted(): bool
    {
        return self::hasLimit() && self::used() >= self::limit();
    }

    /**
     * Whether a call counting $estimated AI interactions would cross the limit.
     */
    public static function wouldExceed(int $estimated): bool
    {
        return self::hasLimit() && self::used() + max(0, $estimated) > self::limit();
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
     * AI interactions this month beyond the ones the package includes, and
     * what they cost at the agreed price per interaction. On
     * Pay-As-You-Grow every interaction is charged. The figure is the one
     * the limit works on, so usage only the brain knows about is included.
     *
     * A License-Key customer is billed by Tarsus under the customer's own
     * contract: the interactions beyond the package are still reported,
     * but no cost at the partner's price ('cost' is 0.0).
     *
     * @return array{requests: int, interactions: int, included: int, charged: int, cost: float, billedby: string}
     */
    public static function overage(): array
    {
        $included = Plan::includedCredits();
        $used = self::used();
        $charged = $included <= 0 ? $used : max(0, $used - $included);
        $billedby = Plan::billedBy();
        return [
            'requests' => self::localRequests(),
            'interactions' => $used,
            'included' => $included,
            'charged' => $charged,
            'cost' => $billedby === Plan::BILLED_BY_PARTNER ? round($charged * Plan::pricePerRequest(), 2) : 0.0,
            'billedby' => $billedby,
        ];
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
            'used' => number_format(self::used()),
            'limit' => number_format(self::limit()),
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
            'used' => number_format(self::used()),
            'limit' => number_format(self::limit()),
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
