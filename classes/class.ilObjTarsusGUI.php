<?php

/**
 * The screens of a Tarsus object: the coach (the learner's chat, with the
 * teacher's settings, evaluation and history panels sliding in from the
 * right), the teacher's material list and settings, plus the AJAX
 * endpoints the coach and the material list talk to.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 *
 * @ilCtrl_isCalledBy ilObjTarsusGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjTarsusGUI: ilPermissionGUI, ilInfoScreenGUI, ilObjectCopyGUI, ilCommonActionDispatcherGUI, ilLearningProgressGUI
 */

require_once __DIR__ . '/../src/autoload.php';

use TarsusIlias\Activity\Didactics;
use TarsusIlias\Activity\Learners;
use TarsusIlias\Activity\Scope;
use TarsusIlias\Activity\Scoring;
use TarsusIlias\Client\ClientInterface;
use TarsusIlias\Index\Inclusions;
use TarsusIlias\Index\Reconciler;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Material\Resolver;
use TarsusIlias\Output\Html;
use TarsusIlias\Output\Icons;
use TarsusIlias\Output\Presenter;
use TarsusIlias\Output\Views;
use TarsusIlias\Service\LearnerApi;
use TarsusIlias\Service\ManagerApi;
use TarsusIlias\Service\SettingsApi;
use TarsusIlias\Service\TeacherApi;
use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Help;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;
use ILIAS\UI\Component\Input\Container\Form\Standard as StandardForm;
use Psr\Http\Message\UploadedFileInterface;

class ilObjTarsusGUI extends ilObjectPluginGUI
{
    /** Materials listed per page of the teacher's list (one brain call per page). */
    public const PAGE_SIZE = 10;

    /** @var string[] Commands that answer JSON and need read permission. */
    private const AJAX_READ = [
        'ajaxFeed', 'ajaxAsk', 'ajaxAskVoice', 'ajaxPracticeNext', 'ajaxPracticeCheck', 'ajaxLearnNext', 'ajaxAudio',
        'ajaxDiscuss', 'ajaxReferences',
    ];

    /** @var string[] Commands that answer JSON and need write permission. */
    private const AJAX_WRITE = [
        'ajaxAction', 'ajaxInclude', 'ajaxSync',
        'ajaxStats', 'ajaxHistory', 'ajaxAnalytics', 'ajaxSettings', 'ajaxSaveSettings',
    ];

    /** Longest spoken question accepted, in seconds. */
    public const VOICE_MAX_SECONDS = 180;

    /** The coach's modes, in the order of the settings. */
    private const MODES = ['ask', 'test', 'build', 'discuss'];

    /** @var string[] Interface texts of the coach. */
    private const CHAT_STRINGS = [
        'a11y_you_asked', 'a11y_question', 'a11y_learning_unit', 'a11y_working', 'a11y_answered', 'a11y_correct', 'a11y_incorrect', 'a11y_partial',
        'a11y_your_answer_correct', 'a11y_your_answer_incorrect', 'a11y_not_correct', 'a11y_answer_missing', 'a11y_mode_ask', 'a11y_mode_next', 'a11y_mode_discuss',
        'practice_start', 'practice_next', 'practice_check', 'practice_answer_placeholder',
        'practice_variable_placeholder', 'practice_score', 'practice_correct_value', 'xp', 'learning_units', 'learn_slide_of',
        'learn_previous', 'learn_next', 'learn_image_enlarge', 'learn_image_close', 'learn_audio', 'learn_audio_play',
        'learn_audio_pause', 'learn_ask_about', 'learn_ask_about_unit', 'chat_reply_about', 'chat_reply_clear', 'learn_complete_title',
        'frame_title', 'frame_enlarge', 'learn_motivation_title', 'mode_build', 'mode_test', 'mode_ask', 'mode_discuss',
        'unexpected_error', 'session_lost', 'grade_chip', 'grade_title',
        'voice_to_voice', 'voice_to_text', 'voice_start', 'voice_stop', 'voice_pause', 'voice_resume', 'voice_discard',
        'voice_play', 'voice_recording', 'voice_paused', 'voice_ready', 'voice_question', 'voice_transcribing',
        'voice_transcript_note', 'voice_limit', 'voice_unsupported', 'mic_denied', 'mic_notfound', 'mic_failed',
        'live_start', 'live_new', 'live_intro', 'live_expired', 'live_end_confirm', 'live_end', 'dialog_cancel', 'live_title', 'live_starting',
        'sources_show', 'sources_title', 'sources_count', 'sources_none', 'sources_open', 'sources_transcript',
        'source_type_text', 'source_type_table', 'source_type_image', 'source_type_audio', 'source_type_video',
        'source_type_question', 'source_type_form', 'source_type_other', 'panel_close', 'loading',
        'proc_ask_classify', 'proc_ask_search', 'proc_ask_media', 'proc_ask_select', 'proc_ask_cube',
        'proc_ask_dqr', 'proc_ask_compose', 'proc_ask_verify', 'proc_ask_polish', 'proc_ask_transcribe',
        'proc_practice_cube_create', 'proc_practice_baseline', 'proc_practice_evaluate',
        'proc_practice_compare', 'proc_practice_surprise', 'proc_practice_reestimate',
        'proc_practice_align', 'proc_practice_cells', 'proc_practice_history', 'proc_practice_action',
        'proc_practice_taxonomy', 'proc_practice_difficulty', 'proc_practice_format',
        'proc_practice_generate', 'proc_practice_operators', 'proc_practice_options',
        'proc_practice_solution', 'proc_practice_polish',
        'proc_learn_path_create', 'proc_learn_progress', 'proc_learn_objectives', 'proc_learn_cells',
        'proc_learn_next', 'proc_learn_passages', 'proc_learn_dqr', 'proc_learn_split',
        'proc_learn_examples', 'proc_learn_link', 'proc_learn_check', 'proc_learn_path_update',
        'proc_learn_polish',
    ];

