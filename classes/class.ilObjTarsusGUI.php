<?php

/**
 * The screens of a Tarsus object: the learner's chat, the teacher's
 * material list, learner overview and settings, plus the AJAX endpoints the
 * chat and the material list talk to.
 *
 * @author  lern.link GmbH
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
use TarsusIlias\Output\MathSupport;
use TarsusIlias\Output\Presenter;
use TarsusIlias\Output\Views;
use TarsusIlias\Service\LearnerApi;
use TarsusIlias\Service\ManagerApi;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Ilias;

class ilObjTarsusGUI extends ilObjectPluginGUI
{
    /** Materials listed per page of the teacher's list (one brain call per page). */
    public const PAGE_SIZE = 10;

    /** @var string[] Commands that answer JSON and need read permission. */
    private const AJAX_READ = ['ajaxFeed', 'ajaxAsk', 'ajaxPracticeNext', 'ajaxPracticeCheck', 'ajaxLearnNext', 'ajaxAudio'];

    /** @var string[] Commands that answer JSON and need write permission. */
    private const AJAX_WRITE = ['ajaxAction', 'ajaxInclude', 'ajaxSync'];

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
            case 'confirmResetChat':
            case 'resetChat':
                $this->checkPermission('read');
                $this->$cmd();
                break;

            case 'showMaterials':
            case 'showLearners':
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
            $this->tabs->addTab('materials', $this->txt('tab_materials'), $this->ctrl->getLinkTarget($this, 'showMaterials'));
            $this->tabs->addTab('learners', $this->txt('tab_learners'), $this->ctrl->getLinkTarget($this, 'showLearners'));
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
        foreach ($settings->modes() as $mode) {
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

    private function includeAssets(): void
    {
        $this->tpl->addCss(Ilias::assetUrl('css/tarsus.css'));
        // A relative path starting with ./ keeps ILIAS from dropping a URL
        // with a query string, and the plugin version busts the cache.
        $this->tpl->addJavaScript('./' . Ilias::assetUrl('js/tarsus.js'), false);
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

        $modes = $settings->modes();
        if ($modes === []) {
            $this->tpl->setOnScreenMessage('info', $this->txt('no_modes_notice'));
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

        $this->includeAssets();
        MathSupport::includeOnPage();

        $toolbar = $this->toolbar;
        $toolbar->setFormAction($this->ctrl->getFormAction($this, 'confirmResetChat'));
        $toolbar->addFormButton($this->txt('resetchat'), 'confirmResetChat');

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

        $config = [
            'kind' => 'chat',
            'endpoints' => $this->endpoints([
                'feed' => 'ajaxFeed',
                'ask' => 'ajaxAsk',
                'practiceNext' => 'ajaxPracticeNext',
                'practiceCheck' => 'ajaxPracticeCheck',
                'learnNext' => 'ajaxLearnNext',
                'audio' => 'ajaxAudio',
            ]),
            'modes' => $modes,
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
            'strings' => $this->strings([
                'practice_start', 'practice_next', 'practice_check', 'practice_answer_placeholder',
                'practice_variable_placeholder', 'practice_table_position', 'practice_table_rate', 'practice_table_amount',
                'practice_score', 'xp', 'learning_units', 'learn_slide_of', 'learn_previous', 'learn_next',
                'learn_image_enlarge', 'learn_image_close', 'learn_audio', 'learn_audio_play', 'learn_audio_pause',
                'learn_ask_about', 'chat_reply_about', 'chat_reply_clear', 'learn_complete_title',
                'learn_motivation_title', 'mode_build', 'mode_test', 'mode_ask', 'unexpected_error', 'session_lost',
                'proc_ask_classify', 'proc_ask_search', 'proc_ask_media', 'proc_ask_select', 'proc_ask_cube',
                'proc_ask_dqr', 'proc_ask_compose', 'proc_ask_verify', 'proc_ask_polish',
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
            ]),
        ];

        $this->tpl->setContent(Views::chat('tarsus-chat-' . $object->getId(), $config));
    }

    private function confirmResetChat(): void
    {
        $this->tabs->activateTab('content');
        $confirm = new ilConfirmationGUI();
        $confirm->setFormAction($this->ctrl->getFormAction($this, 'resetChat'));
        $confirm->setHeaderText(sprintf($this->txt('resetchat_confirm'), Html::e($this->object->getTitle())));
        $confirm->setConfirm($this->txt('resetchat'), 'resetChat');
        $confirm->setCancel($this->lng->txt('cancel'), 'showContent');
        $this->tpl->setContent($confirm->getHTML());
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
            (string) ($payload['replyto'] ?? '')
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
                'confirmremove_body', 'unexpected_error', 'session_lost', 'sync_done', 'bulk_done',
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
    // Learners
    // ------------------------------------------------------------------

    private function showLearners(): void
    {
        $this->tabs->activateTab('learners');
        $object = $this->tarsus();
        $settings = $object->getSettings();
        $overview = (new Learners($object->getId(), $object->getContainerRefId(), $settings))->overview();

        $this->toolbar->setFormAction($this->ctrl->getFormAction($this, 'exportLearners'));
        $this->toolbar->addFormButton($this->txt('export_csv'), 'exportLearners');

        $this->includeAssets();
        $this->tpl->setContent(Views::learners($overview, $settings->testenabled, $settings->buildenabled, $settings->completionprogress));
    }

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

    private function editSettings(?ilPropertyFormGUI $form = null): void
    {
        $this->tabs->activateTab('settings');
        if ($form === null) {
            $form = $this->initSettingsForm();
            $this->fillSettingsForm($form);
        }
        $this->tpl->setContent($form->getHTML());
    }

    private function initSettingsForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'updateSettings'));
        $form->setTitle($this->txt('settings_title'));

        $title = new ilTextInputGUI($this->lng->txt('title'), 'title');
        $title->setRequired(true);
        $title->setMaxLength(ilObject::TITLE_LENGTH);
        $form->addItem($title);

        $description = new ilTextAreaInputGUI($this->lng->txt('description'), 'description');
        $description->setRows(3);
        $description->setInfo($this->txt('description_info'));
        $form->addItem($description);

        $online = new ilCheckboxInputGUI($this->lng->txt('online'), 'online');
        $online->setInfo($this->txt('online_info'));
        $form->addItem($online);

        // Modes.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_modes'));
        $form->addItem($section);
        foreach (['ask', 'test', 'build'] as $mode) {
            $box = new ilCheckboxInputGUI($this->txt($mode . 'enabled'), $mode . 'enabled');
            $box->setInfo($this->txt($mode . 'enabled_help'));
            $form->addItem($box);
        }

        // General didactics.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_general'));
        $form->addItem($section);

        $dqr = new ilSelectInputGUI($this->txt('dqr'), 'dqr');
        $levels = [];
        foreach (Didactics::DQR_LEVELS as $level) {
            $levels[$level] = $level . ' – ' . $this->txt('dqr_level' . $level);
        }
        $dqr->setOptions($levels);
        $dqr->setInfo($this->txt('dqr_help'));
        $form->addItem($dqr);

        $industry = new ilTextInputGUI($this->txt('industry'), 'industry');
        $industry->setMaxLength(255);
        $industry->setInfo($this->txt('industry_help'));
        $form->addItem($industry);

        $anticheat = new ilCheckboxInputGUI($this->txt('anticheat'), 'anticheat');
        $anticheat->setInfo($this->txt('anticheat_help'));
        $form->addItem($anticheat);

        // Ask a question.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_ask'));
        $form->addItem($section);

        $style = new ilSelectInputGUI($this->txt('conversationstyle'), 'conversationstyle');
        $styles = [];
        foreach (['closed', 'lexical', 'tutorial', 'socratic', 'open'] as $i => $key) {
            $styles[$i + 1] = $this->txt('style_' . $key);
        }
        $style->setOptions($styles);
        $style->setInfo($this->txt('conversationstyle_help'));
        $form->addItem($style);

        // Test knowledge.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_test'));
        $form->addItem($section);

        $taxonomy = new ilCheckboxGroupInputGUI($this->txt('taxonomylevels'), 'taxonomylevels');
        $taxonomy->setInfo($this->txt('taxonomylevels_help'));
        foreach ([1 => 'taxonomy_knowledge', 2 => 'taxonomy_understanding', 3 => 'taxonomy_application'] as $level => $key) {
            $taxonomy->addOption(new ilCheckboxOption($this->txt($key), (string) $level));
        }
        $form->addItem($taxonomy);

        $grades = [1 => 'difficulty_easy', 2 => 'difficulty_medium', 3 => 'difficulty_hard'];
        foreach ($grades as $grade => $gradekey) {
            $types = new ilCheckboxGroupInputGUI(str_replace('{grade}', $this->txt($gradekey), $this->txt('questiontypes_grade')), 'qtypes' . $grade);
            foreach (Didactics::TYPES as $type) {
                $types->addOption(new ilCheckboxOption($this->txt(Didactics::typeLabelKey($type)), $type));
            }
            if ($grade === 1) {
                $types->setInfo($this->txt('questiontypes_help'));
            }
            $form->addItem($types);
        }

        $operators = new ilRadioGroupInputGUI($this->txt('operators'), 'operators');
        $operators->addOption(new ilRadioOption($this->txt('operators_standard'), Didactics::OPERATORS_STANDARD));
        $operators->addOption(new ilRadioOption($this->txt('operators_ihk'), Didactics::OPERATORS_IHK));
        $operators->setInfo($this->txt('operators_help'));
        $form->addItem($operators);

        $tempo = new ilSelectInputGUI($this->txt('learntempo'), 'learntempo');
        $tempos = [];
        foreach (Didactics::LEARN_TEMPOS as $value) {
            $label = Didactics::tempoLabel($value);
            $tempos[(string) $value] = $value === Didactics::LEARN_TEMPO_DEFAULT
                ? str_replace('{label}', $label, $this->txt('learntempo_recommended')) : $label;
        }
        $tempo->setOptions($tempos);
        $tempo->setInfo($this->txt('learntempo_help'));
        $form->addItem($tempo);

        // Build knowledge.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_build'));
        $form->addItem($section);

        $method = new ilSelectInputGUI($this->txt('learningmethod'), 'learningmethod');
        $methods = [];
        foreach (Didactics::LEARNING_METHODS as $value) {
            $methods[$value] = $this->txt('learningmethod_' . str_replace('-', '', $value));
        }
        $method->setOptions($methods);
        $method->setInfo($this->txt('learningmethod_help'));
        $form->addItem($method);

        $gif = new ilCheckboxInputGUI($this->txt('gifenabled'), 'gifenabled');
        $gif->setInfo($this->txt('gifenabled_help'));
        $form->addItem($gif);

        // Learning progress.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->lng->txt('learning_progress'));
        $form->addItem($section);

        $completion = new ilNumberInputGUI($this->txt('completionprogress'), 'completionprogress');
        $completion->setMinValue(0);
        $completion->setMaxValue(100);
        $completion->setSize(4);
        $completion->setSuffix('%');
        $completion->allowDecimals(false);
        $completion->setInfo($this->txt('completionprogress_help'));
        $form->addItem($completion);

        $form->addCommandButton('updateSettings', $this->lng->txt('save'));
        return $form;
    }

    private function fillSettingsForm(ilPropertyFormGUI $form): void
    {
        $settings = $this->tarsus()->getSettings();
        $values = [
            'title' => $this->object->getTitle(),
            'description' => $this->object->getDescription(),
            'online' => $settings->online,
            'askenabled' => $settings->askenabled,
            'testenabled' => $settings->testenabled,
            'buildenabled' => $settings->buildenabled,
            'dqr' => $settings->dqr,
            'industry' => $settings->industry,
            'anticheat' => $settings->anticheat,
            'conversationstyle' => $settings->conversationstyle,
            'taxonomylevels' => array_map('strval', $settings->taxonomylevels),
            'operators' => $settings->operators,
            'learntempo' => (string) $settings->learntempo,
            'learningmethod' => $settings->learningmethod,
            'gifenabled' => $settings->gifenabled,
            'completionprogress' => $settings->completionprogress,
        ];
        foreach (Didactics::GRADES as $grade) {
            $values['qtypes' . $grade] = $settings->questiontypes[$grade] ?? [];
        }
        $form->setValuesByArray($values);
    }

    private function updateSettings(): void
    {
        $form = $this->initSettingsForm();
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->editSettings($form);
            return;
        }

        $errors = false;
        $modesOn = false;
        foreach (['ask', 'test', 'build'] as $mode) {
            if ($form->getInput($mode . 'enabled')) {
                $modesOn = true;
            }
        }
        if (!$modesOn) {
            $form->getItemByPostVar('askenabled')->setAlert($this->txt('error_modesrequired'));
            $errors = true;
        }
        if (Didactics::encodeTaxonomyLevels((array) $form->getInput('taxonomylevels')) === '') {
            $form->getItemByPostVar('taxonomylevels')->setAlert($this->txt('error_taxonomyrequired'));
            $errors = true;
        }
        $map = [];
        foreach (Didactics::GRADES as $grade) {
            $map[$grade] = array_values(array_intersect((array) $form->getInput('qtypes' . $grade), Didactics::TYPES));
            if ($map[$grade] === []) {
                $form->getItemByPostVar('qtypes' . $grade)->setAlert($this->txt('error_questiontypesrequired'));
                $errors = true;
            }
        }
        if ($errors) {
            $this->tpl->setOnScreenMessage('failure', $this->lng->txt('form_input_not_valid'));
            $form->setValuesByPost();
            $this->editSettings($form);
            return;
        }

        $object = $this->tarsus();
        $object->setTitle((string) $form->getInput('title'));
        $object->setDescription((string) $form->getInput('description'));

        $settings = $object->getSettings();
        $settings->objid = $object->getId();
        $settings->online = (bool) $form->getInput('online');
        $settings->askenabled = (bool) $form->getInput('askenabled');
        $settings->testenabled = (bool) $form->getInput('testenabled');
        $settings->buildenabled = (bool) $form->getInput('buildenabled');
        $settings->dqr = (int) $form->getInput('dqr');
        $settings->industry = (string) $form->getInput('industry');
        $settings->anticheat = (bool) $form->getInput('anticheat');
        $settings->conversationstyle = (int) $form->getInput('conversationstyle');
        $settings->taxonomylevels = Didactics::decodeTaxonomyLevels(Didactics::encodeTaxonomyLevels((array) $form->getInput('taxonomylevels')));
        $settings->questiontypes = $map;
        $settings->operators = Didactics::operators((string) $form->getInput('operators'));
        $settings->learntempo = Didactics::learnTempo($form->getInput('learntempo'));
        $settings->learningmethod = Didactics::learningMethod((string) $form->getInput('learningmethod'));
        $settings->gifenabled = (bool) $form->getInput('gifenabled');
        $settings->completionprogress = max(0, min(100, (int) $form->getInput('completionprogress')));

        $object->update();

        // A changed threshold or mode changes every learner's status.
        Scoring::pushAll($object->getId());

        $this->tpl->setOnScreenMessage('success', $this->lng->txt('msg_obj_modified'), true);
        $this->ctrl->redirect($this, 'editSettings');
    }
}
