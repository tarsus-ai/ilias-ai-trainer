<?php

namespace TarsusIlias\Output;

use TarsusIlias\Activity\Scoring;
use TarsusIlias\Support\Ilias;

/**
 * Server-rendered markup of the plugin's screens. The chat's cards are
 * rendered client-side (tarsus.js); everything here is the frame they live
 * in, and the teacher's material and learner lists.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class Views
{
    private static function t(string $key, array $params = []): string
    {
        return Html::e(Ilias::txt($key, $params));
    }

    /**
     * The coach: feed, progress bar, composer with the mode switch, the
     * recorder for spoken questions, the bar of the live interaction, and
     * the panel that slides in from the right (sources for everyone;
     * settings, evaluation and history for teachers).
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

        $teacherbar = '';
        if (!empty($config['teacher'])) {
            $teacherbar = '<div class="tarsus-teacher-bar" role="group" aria-label="' . self::t('teacher_tools') . '">';
            foreach (['settings' => 'settings', 'stats' => 'chart', 'history' => 'history'] as $panel => $icon) {
                $teacherbar .= '<button type="button" class="tarsus-btn tarsus-btn--sm tarsus-teacher-bar__btn" data-action="panel" data-panel="'
                    . $panel . '">' . Html::icon($icon) . '<span>' . self::t('panel_' . $panel) . '</span></button>';
            }
            $teacherbar .= '</div>';
        }

        $voice = '';
        $voicetoggle = '';
        if (!empty($config['voice'])) {
            $button = static fn(string $action, string $icon, string $label, string $extra = ''): string =>
                '<button type="button" class="tarsus-voice__btn' . $extra . '" data-action="' . $action . '">'
                . Html::icon($icon) . '<span>' . self::t($label) . '</span></button>';
            $voice = '<div class="tarsus-voice tarsus-hidden" data-region="voice" data-state="idle">'
                . '<span class="tarsus-voice__dot" aria-hidden="true"></span>'
                . '<span class="tarsus-voice__state" data-region="voice-state" aria-live="polite">' . self::t('voice_question') . '</span>'
                . '<span class="tarsus-voice__time" data-region="voice-time">0:00</span>'
                . '<span class="tarsus-voice__wave" data-region="voice-wave" aria-hidden="true"></span>'
                . '<audio class="tarsus-hidden" data-region="voice-audio" preload="auto"></audio>'
                . '<span class="tarsus-voice__actions">'
                . $button('voice-start', 'record', 'voice_start', ' tarsus-voice__btn--record')
                . $button('voice-pause', 'audio_pause', 'voice_pause')
                . $button('voice-resume', 'record', 'voice_resume')
                . $button('voice-stop', 'stop', 'voice_stop')
                . $button('voice-play', 'audio_play', 'voice_play')
                . $button('voice-discard', 'trash', 'voice_discard')
                . '</span></div>';
            $voicetoggle = '<button type="button" class="tarsus-btn tarsus-btn--ghost tarsus-voice-toggle tarsus-hidden" data-action="voice-toggle"'
                . ' title="' . self::t('voice_to_voice') . '" aria-label="' . self::t('voice_to_voice') . '">'
                . '<span data-region="voice-toggle-mic">' . Html::icon('mic') . '</span>'
                . '<span class="tarsus-hidden" data-region="voice-toggle-text">' . Html::icon('keyboard') . '</span>'
                . '<span class="tarsus-voice-toggle__label" data-region="voice-toggle-label">' . self::t('voice_to_voice') . '</span></button>';
        }

        $live = '';
        if (in_array('discuss', $modes, true)) {
            $live = '<div class="tarsus-live-bar tarsus-hidden" data-region="live-bar">'
                . '<span class="tarsus-live-bar__text" data-region="live-text">' . self::t('live_intro') . '</span>'
                . '<button type="button" class="tarsus-btn tarsus-btn--primary" data-action="live-start">' . Html::icon('mode_discuss')
                . '<span data-region="live-label">' . self::t('live_start') . '</span>'
                . '<span class="tarsus-spinner tarsus-spinner--light tarsus-hidden" data-region="live-spinner" aria-hidden="true"></span></button>'
                . '</div>';
        }

        $drawer = '<div class="tarsus-drawer" data-region="drawer" hidden>'
            . '<div class="tarsus-drawer__backdrop" data-action="drawer-close"></div>'
            . '<aside class="tarsus-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="' . Html::e($containerid) . '-drawer-title" tabindex="-1">'
            . '<header class="tarsus-drawer__head"><h2 class="tarsus-drawer__title" id="' . Html::e($containerid) . '-drawer-title" data-region="drawer-title"></h2>'
            . '<button type="button" class="tarsus-drawer__close" data-action="drawer-close" title="' . self::t('panel_close') . '" aria-label="'
            . self::t('panel_close') . '">' . Html::icon('close') . '</button></header>'
            . '<div class="tarsus-drawer__body" data-region="drawer-body"></div>'
            . '</aside></div>';

        return '<div class="tarsus-app tarsus-chat-box" id="' . Html::e($containerid) . '" data-config="' . Html::e(Html::json($config)) . '">'
            . $demo
            . $teacherbar
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
            . $voice
            . $live
            . '<div class="tarsus-composer-row">'
            . '<div class="tarsus-composer-left">'
            . '<div class="tarsus-mode-switch__group" role="group" aria-label="' . self::t('mode_switch') . '">' . $buttons . '</div>'
            . '<span class="tarsus-xp-badge tarsus-hidden" data-region="xp-badge"><span data-region="xp-earned">0</span>&nbsp;/&nbsp;'
            . '<span data-region="xp-total">0</span>&nbsp;<span data-region="xp-unit">' . self::t('xp') . '</span></span>'
            . '<span class="tarsus-grade-chip tarsus-hidden" data-region="grade-chip" title="' . self::t('grade_title') . '"></span>'
            . '</div>'
            . '<div class="tarsus-composer-right">'
            . $voicetoggle
            . '<button class="tarsus-btn tarsus-btn--primary tarsus-practice-next tarsus-hidden" type="button" data-action="next">'
            . '<span data-region="practice-label">' . self::t('practice_start') . '</span>'
            . '<span class="tarsus-practice-next__icon">' . Html::icon('send') . '</span>'
            . '<span class="tarsus-spinner tarsus-spinner--light tarsus-hidden" data-region="next-spinner" aria-hidden="true"></span></button>'
            . '<button class="tarsus-btn tarsus-btn--primary tarsus-chat-submit" type="submit">'
            . '<span class="tarsus-spinner tarsus-spinner--light tarsus-hidden" data-region="send-spinner" aria-hidden="true"></span>'
            . '<span>' . self::t('send') . '</span>' . Html::icon('send') . '</button>'
            . '</div>'
            . '</div>'
            . '</form>'
            . $drawer
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
    public static function usageBar(float $used, int $limit, string $level): string
    {
        $percent = $limit > 0 ? min(100, $used / $limit * 100) : 0;
        // The brain's figure, written the way the user's language writes numbers.
        $label = $limit > 0
            ? self::t('usage_bar_label', [
                'used' => \TarsusIlias\Support\Budget::format($used),
                'limit' => \TarsusIlias\Support\Budget::format((float) $limit),
                'percent' => number_format($percent, 0),
            ])
            : self::t('usage_bar_nolimit', ['used' => \TarsusIlias\Support\Budget::format($used)]);
        return '<div class="tarsus-usage tarsus-usage--' . Html::e($level) . '" role="img" aria-label="' . $label . '">'
            . '<div class="tarsus-usage__track"><span class="tarsus-usage__fill" style="width:' . number_format($percent, 1, '.', '') . '%"></span>'
            . '<i class="tarsus-usage__mark" style="left:50%"></i><i class="tarsus-usage__mark" style="left:75%"></i><i class="tarsus-usage__mark" style="left:90%"></i></div>'
            . '<div class="tarsus-usage__label">' . $label . '</div></div>';
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
            . '<div class="tarsus-plan-head__usage">' . self::usageBar((float) $data['used'], (int) $data['limit'], (string) $data['level']) . '</div></div>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table"><tbody>' . $rows . '</tbody></table></div>';
        if ($data['items'] !== []) {
            $html .= '<details class="tarsus-details"><summary>' . self::t('usage_breakdown') . '</summary>'
                . '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . self::t('usage_service') . '</th><th>'
                . self::t('usage_name') . '</th><th class="tarsus-num">' . self::t('status_credits') . '</th></tr></thead><tbody>';
            foreach ($data['items'] as $item) {
                if ((float) $item['credits'] <= 0) {
                    continue;
                }
                $html .= '<tr><td>' . Html::e($item['service']) . '</td><td>' . Html::e($item['name']) . '</td><td class="tarsus-num">'
                    . Html::e(\TarsusIlias\Support\Budget::format((float) $item['credits'])) . '</td></tr>';
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
