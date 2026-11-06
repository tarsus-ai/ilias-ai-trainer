<?php

namespace TarsusIlias\Output;

use TarsusIlias\Activity\Scoring;
use TarsusIlias\Support\Ilias;

/**
 * Server-rendered markup of the plugin's screens. The chat's cards are
 * rendered client-side (tarsus.js); everything here is the frame they live
 * in, and the teacher's material and learner lists.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Views
{
    private static function t(string $key, array $params = []): string
    {
        return Html::e(Ilias::txt($key, $params));
    }

    /**
     * The learner chat: feed, progress bar, composer with the mode switch.
     *
     * @param array<string, mixed> $config The client configuration (JSON-encoded into the page).
     */
    public static function chat(string $containerid, array $config, string $help = ''): string
    {
        $modes = (array) $config['modes'];
        $buttons = '';
        foreach ($modes as $mode) {
            $buttons .= '<button type="button" class="tarsus-mode-switch__btn" data-mode="' . Html::e($mode) . '" aria-pressed="false">'
                . Html::icon('mode_' . $mode) . '<span>' . self::t('mode_' . $mode) . '</span></button>';
        }

        $demo = !empty($config['demo'])
            ? '<div class="tarsus-demo-note" role="note">' . self::t('demo_note') . '</div>'
            : '';

        return '<div class="tarsus-app tarsus-chat-box" id="' . Html::e($containerid) . '" data-config="' . Html::e(Html::json($config)) . '">'
            . $demo
            . '<div class="tarsus-top-dock">' . $help . '<button type="button" class="tarsus-jump-top tarsus-hidden" data-action="jump-top" title="'
            . self::t('jump_top') . '" aria-label="' . self::t('jump_top') . '">' . Html::icon('jump_top') . '</button></div>'
            . '<div class="tarsus-progress tarsus-hidden" data-region="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"'
            . ' aria-valuenow="0" aria-label="' . self::t('practice_progress') . '"><div class="tarsus-progress__fill" data-region="progress-fill"></div></div>'
            . '<div class="tarsus-chat-flow" data-region="flow">'
            . '<div class="tarsus-feed-older tarsus-hidden" data-region="feed-older"><span class="tarsus-spinner" aria-hidden="true"></span></div>'
            . '<div class="tarsus-feed-loading"><span class="tarsus-spinner" aria-hidden="true"></span><span>' . self::t('loading_history') . '</span></div>'
            . '</div>'
            . '<form class="tarsus-chat-form" data-region="composer" action="#" autocomplete="off">'
            . '<div class="tarsus-chat-alert tarsus-hidden" role="alert" data-region="alert"></div>'
            . '<div class="tarsus-reply-chip tarsus-hidden" data-region="reply-chip">' . Html::icon('reply')
            . '<span class="tarsus-reply-chip__title" data-region="reply-title"></span>'
            . '<button type="button" class="tarsus-reply-chip__clear" data-action="reply-clear" title="' . self::t('chat_reply_clear')
            . '" aria-label="' . self::t('chat_reply_clear') . '">' . Html::icon('close') . '</button></div>'
            . '<textarea class="tarsus-chat-input" rows="1" maxlength="4000" placeholder="' . self::t('chat_placeholder') . '" aria-label="'
            . self::t('chat_placeholder') . '"></textarea>'
            . '<div class="tarsus-composer-row">'
            . '<div class="tarsus-composer-left">'
            . '<div class="tarsus-mode-switch__group" role="group" aria-label="' . self::t('mode_switch') . '">' . $buttons . '</div>'
            . '<span class="tarsus-xp-badge tarsus-hidden" data-region="xp-badge"><span data-region="xp-earned">0</span>&nbsp;/&nbsp;'
            . '<span data-region="xp-total">0</span>&nbsp;<span data-region="xp-unit">' . self::t('xp') . '</span></span>'
            . '</div>'
            . '<button class="tarsus-btn tarsus-btn--primary tarsus-practice-next tarsus-hidden" type="button" data-action="next">'
            . '<span data-region="practice-label">' . self::t('practice_start') . '</span>'
            . '<span class="tarsus-practice-next__icon">' . Html::icon('send') . '</span>'
            . '<span class="tarsus-spinner tarsus-spinner--light tarsus-hidden" data-region="next-spinner" aria-hidden="true"></span></button>'
            . '<button class="tarsus-btn tarsus-btn--primary tarsus-chat-submit" type="submit">'
            . '<span class="tarsus-spinner tarsus-spinner--light tarsus-hidden" data-region="send-spinner" aria-hidden="true"></span>'
            . '<span>' . self::t('send') . '</span>' . Html::icon('send') . '</button>'
            . '</div>'
            . '</form>'
            . '</div>';
    }

    /**
     * The teacher's material list with status, inclusion switch and actions.
     *
     * @param array<int, array<string, mixed>> $rows Presenter rows plus name, url, typelabel, title.
     */
    public static function materials(string $rootid, array $rows, string $pager, array $config, array $counts): string
    {
        $html = '<div class="tarsus-app tarsus-dashboard" id="' . Html::e($rootid) . '" data-config="' . Html::e(Html::json($config)) . '">';

        $html .= '<div class="tarsus-dashboard__intro">'
            . '<p>' . self::t('materials_intro') . '</p>'
            . '<div class="tarsus-dashboard__stats">'
            . '<span class="tarsus-stat"><strong>' . (int) ($counts['total'] ?? 0) . '</strong> ' . self::t('materials_count_total') . '</span>'
            . '<span class="tarsus-stat"><strong>' . (int) ($counts['indexed'] ?? 0) . '</strong> ' . self::t('materials_count_indexed') . '</span>'
            . '<span class="tarsus-stat"><strong>' . (int) ($counts['included'] ?? 0) . '</strong> ' . self::t('materials_count_included') . '</span>'
            . '</div></div>';

        if ($rows === []) {
            return $html . '<div class="tarsus-empty">' . self::t('no_materials') . '</div></div>';
        }

        $html .= '<div class="tarsus-dashboard__bulk" data-region="bulk">'
            . '<button type="button" class="tarsus-btn tarsus-btn--primary tarsus-btn--sm" data-bulk="index">' . self::t('bulk_index') . '</button>'
            . '<button type="button" class="tarsus-btn tarsus-btn--sm" data-bulk="include">' . self::t('bulk_include') . '</button>'
            . '<button type="button" class="tarsus-btn tarsus-btn--sm" data-bulk="sync">' . Html::icon('refresh') . '<span>' . self::t('bulk_sync') . '</span></button>'
            . '<span class="tarsus-spinner" data-region="bulk-spinner" hidden></span>'
            . '<span class="tarsus-dashboard__message" data-region="bulk-message" role="status"></span>'
            . '</div>'
            . '<p class="tarsus-muted tarsus-small">' . self::t('bulk_help') . '</p>';

        $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table">'
            . '<thead><tr>'
            . '<th scope="col">' . self::t('col_material') . '</th>'
            . '<th scope="col">' . self::t('col_type') . '</th>'
            . '<th scope="col">' . self::t('col_status') . '</th>'
            . '<th scope="col">' . self::t('col_included') . '</th>'
            . '<th scope="col">' . self::t('col_actions') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $name = $row['url'] !== ''
                ? '<a href="' . Html::e($row['url']) . '">' . Html::e($row['objecttitle']) . '</a>'
                : Html::e($row['objecttitle']);
            if ($row['title'] !== $row['objecttitle']) {
                $name .= '<div class="tarsus-muted tarsus-small">' . Html::e($row['title']) . '</div>';
            }
            $html .= '<tr data-region="resource" data-resourceid="' . Html::e($row['resourceid']) . '">'
                . '<td>' . $name . '</td>'
                . '<td class="tarsus-nowrap">' . Html::e($row['typelabel']) . '</td>'
                . '<td class="tarsus-nowrap"><span class="' . Html::e($row['badgeclass']) . '" data-region="status-badge">'
                . Html::e($row['statuslabel']) . '</span>'
                . '<span class="tarsus-spinner tarsus-spinner--inline" data-region="status-spinner"' . ($row['inflight'] ? '' : ' hidden') . '></span></td>'
                . '<td><label class="tarsus-switch"><input type="checkbox" role="switch" data-region="include-toggle" aria-label="'
                . self::t('col_included') . '"' . ($row['included'] ? ' checked' : '') . ($row['caninclude'] ? '' : ' disabled')
                . '><span class="tarsus-switch__track" aria-hidden="true"></span></label></td>'
                . '<td><div class="tarsus-actions">'
                . self::actionButton('index', 'tarsus-btn--primary', $row['canindex'])
                . self::actionButton('reindex', '', $row['canreindex'])
                . self::actionButton('retry', 'tarsus-btn--warning', $row['canretry'])
                . self::actionButton('remove', 'tarsus-btn--danger', $row['canremove'])
                . '</div></td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>' . $pager . '</div>';
        return $html;
    }

    private static function actionButton(string $action, string $modifier, bool $visible): string
    {
        return '<button type="button" class="tarsus-btn tarsus-btn--sm ' . $modifier . '" data-action="' . $action . '"'
            . ($visible ? '' : ' hidden') . '>' . self::t('action_' . $action) . '</button>';
    }

    /**
     * Simple previous/next paging.
     */
    public static function pager(int $page, int $pages, callable $url): string
    {
        if ($pages <= 1) {
            return '';
        }
        $html = '<nav class="tarsus-pager" aria-label="' . self::t('pager') . '">';
        if ($page > 0) {
            $html .= '<a class="tarsus-btn tarsus-btn--sm" href="' . Html::e($url($page - 1)) . '">&lsaquo; ' . self::t('pager_previous') . '</a>';
        }
        $html .= '<span class="tarsus-pager__state">' . self::t('pager_state', ['page' => $page + 1, 'pages' => $pages]) . '</span>';
        if ($page < $pages - 1) {
            $html .= '<a class="tarsus-btn tarsus-btn--sm" href="' . Html::e($url($page + 1)) . '">' . self::t('pager_next') . ' &rsaquo;</a>';
        }
        return $html . '</nav>';
    }

    /**
     * The teacher's learner overview: summary card and per-learner table.
     *
     * @param array<string, mixed> $overview From Activity\Learners::overview().
     */
    public static function learners(array $overview, bool $hastest, bool $hasbuild, int $threshold): string
    {
        if (!$hastest && !$hasbuild) {
            // Ask a question alone: a read-only activity without score or
            // progress - just who took part and when.
            return self::learnersReadOnly($overview);
        }
        $summary = $overview['summary'];
        $total = max(0, (int) $summary['total']);
        $pct = static fn(int $n): string => $total > 0 ? number_format($n / $total * 100, 1, '.', '') : '0';
        $avg = (float) $summary['avgprogress'];
        $ring = number_format(56.55 * (1 - max(0, min(100, $avg)) / 100), 2, '.', '');

        $html = '<div class="tarsus-app tarsus-learners">';
        $html .= '<div class="tarsus-summary">'
            . '<div class="tarsus-summary__hero">'
            . '<span class="tarsus-ring" style="--tarsus-ring:64px"><svg viewBox="0 0 24 24" width="64" height="64" role="img" aria-label="'
            . self::t('avg_progress') . ' ' . (int) round($avg) . '%"><circle class="tarsus-ring__track" cx="12" cy="12" r="9"></circle>'
            . '<circle class="tarsus-ring__fill" cx="12" cy="12" r="9" style="stroke-dashoffset:' . $ring . '"></circle></svg>'
            . '<span class="tarsus-ring__value">' . (int) round($avg) . '%</span></span>'
            . '<div class="tarsus-summary__text"><div class="tarsus-eyebrow">' . self::t('avg_progress') . '</div>'
            . '<div class="tarsus-headline">' . self::t('n_learners', ['count' => $total]) . '</div>'
            . ($summary['avggrade'] !== null ? '<div class="tarsus-muted">' . self::t('avg_score', ['score' => (int) round((float) $summary['avggrade'])]) . '</div>' : '')
            . '</div></div>'
            . '<div class="tarsus-summary__dist">'
            . '<div class="tarsus-stack" role="img" aria-label="' . self::t('distribution', [
                'completed' => (int) $summary['completed'], 'inprogress' => (int) $summary['inprogress'], 'notstarted' => (int) $summary['notstarted'],
            ]) . '">'
            . '<span class="tarsus-stack__part tarsus-stack__part--done" style="width:' . $pct((int) $summary['completed']) . '%"></span>'
            . '<span class="tarsus-stack__part tarsus-stack__part--open" style="width:' . $pct((int) $summary['inprogress']) . '%"></span>'
            . '<span class="tarsus-stack__part tarsus-stack__part--none" style="width:' . $pct((int) $summary['notstarted']) . '%"></span>'
            . '</div>'
            . '<div class="tarsus-legend">'
            . '<span><i class="tarsus-dot tarsus-dot--done"></i>' . self::t('n_completed', ['count' => (int) $summary['completed']]) . '</span>'
            . '<span><i class="tarsus-dot tarsus-dot--open"></i>' . self::t('n_inprogress', ['count' => (int) $summary['inprogress']]) . '</span>'
            . '<span><i class="tarsus-dot tarsus-dot--none"></i>' . self::t('n_notstarted', ['count' => (int) $summary['notstarted']]) . '</span>'
            . '</div>';

        $buckets = (array) $summary['buckets'];
        $peak = max(1, max($buckets ?: [0]));
        $labels = ['0-25', '25-50', '50-75', '75-100'];
        $html .= '<div class="tarsus-histogram" role="img" aria-label="' . self::t('histogram') . '">';
        foreach ($buckets as $i => $count) {
            $html .= '<div class="tarsus-histogram__bucket" title="' . Html::e($labels[$i] . ' %: ' . $count) . '">'
                . '<span class="tarsus-histogram__count">' . (int) $count . '</span>'
                . '<span class="tarsus-histogram__bar"><span class="tarsus-histogram__fill" style="height:' . (int) round($count / $peak * 100) . '%"></span></span>'
                . '<span class="tarsus-histogram__label">' . Html::e($labels[$i]) . '</span></div>';
        }
        $html .= '</div></div></div>';

        if ($threshold > 0) {
            $html .= '<p class="tarsus-muted tarsus-small">' . self::t('learners_threshold', ['threshold' => $threshold]) . '</p>';
        }

        if ($overview['learners'] === []) {
            return $html . '<div class="tarsus-empty">' . self::t('no_learners') . '</div></div>';
        }

        $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr>'
            . '<th scope="col">' . self::t('col_learner') . '</th>'
            . '<th scope="col">' . self::t('col_progress') . '</th>'
            . ($hastest ? '<th scope="col">' . self::t('xp') . '</th>' : '')
            . ($hasbuild ? '<th scope="col">' . self::t('learning_units') . '</th>' : '')
            . (($hastest || $hasbuild) ? '<th scope="col">' . self::t('col_score') . '</th>' : '')
            . '<th scope="col">' . self::t('col_lp_status') . '</th>'
            . '<th scope="col">' . self::t('col_last_activity') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($overview['learners'] as $learner) {
            $statuskey = $learner['status'] === Scoring::LP_COMPLETED ? 'lp_completed'
                : ($learner['status'] === Scoring::LP_IN_PROGRESS ? 'lp_in_progress' : 'lp_not_attempted');
            $statusclass = $learner['status'] === Scoring::LP_COMPLETED ? 'success'
                : ($learner['status'] === Scoring::LP_IN_PROGRESS ? 'info' : 'neutral');
            $name = trim($learner['lastname'] . ', ' . $learner['firstname'], ', ');
            $html .= '<tr>'
                . '<td>' . Html::e($name) . ($learner['member'] ? '' : ' <span class="tarsus-muted tarsus-small">(' . self::t('not_member') . ')</span>') . '</td>'
                . '<td><div class="tarsus-minibar"><span class="tarsus-minibar__track"><span class="tarsus-minibar__fill" style="width:'
                . (int) round($learner['progress']) . '%"></span></span><span>' . (int) round($learner['progress']) . ' %</span></div></td>'
                . ($hastest ? '<td class="tarsus-nowrap">' . self::figure($learner['earnedxp']) . ' / ' . self::figure($learner['totalxp']) . '</td>' : '')
                . ($hasbuild ? '<td class="tarsus-nowrap">' . self::figure($learner['earnedunits']) . ' / ' . self::figure($learner['totalunits']) . '</td>' : '')
                . (($hastest || $hasbuild) ? '<td>' . ($learner['grade'] === null ? '&ndash;' : (int) round($learner['grade'])) . '</td>' : '')
                . '<td><span class="tarsus-badge tarsus-badge--' . $statusclass . '">' . self::t($statuskey) . '</span></td>'
                . '<td class="tarsus-nowrap">' . ($learner['lastactivity'] > 0 ? Html::e(self::date((int) $learner['lastactivity'])) : '&ndash;') . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    /**
     * The learner list of a read-only activity (neither Test nor Build
     * knowledge active): participation and last activity only.
     *
     * @param array<string, mixed> $overview
     */
    private static function learnersReadOnly(array $overview): string
    {
        $html = '<div class="tarsus-app tarsus-learners">'
            . '<div class="tarsus-readonly-note">' . Html::icon('mode_ask', 'tarsus-icon tarsus-icon--inline') . ' ' . self::t('learners_readonly') . '</div>';
        if ($overview['learners'] === []) {
            return $html . '<div class="tarsus-empty">' . self::t('no_learners') . '</div></div>';
        }
        $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr>'
            . '<th scope="col">' . self::t('col_learner') . '</th>'
            . '<th scope="col">' . self::t('col_participation') . '</th>'
            . '<th scope="col">' . self::t('col_last_activity') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($overview['learners'] as $learner) {
            $name = trim($learner['lastname'] . ', ' . $learner['firstname'], ', ');
            $html .= '<tr>'
                . '<td>' . Html::e($name) . ($learner['member'] ? '' : ' <span class="tarsus-muted tarsus-small">(' . self::t('not_member') . ')</span>') . '</td>'
                . '<td><span class="tarsus-badge tarsus-badge--' . ($learner['started'] ? 'info' : 'neutral') . '">' . self::t($learner['started'] ? 'participated' : 'lp_not_attempted') . '</span></td>'
                . '<td class="tarsus-nowrap">' . ($learner['lastactivity'] > 0 ? Html::e(self::date((int) $learner['lastactivity'])) : '&ndash;') . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div></div>';
    }

    /**
     * The help button with its panel of support links and videos. Empty
     * when nothing is configured.
     *
     * @param array<int, array{title: string, url: string, embed: string}> $links
     */
    public static function help(array $links, string $id = 'tarsus-help'): string
    {
        if ($links === []) {
            return '';
        }
        $items = '';
        foreach ($links as $index => $link) {
            $video = $link['embed'] !== '';
            $items .= '<li class="tarsus-help__item' . ($video ? ' tarsus-help__item--video' : '') . '">'
                . Html::icon($video ? 'video' : 'link', 'tarsus-icon tarsus-help__icon')
                . '<a href="' . Html::e($link['url']) . '" target="_blank" rel="noopener noreferrer">' . Html::e($link['title']) . '</a>'
                . ($video ? '<div class="tarsus-help__embed" data-embed="' . Html::e($link['embed']) . '" data-title="' . Html::e($link['title']) . '"></div>' : '')
                . '</li>';
        }
        return '<div class="tarsus-help">'
            . '<button type="button" class="tarsus-help__button" data-action="help-toggle" aria-expanded="false" aria-controls="' . Html::e($id) . '" title="'
            . self::t('help') . '">' . Html::icon('help') . '<span>' . self::t('help') . '</span></button>'
            . '<div class="tarsus-help__panel tarsus-hidden" data-region="help-panel" id="' . Html::e($id) . '" role="dialog" aria-label="' . self::t('help_title') . '">'
            . '<div class="tarsus-help__head"><strong>' . self::t('help_title') . '</strong>'
            . '<button type="button" class="tarsus-help__close" data-action="help-close" aria-label="' . self::t('learn_image_close') . '">' . Html::icon('close') . '</button></div>'
            . '<ul class="tarsus-help__list">' . $items . '</ul>'
            . '</div></div>';
    }

    /**
     * A horizontal usage bar: used against limit, coloured by severity.
     */
    public static function usageBar(int $used, int $limit, string $level): string
    {
        $percent = $limit > 0 ? min(100, $used / $limit * 100) : 0;
        $label = $limit > 0
            ? self::t('usage_bar_label', ['used' => number_format($used), 'limit' => number_format($limit), 'percent' => number_format($percent, 0)])
            : self::t('usage_bar_nolimit', ['used' => number_format($used)]);
        return '<div class="tarsus-usage tarsus-usage--' . Html::e($level) . '" role="img" aria-label="' . $label . '">'
            . '<div class="tarsus-usage__track"><span class="tarsus-usage__fill" style="width:' . number_format($percent, 1, '.', '') . '%"></span>'
            . '<i class="tarsus-usage__mark" style="left:50%"></i><i class="tarsus-usage__mark" style="left:75%"></i><i class="tarsus-usage__mark" style="left:90%"></i></div>'
            . '<div class="tarsus-usage__label">' . $label . '</div></div>';
    }

    /**
     * The teacher's analytics: this month's figures, a 30-day chart of
     * requests, the split by mode and the most active learners.
     *
     * @param array<string, mixed> $data
     */
    public static function analytics(array $data, string $help = ''): string
    {
        $month = $data['month'];
        $html = '<div class="tarsus-app tarsus-analytics">' . ($help !== '' ? '<div class="tarsus-help-row">' . $help . '</div>' : '');

        $cards = [
            ['value' => number_format((int) $month['requests']), 'label' => self::t('analytics_requests_month')],
            ['value' => number_format((int) $month['credits']), 'label' => self::t('analytics_credits_month')],
            ['value' => number_format((int) $month['learners']), 'label' => self::t('analytics_active_learners')],
            ['value' => number_format((float) $month['share'], 1) . ' %', 'label' => self::t('analytics_share')],
        ];
        $html .= '<div class="tarsus-cards">';
        foreach ($cards as $card) {
            $html .= '<div class="tarsus-stat-card"><div class="tarsus-stat-card__value">' . Html::e($card['value']) . '</div>'
                . '<div class="tarsus-stat-card__label">' . $card['label'] . '</div></div>';
        }
        $html .= '</div>';

        // Installation-wide budget, so a teacher knows when the month runs dry.
        $budget = $data['budget'];
        $html .= '<div class="tarsus-panel"><div class="tarsus-eyebrow">' . self::t('analytics_budget') . '</div>'
            . self::usageBar((int) $budget['used'], (int) $budget['limit'], (string) $budget['level'])
            . '<p class="tarsus-muted tarsus-small">' . self::t('analytics_budget_note', ['reset' => (string) $budget['reset']]) . '</p></div>';

        // Requests per day, last 30 days.
        $days = $data['days'];
        $max = 1;
        foreach ($days as $day) {
            $max = max($max, (int) $day['calls']);
        }
        $count = max(1, count($days));
        $w = 900;
        $h = 220;
        $pad = ['l' => 36, 'r' => 8, 't' => 10, 'b' => 30];
        $plotw = $w - $pad['l'] - $pad['r'];
        $ploth = $h - $pad['t'] - $pad['b'];
        $slot = $plotw / $count;
        $svg = '<svg class="tarsus-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . self::t('analytics_chart_label') . '">';
        foreach ([0, 0.5, 1] as $tick) {
            $y = $pad['t'] + $ploth * (1 - $tick);
            $svg .= '<line class="tarsus-chart__grid" x1="' . $pad['l'] . '" x2="' . ($w - $pad['r']) . '" y1="' . $y . '" y2="' . $y . '"></line>'
                . '<text class="tarsus-chart__tick" x="' . ($pad['l'] - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . (int) round($max * $tick) . '</text>';
        }
        foreach (array_values($days) as $index => $day) {
            $calls = (int) $day['calls'];
            $barh = $max > 0 ? $ploth * $calls / $max : 0;
            $x = $pad['l'] + $slot * $index + $slot * 0.15;
            $y = $pad['t'] + $ploth - $barh;
            $svg .= '<rect class="tarsus-chart__bar' . ($calls === 0 ? ' tarsus-chart__bar--empty' : '') . '" x="' . number_format($x, 1, '.', '') . '" y="' . number_format($y, 1, '.', '')
                . '" width="' . number_format($slot * 0.7, 1, '.', '') . '" height="' . number_format(max($barh, $calls > 0 ? 2 : 0), 1, '.', '') . '" rx="2">'
                . '<title>' . Html::e($day['label']) . ': ' . self::t('analytics_tooltip', ['calls' => $calls, 'credits' => (int) $day['credits']]) . '</title></rect>';
            if ($index % 5 === 0 || $index === $count - 1) {
                $svg .= '<text class="tarsus-chart__tick" x="' . number_format($x + $slot * 0.35, 1, '.', '') . '" y="' . ($h - 10) . '" text-anchor="middle">' . Html::e($day['short']) . '</text>';
            }
        }
        $svg .= '</svg>';
        $html .= '<div class="tarsus-panel"><div class="tarsus-eyebrow">' . self::t('analytics_chart_title') . '</div>' . $svg . '</div>';

        // Split by mode.
        $html .= '<div class="tarsus-analytics__grid">';
        $html .= '<div class="tarsus-panel"><div class="tarsus-eyebrow">' . self::t('analytics_by_mode') . '</div>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . self::t('analytics_mode') . '</th><th class="tarsus-num">'
            . self::t('status_calls') . '</th><th class="tarsus-num">' . self::t('status_credits') . '</th></tr></thead><tbody>';
        foreach ($data['modes'] as $mode) {
            $html .= '<tr><td>' . Html::icon($mode['icon'], 'tarsus-icon tarsus-icon--inline') . ' ' . Html::e($mode['label']) . '</td><td class="tarsus-num">'
                . number_format((int) $mode['calls']) . '</td><td class="tarsus-num">' . number_format((int) $mode['credits']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        // Most active learners.
        $html .= '<div class="tarsus-panel"><div class="tarsus-eyebrow">' . self::t('analytics_top_learners') . '</div>';
        if ($data['top'] === []) {
            $html .= '<p class="tarsus-muted">' . self::t('analytics_none') . '</p>';
        } else {
            $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . self::t('col_learner') . '</th><th class="tarsus-num">'
                . self::t('status_calls') . '</th><th>' . self::t('col_lastactivity') . '</th></tr></thead><tbody>';
            foreach ($data['top'] as $row) {
                $html .= '<tr><td>' . Html::e($row['name']) . '</td><td class="tarsus-num">' . number_format((int) $row['calls']) . '</td>'
                    . '<td class="tarsus-nowrap">' . Html::e(self::date((int) $row['last'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div></div>';

        $html .= '<p class="tarsus-muted tarsus-small">' . self::t('analytics_note') . '</p></div>';
        return $html;
    }

    /**
     * The administrator's plan and usage panel on the status page.
     *
     * @param array<string, mixed> $data
     */
    public static function planUsage(array $data): string
    {
        $rows = '';
        foreach ($data['rows'] as [$label, $value]) {
            $rows .= '<tr><th scope="row" style="width:30%">' . $label . '</th><td>' . $value . '</td></tr>';
        }
        $html = '<div class="tarsus-panel tarsus-panel--plan">'
            . '<div class="tarsus-plan-head"><div><div class="tarsus-eyebrow">' . self::t('plan_current') . '</div>'
            . '<div class="tarsus-headline">' . Html::e($data['plan']) . '</div></div>'
            . '<div class="tarsus-plan-head__usage">' . self::usageBar((int) $data['used'], (int) $data['limit'], (string) $data['level']) . '</div></div>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table"><tbody>' . $rows . '</tbody></table></div>';
        if ($data['items'] !== []) {
            $html .= '<details class="tarsus-details"><summary>' . self::t('usage_breakdown') . '</summary>'
                . '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . self::t('usage_service') . '</th><th>'
                . self::t('usage_name') . '</th><th class="tarsus-num">' . self::t('status_credits') . '</th></tr></thead><tbody>';
            foreach ($data['items'] as $item) {
                if ((int) $item['credits'] === 0) {
                    continue;
                }
                $html .= '<tr><td>' . Html::e($item['service']) . '</td><td>' . Html::e($item['name']) . '</td><td class="tarsus-num">' . number_format((int) $item['credits']) . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        return $html . '</div>';
    }

    public static function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    public static function date(int $timestamp): string
    {
        try {
            return \ilDatePresentation::formatDate(new \ilDateTime($timestamp, IL_CAL_UNIX));
        } catch (\Throwable $e) {
            return date('Y-m-d H:i', $timestamp);
        }
    }
}