    /** @var string[] Interface texts of the teacher's panels. */
    private const TEACHER_STRINGS = [
        'panel_settings', 'panel_stats', 'panel_history', 'settings_save', 'settings_saved', 'settings_saving',
        'settings_open_full', 'form_invalid', 'stats_learners', 'stats_avg_grade', 'stats_completed', 'stats_avg_progress',
        'stats_distribution', 'stats_distribution_help', 'stats_status', 'stats_status_help', 'stats_col_name',
        'stats_col_grade', 'stats_col_progress', 'stats_col_status', 'stats_col_build', 'stats_col_test', 'stats_col_last',
        'stats_history', 'stats_group_all', 'stats_group', 'stats_export', 'stats_none', 'stats_count_learners',
        'lp_completed', 'lp_in_progress', 'lp_not_attempted', 'history_readonly', 'history_pick', 'history_growth',
        'history_xp', 'history_units', 'history_empty', 'history_more', 'history_back', 'history_of',
    ];

    public function getType(): string
    {
        return ilTarsusPlugin::ID;
    }

    public function getAfterCreationCmd(): string
    {
        return 'editSettings';
    }

    public function getStandardCmd(): string
    {
        return 'showContent';
    }

    /**
     * @return ilObjTarsus
     */
    private function tarsus(): ilObjTarsus
    {
        /** @var ilObjTarsus $object */
        $object = $this->object;
        return $object;
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    public function performCommand(string $cmd): void
    {
        if (in_array($cmd, self::AJAX_READ, true) || in_array($cmd, self::AJAX_WRITE, true)) {
            $this->performAjax($cmd);
            return;
        }

        switch ($cmd) {
            case 'showContent':
            case 'resetChat':
                $this->checkPermission('read');
                $this->$cmd();
                break;

            case 'showMaterials':
            case 'exportLearners':
            case 'editSettings':
            case 'updateSettings':
                $this->checkPermission('write');
                $this->$cmd();
                break;

            default:
                $this->checkPermission('read');
                $this->showContent();
                break;
        }
    }

    private function performAjax(string $cmd): void
    {
        $permission = in_array($cmd, self::AJAX_WRITE, true) ? 'write' : 'read';
        if (!$this->checkPermissionBool($permission)) {
            Ilias::sendJson(['status' => 'error', 'ok' => false, 'error' => $this->lng->txt('permission_denied')], 403);
        }
        if ($permission === 'read' && !$this->tarsus()->isOnline() && !$this->checkPermissionBool('write')) {
            Ilias::sendJson(['status' => 'error', 'ok' => false, 'error' => $this->txt('offline')], 403);
        }
        try {
            $this->$cmd();
        } catch (Throwable $e) {
            Ilias::log($cmd . ' failed: ' . $e->getMessage(), 'error');
            Ilias::sendJson(['status' => 'error', 'ok' => false, 'error' => $this->txt('unexpected_error')], 500);
        }
    }

    // ------------------------------------------------------------------
    // Tabs
    // ------------------------------------------------------------------

    protected function setTabs(): void
    {
        $ref = $this->object->getRefId();

        if ($this->access->checkAccess('read', '', $ref)) {
            $this->tabs->addTab('content', $this->txt('tab_content'), $this->ctrl->getLinkTarget($this, 'showContent'));
        }

        $this->addInfoTab();

        if ($this->access->checkAccess('write', '', $ref)) {
            // The evaluation and the learners' history open from the coach,
            // as panels sliding in from the right.
            $this->tabs->addTab('materials', $this->txt('tab_materials'), $this->ctrl->getLinkTarget($this, 'showMaterials'));
            $this->tabs->addTab('settings', $this->txt('tab_settings'), $this->ctrl->getLinkTarget($this, 'editSettings'));
        }

        if (class_exists('ilLearningProgressAccess') && ilLearningProgressAccess::checkAccess($ref)) {
            $this->tabs->addTab(
                'learning_progress',
                $this->lng->txt('learning_progress'),
                $this->ctrl->getLinkTargetByClass([get_class($this), 'ilLearningProgressGUI'], '')
            );
        }

        $this->addPermissionTab();
    }

    public function addInfoItems(ilInfoScreenGUI $info): void
    {
        $settings = $this->tarsus()->getSettings();
        $info->addSection($this->txt('info_section'));
        $modes = [];
        foreach ($settings->availableModes() as $mode) {
            $modes[] = $this->txt('mode_' . $mode);
        }
        $info->addProperty($this->txt('info_modes'), $modes === [] ? $this->txt('info_no_modes') : implode(', ', $modes));
        $info->addProperty($this->txt('info_dqr'), $this->txt('dqr_level' . $settings->dqr));
        if ($settings->completionprogress > 0) {
            $info->addProperty($this->txt('completionprogress'), $settings->completionprogress . ' %');
        }
        if ($this->checkPermissionBool('write')) {
            $info->addProperty(
                $this->txt('info_included'),
                (string) count(Scope::allowedResourceIds($this->object->getId(), $this->tarsus()->getContainerRefId()))
            );
        }
    }

    // ------------------------------------------------------------------
    // Assets
    // ------------------------------------------------------------------

    /**
     * @param bool $texts Whether the page shows Tarsus's texts: Markdown
     *                    with KaTeX formulas, rendered like in the Tarsus app
     *                    by the bundled renderer and KaTeX's stylesheet.
     */
    private function includeAssets(bool $texts = false): void
    {
        if ($texts) {
            $this->tpl->addCss(Ilias::assetUrl('vendor/katex/katex.min.css'));
        }
        $this->tpl->addCss(Ilias::assetUrl('css/tarsus.css'));
        // A relative path starting with ./ keeps ILIAS from dropping a URL
        // with a query string, and the plugin version busts the cache.
        if ($texts) {
            $this->tpl->addJavaScript('./' . Ilias::assetUrl('js/vendor/tarsus-markdown.min.js'), false);
        }
        $this->tpl->addJavaScript('./' . Ilias::assetUrl('js/dist/tarsus.js'), false);
        $this->tpl->addOnLoadCode('if (window.TarsusIlias) { window.TarsusIlias.boot(); }');
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(array $commands): array
    {
        $endpoints = [];
        foreach ($commands as $key => $cmd) {
            $endpoints[$key] = $this->ctrl->getFormAction($this, $cmd, '', true);
        }
        return $endpoints;
    }

    /**
     * @return array<string, string>
     */
    private function strings(array $keys): array
    {
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = $this->txt($key);
        }
        return $strings;
    }

    // ------------------------------------------------------------------
    // Content (the learner's chat)
    // ------------------------------------------------------------------

    private function showContent(): void
    {
        $this->tabs->activateTab('content');
        $object = $this->tarsus();
        $settings = $object->getSettings();

        if (!$object->isOnline()) {
            $this->tpl->setOnScreenMessage('info', $this->txt('offline_notice'));
        }

        try {
            ilChangeEvent::_recordReadEvent($object->getType(), $object->getRefId(), $object->getId(), (int) $this->user->getId());
        } catch (Throwable $e) {
            // Statistics only.
        }

        $this->budgetBanner();
        $modes = $settings->availableModes();
        if ($modes === []) {
            $this->tpl->setOnScreenMessage('info', $this->txt($settings->modes() === [] ? 'no_modes_notice' : 'no_modes_plan_notice'));
            $this->tpl->setContent('');
            return;
        }

        $scope = Scope::allowedResourceIds($object->getId(), $object->getContainerRefId());
        if ($scope === [] && $this->checkPermissionBool('write')) {
            $this->tpl->setOnScreenMessage('info', sprintf(
                $this->txt('noscope_teacher'),
                $this->ctrl->getLinkTarget($this, 'showMaterials')
            ));
        }

        $this->includeAssets(true);

        $robotavatar = Config::robotAvatar();
        if ($robotavatar === '') {
            $robotavatar = Ilias::assetUrl('images/robot.svg');
        }
        $useravatar = '';
        try {
            $useravatar = ilObjUser::_getPersonalPicturePath((int) $this->user->getId(), 'small');
        } catch (Throwable $e) {
            $useravatar = '';
        }

        $teacher = $this->checkPermissionBool('write');
        $config = [
            'kind' => 'chat',
            'endpoints' => $this->endpoints([
                'feed' => 'ajaxFeed',
                'ask' => 'ajaxAsk',
                'askVoice' => 'ajaxAskVoice',
                'practiceNext' => 'ajaxPracticeNext',
                'practiceCheck' => 'ajaxPracticeCheck',
                'learnNext' => 'ajaxLearnNext',
                'audio' => 'ajaxAudio',
                'discuss' => 'ajaxDiscuss',
                'references' => 'ajaxReferences',
            ]),
            // The teacher's panels: settings, evaluation, history.
            'teacher' => $teacher ? [
                'endpoints' => $this->endpoints([
                    'stats' => 'ajaxStats',
                    'history' => 'ajaxHistory',
                    'analytics' => 'ajaxAnalytics',
                    'settings' => 'ajaxSettings',
                    'saveSettings' => 'ajaxSaveSettings',
                ]),
                'exportUrl' => $this->ctrl->getLinkTarget($this, 'exportLearners'),
                'settingsUrl' => $this->ctrl->getLinkTarget($this, 'editSettings'),
            ] : null,
            'modes' => $modes,
            // Narration of learning units is part of Build Knowledge and
            // Pay as you grow.
            'audio' => Plan::allowsAudio(),
            // Spoken questions in Ask a question (package and switch).
            'voice' => $settings->voiceInputActive(),
            'voiceMaxSeconds' => self::VOICE_MAX_SECONDS,
            'voiceMaxBytes' => Ilias::uploadLimit(),
            // "View sources" under the answers.
            'references' => $settings->referencesenabled,
            // The result in percent: the experience points' share while
            // Test knowledge is active, the learning units' otherwise.
            'gradeBasis' => $settings->modeActive('test') ? 'xp' : 'units',
            'interactive' => $settings->effectiveInteraction() === Plan::INTERACTION_AI,
            'pageSize' => LearnerApi::FEED_PAGE_SIZE,
            'userAvatar' => $useravatar,
            'robotAvatar' => $robotavatar,
            'robotName' => Config::robotName(),
            'demo' => Config::mockMode(),
            'images' => [
                'welcome' => Ilias::assetUrl('images/welcome.png'),
                'objectives' => Ilias::assetUrl('images/objectives.png'),
                'motivation' => Ilias::assetUrl('images/motivation.png'),
                'success' => Ilias::assetUrl('images/success.png'),
            ],
            'icons' => Icons::PATHS,
            'strings' => $this->strings(array_merge(self::CHAT_STRINGS, $teacher ? self::TEACHER_STRINGS : [])),
        ];

        $this->tpl->setContent($this->resetChatButton() . Views::chat('tarsus-chat-' . $object->getId(), $config, Views::help(Help::links(), 'tarsus-help-chat')));
    }

    /**
     * "Reset chat history": an ILIAS button that opens ILIAS's
     * confirmation dialog, which sends the reset.
     */
    private function resetChatButton(): string
    {
        $ui = Ilias::dic()->ui();
        $modal = $ui->factory()->modal()->interruptive(
            $this->txt('resetchat'),
            sprintf($this->txt('resetchat_confirm'), Html::e($this->object->getTitle())),
            $this->ctrl->getFormAction($this, 'resetChat')
        )->withActionButtonLabel($this->txt('resetchat'));
        $button = $ui->factory()->button()->standard($this->txt('resetchat'), '')->withOnClick($modal->getShowSignal());
        return '<div class="tarsus-reset-row">' . $ui->renderer()->render($button) . '</div>' . $ui->renderer()->render($modal);
    }

    /**
     * Tell teachers how much of the month's AI credits is used, and
     * learners when the month's credits are spent.
     */
    private function budgetBanner(): void
    {
        if ($this->checkPermissionBool('write')) {
            $banner = Budget::banner();
            if ($banner !== null) {
                $this->tpl->setOnScreenMessage($banner['type'], $banner['text']);
            }
            return;
        }
        if (Budget::exhausted()) {
            $this->tpl->setOnScreenMessage('info', $this->txt('budget_exhausted_learner'));
        }
    }

    /**
     * The help button for the teacher's screens; '' when no links are configured.
     */
    private function helpHtml(): string
    {
        $help = Views::help(Help::links(), 'tarsus-help-' . $this->object->getId());
        return $help === '' ? '' : '<div class="tarsus-app tarsus-help-row">' . $help . '</div>';
    }

    private function resetChat(): void
    {
        $result = $this->learnerApi()->reset();
        if (($result['status'] ?? '') === 'success') {
            $this->tpl->setOnScreenMessage('success', $this->txt('resetchat_done'), true);
        } else {
            $this->tpl->setOnScreenMessage('failure', $this->txt('resetchat_failed') . ' ' . (string) ($result['error'] ?? ''), true);
        }
        $this->ctrl->redirect($this, 'showContent');
    }

    private function learnerApi(): LearnerApi
    {
        $object = $this->tarsus();
        return new LearnerApi(
            $object->getId(),
            (string) $object->getTitle(),
            (string) $object->getDescription(),
            $object->getSettings(),
            $object->getContainerRefId()
        );
    }

    // ------------------------------------------------------------------
    // AJAX: learner
    // ------------------------------------------------------------------

    private function ajaxFeed(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->learnerApi()->feed((int) ($payload['offset'] ?? 0)));
    }

