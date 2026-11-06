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
    public static function chat(string $containerid, array $config): string
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
            . '<div class="tarsus-top-dock"><button type="button" class="tarsus-jump-top tarsus-hidden" data-action="jump-top" title="'
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