    private function ajaxAsk(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->learnerApi()->ask(
            (string) ($payload['query'] ?? ''),
            (string) ($payload['replyto'] ?? ''),
            (string) ($payload['replystack'] ?? '')
        ));
    }

    /**
     * A spoken question: the recording arrives as the uploaded file
     * "audio", the reply target in the form field "payload".
     */
    private function ajaxAskVoice(): void
    {
        $payload = Ilias::jsonPayload();
        // Larger than PHP accepts: the recording never arrived.
        $file = Ilias::request()->getUploadedFiles()['audio'] ?? null;
        $error = $file instanceof UploadedFileInterface ? $file->getError() : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            Ilias::sendJson(['status' => 'error', 'error' => $this->txt('err_toolarge')]);
        }
        // ILIAS's upload service applies the installation's rules (allowed
        // file types, virus scanner) before the recording goes on.
        $upload = Ilias::dic()->upload();
        if (!$upload->hasBeenProcessed()) {
            $upload->process();
        }
        $recording = null;
        foreach ($upload->getResults() as $result) {
            if ($result->isOK() && $result->getPath() !== '') {
                $recording = $result;
                break;
            }
        }
        if ($recording === null) {
            // Arrived complete but refused: the installation's rules; else unreadable.
            Ilias::sendJson(['status' => 'error', 'error' => $this->txt($error === UPLOAD_ERR_OK ? 'err_audio_refused' : 'err_audio_format')]);
            return;
        }
        // The browser's type without its parameters (audio/webm;codecs=opus).
        $mimetype = strtolower(trim(explode(';', $recording->getMimeType())[0]));
        if (strncmp($mimetype, 'audio/', 6) !== 0 && strncmp($mimetype, 'video/', 6) !== 0) {
            $mimetype = 'application/octet-stream';
        }
        $name = basename($recording->getName());
        Ilias::sendJson($this->learnerApi()->askVoice(
            $recording->getPath(),
            $mimetype,
            $name !== '' ? $name : 'question.webm',
            (string) ($payload['replyto'] ?? ''),
            (string) ($payload['replystack'] ?? '')
        ));
    }

    private function ajaxDiscuss(): void
    {
        Ilias::sendJson($this->learnerApi()->discuss());
    }

    private function ajaxReferences(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->learnerApi()->references(
            (string) ($payload['widgetid'] ?? ''),
            (string) ($payload['state'] ?? 'ask')
        ));
    }

    private function ajaxPracticeNext(): void
    {
        Ilias::sendJson($this->learnerApi()->practiceNext());
    }

    private function ajaxPracticeCheck(): void
    {
        Ilias::sendJson($this->learnerApi()->practiceCheck(Ilias::jsonPayload()));
    }

    private function ajaxLearnNext(): void
    {
        Ilias::sendJson($this->learnerApi()->learnNext());
    }

    private function ajaxAudio(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->learnerApi()->stackAudio((string) ($payload['stackid'] ?? '')));
    }

    // ------------------------------------------------------------------
    // Materials (the teacher's list)
    // ------------------------------------------------------------------

    private function showMaterials(): void
    {
        $this->tabs->activateTab('materials');
        $this->budgetBanner();
        $object = $this->tarsus();
        $containerref = $object->getContainerRefId();

        $resolver = new Resolver();
        $repo = new Repository();
        $materials = $resolver->forContainer($containerref);

        // What the course holds now against what the brain holds: vanished
        // material is withdrawn, changed material flagged. The brain is
        // not called for uploads here; the page's sync does the reads.
        try {
            (new Reconciler($repo, $resolver))->reconcileContainer($containerref, 0, true, $materials);
        } catch (Throwable $e) {
            Ilias::log('reconcile failed: ' . $e->getMessage());
        }

        $rows = $repo->getByContainer($containerref);
        $included = array_flip(Inclusions::forObject($object->getId()));

        $entries = [];
        $seen = [];
        foreach ($materials as $material) {
            $seen[$material->resourceid] = true;
            $row = $rows[$material->resourceid] ?? null;
            $status = $row->status ?? Status::NOT_INDEXED;
            $entries[] = Presenter::present($material->resourceid, $status, (int) ($row->timemodified ?? 0)) + [
                'included' => isset($included[$material->resourceid]),
                'title' => $material->title,
                'objecttitle' => $material->objecttitle,
                'typelabel' => $this->typeLabel($material->srctype, $material->bittype),
                'url' => $this->materialUrl($material->srcref, $material->srctype),
            ];
        }
        // Rows the brain still holds for material that is gone stay listed
        // until the deletion is delivered.
        foreach ($rows as $resourceid => $row) {
            if (isset($seen[$resourceid]) || $row->status === Status::NOT_INDEXED) {
                continue;
            }
            $entries[] = Presenter::present($resourceid, $row->status, (int) $row->timemodified) + [
                'included' => false,
                'title' => $this->txt('material_gone'),
                'objecttitle' => $this->txt('material_gone'),
                'typelabel' => $this->typeLabel($row->srctype, ''),
                'url' => '',
            ];
        }

        $counts = ['total' => count($entries), 'indexed' => 0, 'included' => 0];
        foreach ($entries as $entry) {
            if ($entry['status'] === Status::INDEXED) {
                $counts['indexed']++;
                if ($entry['included']) {
                    $counts['included']++;
                }
            }
        }

        $pages = max(1, (int) ceil(count($entries) / self::PAGE_SIZE));
        $page = 0;
        if ($this->request_wrapper->has('xtar_page')) {
            $page = max(0, min($pages - 1, $this->request_wrapper->retrieve('xtar_page', $this->refinery->kindlyTo()->int())));
        }
        $pageentries = array_slice($entries, $page * self::PAGE_SIZE, self::PAGE_SIZE);
        $pager = Views::pager($page, $pages, function (int $target): string {
            $this->ctrl->setParameter($this, 'xtar_page', $target);
            $url = $this->ctrl->getLinkTarget($this, 'showMaterials');
            $this->ctrl->clearParameterByClass(get_class($this), 'xtar_page');
            return $url;
        });

        $this->includeAssets();
        $config = [
            'kind' => 'materials',
            'endpoints' => $this->endpoints([
                'action' => 'ajaxAction',
                'include' => 'ajaxInclude',
                'sync' => 'ajaxSync',
            ]),
            'batchLimit' => ClientInterface::BATCH_LIMIT,
            'strings' => $this->strings([
                'confirmremove_body', 'dialog_cancel', 'unexpected_error', 'session_lost', 'sync_done', 'bulk_done',
                'include_not_indexed', 'action_index', 'action_reindex', 'action_remove', 'action_retry',
            ]),
        ];
        $this->tpl->setContent(Views::materials('tarsus-materials-' . $object->getId(), $pageentries, $pager, $config, $counts));
    }

    private function typeLabel(string $srctype, string $bittype): string
    {
        $key = 'obj_' . $srctype;
        $label = $this->lng->txt($key);
        if ($label === '' || $label[0] === '-') {
            $label = $srctype;
        }
        if ($bittype !== '' && $this->plugin !== null && Ilias::hasTxt('bittype_' . $bittype)) {
            $label .= ' · ' . $this->txt('bittype_' . $bittype);
        }
        return $label;
    }

    private function materialUrl(int $refid, string $type): string
    {
        if ($refid <= 0 || !Ilias::refAlive($refid)) {
            return '';
        }
        try {
            return ilLink::_getLink($refid, $type);
        } catch (Throwable $e) {
            return '';
        }
    }

    private function managerApi(): ManagerApi
    {
        return new ManagerApi($this->object->getId(), $this->tarsus()->getContainerRefId());
    }

    private function teacherApi(): TeacherApi
    {
        $object = $this->tarsus();
        return new TeacherApi($object->getId(), $object->getContainerRefId(), $object->getSettings());
    }

    // ------------------------------------------------------------------
    // AJAX: the teacher's panels (evaluation, history, settings)
    // ------------------------------------------------------------------

    private function ajaxStats(): void
    {
        Ilias::sendJson($this->teacherApi()->statistics());
    }

    private function ajaxHistory(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->teacherApi()->history((int) ($payload['userid'] ?? 0), (int) ($payload['offset'] ?? 0)));
    }

    private function ajaxAnalytics(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->teacherApi()->analytics((int) ($payload['userid'] ?? 0)));
    }

    private function ajaxSettings(): void
    {
        Ilias::sendJson(['status' => 'success', 'error' => ''] + (new SettingsApi($this->tarsus()->getSettings()))->describe());
    }

    private function ajaxSaveSettings(): void
    {
        $payload = Ilias::jsonPayload();
        $settings = $this->tarsus()->getSettings();
        $settings->objid = $this->object->getId();
        Ilias::sendJson((new SettingsApi($settings))->save((array) ($payload['values'] ?? [])));
    }

    // ------------------------------------------------------------------
    // AJAX: teacher
    // ------------------------------------------------------------------

    private function ajaxAction(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->managerApi()->action(
            (string) ($payload['resourceid'] ?? ''),
            (string) ($payload['action'] ?? '')
        ));
    }

    private function ajaxInclude(): void
    {
        $payload = Ilias::jsonPayload();
        Ilias::sendJson($this->managerApi()->setIncluded(
            (string) ($payload['resourceid'] ?? ''),
            !empty($payload['included'])
        ));
    }

    private function ajaxSync(): void
    {
        $payload = Ilias::jsonPayload();
        $ids = array_values(array_filter(array_map('strval', (array) ($payload['resourceids'] ?? [])), 'strlen'));
        Ilias::sendJson($this->managerApi()->sync($ids));
    }

    // ------------------------------------------------------------------
    // Learners (CSV export of the evaluation)
    // ------------------------------------------------------------------

    private function exportLearners(): void
    {
        $object = $this->tarsus();
        $settings = $object->getSettings();
        $overview = (new Learners($object->getId(), $object->getContainerRefId(), $settings))->overview();

        $lines = [];
        $lines[] = [
            $this->lng->txt('lastname'), $this->lng->txt('firstname'), $this->lng->txt('login'),
            $this->txt('col_progress'), $this->txt('xp'), $this->txt('xp') . ' ' . $this->txt('total'),
            $this->txt('learning_units'), $this->txt('learning_units') . ' ' . $this->txt('total'),
            $this->txt('col_score'), $this->txt('col_lp_status'), $this->txt('col_last_activity'),
        ];
        foreach ($overview['learners'] as $learner) {
            $status = $learner['status'] === Scoring::LP_COMPLETED ? 'lp_completed'
                : ($learner['status'] === Scoring::LP_IN_PROGRESS ? 'lp_in_progress' : 'lp_not_attempted');
            $lines[] = [
                $learner['lastname'], $learner['firstname'], $learner['login'],
                number_format((float) $learner['progress'], 2, '.', ''),
                number_format((float) $learner['earnedxp'], 2, '.', ''), number_format((float) $learner['totalxp'], 2, '.', ''),
                number_format((float) $learner['earnedunits'], 2, '.', ''), number_format((float) $learner['totalunits'], 2, '.', ''),
                $learner['grade'] === null ? '' : number_format((float) $learner['grade'], 2, '.', ''),
                $this->txt($status),
                $learner['lastactivity'] > 0 ? date('Y-m-d H:i', (int) $learner['lastactivity']) : '',
            ];
        }

        $csv = "\xEF\xBB\xBF";
        foreach ($lines as $line) {
            $csv .= implode(';', array_map(static function ($value): string {
                $value = (string) $value;
                return '"' . str_replace('"', '""', $value) . '"';
            }, $line)) . "\r\n";
        }

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $object->getTitle());
        Ilias::sendDownload('tarsus_' . trim((string) $name, '_') . '_' . date('Ymd') . '.csv', $csv, 'text/csv; charset=utf-8');
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private function editSettings(?StandardForm $form = null): void
    {
        $this->tabs->activateTab('settings');
        $this->budgetBanner();
        $this->includeAssets();
        $this->tpl->setContent($this->helpHtml() . Ilias::ui()->renderer()->render($form ?? $this->settingsForm()));
    }

    /**
     * The object's settings, filled with the stored values: title,
     * description and online state, then what the coach offers and how it
     * teaches. Modes and features outside the booked package are shown,
     * explained and locked.
     */
    private function settingsForm(): StandardForm
    {
        $field = Ilias::ui()->factory()->input()->field();
        $settings = $this->tarsus()->getSettings();

        $general = [
            'title' => $field->text($this->lng->txt('title'))
                ->withMaxLength(ilObject::TITLE_LENGTH)
                ->withRequired(true)
                ->withValue((string) $this->object->getTitle()),
            'description' => $field->textarea($this->lng->txt('description'), $this->txt('description_info'))
                ->withValue((string) $this->object->getDescription()),
            'online' => $field->checkbox($this->lng->txt('online'), $this->txt('online_info'))->withValue($settings->online),
        ];

        // Modes.
        $modes = [];
        foreach (self::MODES as $mode) {
            $byline = $this->txt($mode . 'enabled_help');
            if (!Plan::allowsMode($mode)) {
                // Not part of the customer's plan: shown, explained, locked.
                $byline .= ' <strong>' . Ilias::txt('plan_mode_unavailable', ['plan' => Plan::label()]) . '</strong>';
            }
            $modes[$mode . 'enabled'] = $field->checkbox($this->txt($mode . 'enabled'), $byline)
                ->withValue($settings->modeEnabled($mode))
                ->withDisabled(!Plan::allowsMode($mode));
        }

        // Interaction.
        if (Plan::allowsAiDriven()) {
            $interaction = $field->radio($this->txt('interactiontype'))
                ->withOption(Plan::INTERACTION_TRAINER, $this->txt('interaction_trainer'), $this->txt('interaction_trainer_help'))
                ->withOption(Plan::INTERACTION_AI, $this->txt('interaction_ai'), $this->txt('interaction_ai_help'))
                ->withValue(self::option($settings->interactiontype, [Plan::INTERACTION_TRAINER => '', Plan::INTERACTION_AI => '']));
        } else {
            // AI-driven pages are not part of the package: the trainer drives.
            $interaction = $field->radio(
                $this->txt('interactiontype'),
                $this->txt('interaction_ai') . ': ' . $this->txt('interaction_ai_help')
                    . ' <strong>' . Ilias::txt('plan_interaction_unavailable', ['plan' => Plan::label()]) . '</strong>'
            )
                ->withOption(Plan::INTERACTION_TRAINER, $this->txt('interaction_trainer'), $this->txt('interaction_trainer_help'))
                ->withValue(Plan::INTERACTION_TRAINER);
        }
        $voicebyline = $this->txt('voiceinput_help');
        if (!Plan::allowsVoiceInput()) {
            $voicebyline .= ' <strong>' . Ilias::txt('plan_feature_locked', ['plan' => Plan::label()]) . '</strong>';
        }
        // Thinking level: how thoroughly the coach thinks (replaces "think longer").
        $thinking = $field->radio($this->txt('thinkinglevel'), $this->txt('thinkinglevel_help'));
        foreach ([1 => 'fast', 2 => 'slow', 3 => 'deep', 4 => 'intense'] as $level => $key) {
            $thinking = $thinking->withOption((string) $level, $this->txt('thinking_' . $key), $this->txt('thinking_' . $key . '_help'));
        }
        $interactionsection = [
            'interactiontype' => $interaction,
            'voiceinput' => $field->checkbox($this->txt('voiceinput'), $voicebyline)
                ->withValue($settings->voiceinput)
                ->withDisabled(!Plan::allowsVoiceInput()),
            'referencesenabled' => $field->checkbox($this->txt('referencesenabled'), $this->txt('referencesenabled_help'))
                ->withValue($settings->referencesenabled),
            'visualaccessibility' => $field->checkbox($this->txt('visualaccessibility'), $this->txt('visualaccessibility_help'))
                ->withValue($settings->visualaccessibility),
            'thinkinglevel' => $thinking->withValue(self::option($settings->thinkinglevel, [1 => '', 2 => '', 3 => '', 4 => ''])),
        ];

        // General didactics.
        $levels = [];
        foreach (Didactics::DQR_LEVELS as $level) {
            $levels[$level] = $level . ' – ' . $this->txt('dqr_level' . $level);
        }
        $didactics = [
            'dqr' => $field->select($this->txt('dqr'), $levels, $this->txt('dqr_help'))
                ->withRequired(true)
                ->withValue(self::option($settings->dqr, $levels)),
            'industry' => $field->text($this->txt('industry'), $this->txt('industry_help'))
                ->withMaxLength(255)
                ->withValue($settings->industry),
            'anticheat' => $field->checkbox($this->txt('anticheat'), $this->txt('anticheat_help'))->withValue($settings->anticheat),
        ];

        // Ask a question.
        $styles = [];
        foreach (['closed', 'lexical', 'tutorial', 'socratic', 'open'] as $i => $key) {
            $styles[$i + 1] = $this->txt('style_' . $key);
        }
        $ask = [
            'conversationstyle' => $field->select($this->txt('conversationstyle'), $styles, $this->txt('conversationstyle_help'))
                ->withRequired(true)
                ->withValue(self::option($settings->conversationstyle, $styles)),
        ];

        // Test knowledge.
        $taxonomy = [];
        foreach ([1 => 'taxonomy_knowledge', 2 => 'taxonomy_understanding', 3 => 'taxonomy_application'] as $level => $key) {
            $taxonomy[(string) $level] = $this->txt($key);
        }
        $test = [
            'taxonomylevels' => $field->multiSelect($this->txt('taxonomylevels'), $taxonomy, $this->txt('taxonomylevels_help'))
                ->withValue(self::options($settings->taxonomylevels, $taxonomy))
                ->withAdditionalTransformation(Ilias::check(
                    static fn($value): bool => is_array($value) && $value !== [],
                    $this->txt('error_taxonomyrequired')
                )),
        ];
        $types = [];
        foreach (Didactics::TYPES as $type) {
            $types[$type] = $this->txt(Didactics::typeLabelKey($type));
        }
        foreach ([1 => 'difficulty_easy', 2 => 'difficulty_medium', 3 => 'difficulty_hard'] as $grade => $gradekey) {
            $test['qtypes' . $grade] = $field->multiSelect(
                str_replace('{grade}', $this->txt($gradekey), $this->txt('questiontypes_grade')),
                $types,
                $grade === 1 ? $this->txt('questiontypes_help') : null
            )
                ->withValue(self::options($settings->questiontypes[$grade] ?? [], $types))
                ->withAdditionalTransformation(Ilias::check(
                    static fn($value): bool => is_array($value) && $value !== [],
                    $this->txt('error_questiontypesrequired')
                ));
        }
        $test['operators'] = $field->radio($this->txt('operators'), $this->txt('operators_help'))
            ->withOption(Didactics::OPERATORS_STANDARD, $this->txt('operators_standard'))
            ->withOption(Didactics::OPERATORS_IHK, $this->txt('operators_ihk'))
            ->withValue(self::option($settings->operators, [Didactics::OPERATORS_STANDARD => '', Didactics::OPERATORS_IHK => '']));
        $tempos = [];
        foreach (Didactics::LEARN_TEMPOS as $value) {
            $label = Didactics::tempoLabel($value);
            $tempos[(string) $value] = $value === Didactics::LEARN_TEMPO_DEFAULT
                ? str_replace('{label}', $label, $this->txt('learntempo_recommended')) : $label;
        }
        $test['learntempo'] = $field->select($this->txt('learntempo'), $tempos, $this->txt('learntempo_help'))
            ->withRequired(true)
            ->withValue(self::option($settings->learntempo, $tempos));

        // Build knowledge.
        $methods = [];
        foreach (Didactics::LEARNING_METHODS as $value) {
            $methods[$value] = $this->txt('learningmethod_' . str_replace('-', '', $value));
        }
        $build = [
            'learningmethod' => $field->select($this->txt('learningmethod'), $methods, $this->txt('learningmethod_help'))
                ->withRequired(true)
                ->withValue(self::option($settings->learningmethod, $methods)),
            'gifenabled' => $field->checkbox($this->txt('gifenabled'), $this->txt('gifenabled_help'))->withValue($settings->gifenabled),
        ];

        // Learning progress.
        $progress = [
            'completionprogress' => $field->numeric($this->txt('completionprogress') . ' (%)', $this->txt('completionprogress_help'))
                ->withValue($settings->completionprogress)
                ->withAdditionalTransformation(Ilias::check(
                    static fn($value): bool => is_numeric($value) && (int) $value >= 0 && (int) $value <= 100,
                    $this->txt('error_completion')
                )),
        ];

        return Ilias::ui()->factory()->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'updateSettings'),
            [
                'general' => $field->section($general, $this->txt('settings_title')),
                'modes' => $field->section($modes, $this->txt('settings_modes'))
                    ->withAdditionalTransformation(Ilias::check(
                        static function ($values): bool {
                            foreach (self::MODES as $mode) {
                                if (!empty($values[$mode . 'enabled']) && Plan::allowsMode($mode)) {
                                    return true;
                                }
                            }
                            return false;
                        },
                        $this->txt('error_modesrequired')
                    )),
                'interaction' => $field->section($interactionsection, $this->txt('settings_interaction'), $this->txt('settings_interaction_desc')),
                'didactics' => $field->section($didactics, $this->txt('settings_general')),
                'ask' => $field->section($ask, $this->txt('settings_ask')),
                'test' => $field->section($test, $this->txt('settings_test')),
                'build' => $field->section($build, $this->txt('settings_build')),
                'progress' => $field->section($progress, $this->lng->txt('learning_progress')),
            ]
        )->withSubmitLabel($this->lng->txt('save'));
    }

    /**
     * A stored value as a field may show it: one of its options, else
     * none (the UI framework refuses values it does not offer).
     *
     * @param mixed $value
     * @param array<int|string, string> $options
     */
    private static function option($value, array $options): ?string
    {
        $value = (string) $value;
        return array_key_exists($value, $options) ? $value : null;
    }

    /**
     * The stored values a multiple choice offers.
     *
     * @param array<int|string, mixed> $values
     * @param array<int|string, string> $options
     * @return string[]
     */
    private static function options(array $values, array $options): array
    {
        return array_values(array_filter(
            array_map('strval', $values),
            static fn(string $value): bool => array_key_exists($value, $options)
        ));
    }

    private function updateSettings(): void
    {
        $form = $this->settingsForm()->withRequest(Ilias::request());
        $data = $form->getData();
        if (!is_array($data)) {
            $this->editSettings($form);
            return;
        }
        $values = [];
        foreach ($data as $section) {
            $values += (array) $section;
        }

        // The same checks as the settings panel of the coach.
        $input = [];
        foreach ([
            'askenabled', 'testenabled', 'buildenabled', 'discussenabled', 'interactiontype', 'voiceinput',
            'referencesenabled', 'visualaccessibility', 'thinkinglevel', 'dqr', 'industry', 'anticheat',
            'conversationstyle', 'taxonomylevels', 'qtypes1', 'qtypes2', 'qtypes3', 'operators', 'learntempo',
            'learningmethod', 'gifenabled', 'completionprogress',
        ] as $name) {
            $input[$name] = $values[$name] ?? null;
        }
        foreach (['taxonomylevels', 'qtypes1', 'qtypes2', 'qtypes3'] as $name) {
            $input[$name] = (array) ($input[$name] ?? []);
        }

        $object = $this->tarsus();
        $settings = $object->getSettings();
        $errors = (new SettingsApi($settings))->apply($input);
        if ($errors !== []) {
            // The form checks the same; this is met when the package changed meanwhile.
            $this->tpl->setOnScreenMessage('failure', implode(' ', array_unique($errors)));
            $this->editSettings($form);
            return;
        }

        $object->setTitle((string) $values['title']);
        $object->setDescription((string) $values['description']);
        $settings->objid = $object->getId();
        $settings->online = !empty($values['online']);

        $object->update();

        // A changed threshold or mode changes every learner's status.
        Scoring::pushAll($object->getId());

        $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        $this->ctrl->redirect($this, 'editSettings');
    }
}
