<?php

/**
 * Plugin configuration (Administration > Extending ILIAS > Plugins > Tarsus
 * > Configure): the connection to the Tarsus brain, the assistant's
 * identity, the guardrails, the brain picker, outstanding deletions and
 * the live status.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 *
 * @ilCtrl_IsCalledBy ilTarsusConfigGUI: ilObjComponentSettingsGUI
 */

require_once __DIR__ . '/../src/autoload.php';

use TarsusIlias\Client\ClientFactory;
use TarsusIlias\Client\ConfigurationException;
use TarsusIlias\Client\TransportException;
use TarsusIlias\Guardrail\BlockedException;
use TarsusIlias\Guardrail\Pipeline;
use TarsusIlias\Index\Reconciler;
use TarsusIlias\Index\Repository;
use TarsusIlias\Index\Status;
use TarsusIlias\Output\Html;
use TarsusIlias\Output\Views;
use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Help;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;
use TarsusIlias\Support\UsageMeter;
use TarsusIlias\Support\Voices;

class ilTarsusConfigGUI extends ilPluginConfigGUI
{
    /** Largest avatar kept, in pixels (longest side). */
    private const AVATAR_SIZE = 160;

    /** Largest avatar upload accepted, in bytes. */
    private const AVATAR_MAX_BYTES = 2097152;

    private ilCtrlInterface $ctrl;
    private ilGlobalTemplateInterface $tpl;
    private ilTabsGUI $tabs;
    private ilLanguage $lng;

    public function __construct()
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC['tpl'];
        $this->tabs = $DIC->tabs();
        $this->lng = $DIC->language();
    }

    private function txt(string $key, array $params = []): string
    {
        return Ilias::txt($key, $params);
    }

    public function performCommand(string $cmd): void
    {
        if ($this->getPluginObject() !== null) {
            Ilias::setPlugin($this->getPluginObject());
        }
        $this->tabs->addSubTab('cfg_settings', $this->txt('cfg_tab_settings'), $this->ctrl->getLinkTarget($this, 'configure'));
        $this->tabs->addSubTab('cfg_plan', $this->txt('cfg_tab_plan'), $this->ctrl->getLinkTarget($this, 'showPlan'));
        $this->tabs->addSubTab('cfg_brains', $this->txt('cfg_tab_brains'), $this->ctrl->getLinkTarget($this, 'showBrains'));
        $this->tabs->addSubTab('cfg_deletions', $this->txt('cfg_tab_deletions'), $this->ctrl->getLinkTarget($this, 'showDeletions'));
        $this->tabs->addSubTab('cfg_status', $this->txt('cfg_tab_status'), $this->ctrl->getLinkTarget($this, 'showStatus'));
        $this->tpl->addCss(Ilias::assetUrl('css/tarsus.css'));

        $banner = Budget::banner();
        if ($banner !== null) {
            $this->tpl->setOnScreenMessage($banner['type'], $banner['text']);
        }

        switch ($cmd) {
            case 'saveSettings':
            case 'showPlan':
            case 'savePlan':
            case 'confirmPlan':
            case 'refreshUsage':
            case 'showBrains':
            case 'activateBrain':
            case 'showDeletions':
            case 'retryDeletions':
            case 'showStatus':
            case 'resetBreaker':
            case 'testConnection':
            case 'refreshVoices':
                $this->$cmd();
                break;
            case 'configure':
            default:
                $this->configure();
                break;
        }
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private function configure(?ilPropertyFormGUI $form = null): void
    {
        $this->tabs->activateSubTab('cfg_settings');
        if ($form === null) {
            $form = $this->initSettingsForm();
            $this->fillSettingsForm($form);
        }
        $this->tpl->setContent($form->getHTML());
    }

    private function initSettingsForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'saveSettings'));
        $form->setTitle($this->txt('cfg_title'));

        // Connection.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_connection'));
        $section->setInfo($this->txt('settings_connection_desc'));
        $form->addItem($section);

        $url = new ilTextInputGUI($this->txt('baseurl'), 'base_url');
        $url->setInfo($this->txt('baseurl_desc'));
        $url->setMaxLength(255);
        $form->addItem($url);

        $key = new ilPasswordInputGUI($this->txt('apikey'), 'api_key');
        $key->setRetype(false);
        $key->setSkipSyntaxCheck(true);
        $key->setMaxLength(255);
        $key->setInfo($this->txt('apikey_desc') . ' ' . $this->txt(Config::apiKey() !== '' ? 'apikey_keep' : 'apikey_none'));
        $form->addItem($key);

        $brain = new ilTextInputGUI($this->txt('brainid'), 'brain_id');
        $brain->setMaxLength(255);
        $brain->setInfo($this->txt('brainid_desc') . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'showBrains')) . '">'
            . $this->txt('cfg_tab_brains') . '</a>');
        $form->addItem($brain);

        // Assistant.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_assistant'));
        $section->setInfo($this->txt('settings_assistant_desc'));
        $form->addItem($section);

        $name = new ilTextInputGUI($this->txt('robotname'), 'robot_name');
        $name->setInfo($this->txt('robotname_desc'));
        $name->setMaxLength(100);
        $form->addItem($name);

        $id = new ilTextInputGUI($this->txt('robotid'), 'robot_id');
        $id->setInfo($this->txt('robotid_desc'));
        $id->setMaxLength(255);
        $form->addItem($id);

        $description = new ilTextAreaInputGUI($this->txt('robotdescription'), 'robot_description');
        $description->setInfo($this->txt('robotdescription_desc'));
        $description->setRows(3);
        $form->addItem($description);

        $avatar = new ilImageFileInputGUI($this->txt('robotavatar'), 'robot_avatar');
        $avatar->setInfo($this->txt('robotavatar_desc'));
        $avatar->setSuffixes(['png', 'jpg', 'jpeg', 'gif', 'webp']);
        $avatar->setAllowDeletion(true);
        $form->addItem($avatar);

        $mode = new ilRadioGroupInputGUI($this->txt('voice_mode'), 'voice_mode');
        $mode->setInfo($this->txt('voice_mode_desc'));
        $mode->addOption(new ilRadioOption($this->txt('voice_mode_auto'), Voices::MODE_AUTO));
        $mode->addOption(new ilRadioOption($this->txt('voice_mode_fixed'), Voices::MODE_FIXED));
        $form->addItem($mode);

        $voice = new ilSelectInputGUI($this->txt('voice'), 'voice');
        $voice->setInfo($this->txt('voice_desc') . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'refreshVoices')) . '">'
            . $this->txt('voice_refresh') . '</a>');
        $voice->setOptions($this->voiceOptions());
        $form->addItem($voice);

        // Guardrails.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_guardrails'));
        $section->setInfo($this->txt('settings_guardrails_desc'));
        $form->addItem($section);

        $mock = new ilCheckboxInputGUI($this->txt('mockmode'), 'mockmode');
        $mock->setInfo($this->txt('mockmode_desc'));
        $form->addItem($mock);

        $kill = new ilCheckboxInputGUI($this->txt('killswitch'), 'killswitch');
        $kill->setInfo($this->txt('killswitch_desc'));
        $form->addItem($kill);

        $rate = new ilNumberInputGUI($this->txt('ratelimit'), 'ratelimit');
        $rate->setInfo($this->txt('ratelimit_desc'));
        $rate->setMinValue(1);
        $rate->setSize(6);
        $rate->allowDecimals(false);
        $form->addItem($rate);

        $limitnote = new ilNonEditableValueGUI($this->txt('monthly_limit'), 'monthly_limit_note', true);
        $limitnote->setValue($this->txt('monthly_limit_elsewhere', ['limit' => Budget::hasLimit() ? number_format(Budget::limit()) : $this->txt('no_limit')])
            . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'showPlan')) . '">' . $this->txt('cfg_tab_plan') . '</a>');
        $form->addItem($limitnote);

        // Help.
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('settings_help'));
        $section->setInfo($this->txt('settings_help_desc'));
        $form->addItem($section);

        $help = new ilTextAreaInputGUI($this->txt('help_links'), Help::KEY);
        $help->setInfo($this->txt('help_links_desc'));
        $help->setRows(5);
        $form->addItem($help);

        $form->addCommandButton('saveSettings', $this->lng->txt('save'));
        return $form;
    }

    /**
     * @return array<string, string>
     */
    private function voiceOptions(): array
    {
        $options = [];
        try {
            $options = Voices::options();
        } catch (Throwable $e) {
            $options = [];
        }
        $current = trim((string) Config::get('voice', ''));
        if ($current !== '' && !isset($options[$current])) {
            $options = [$current => $current] + $options;
        }
        if ($options === []) {
            $options = [Voices::DEFAULT_VOICE => Voices::DEFAULT_VOICE];
        }
        return $options;
    }

    private function fillSettingsForm(ilPropertyFormGUI $form): void
    {
        $form->setValuesByArray([
            'base_url' => Config::baseUrl(),
            'api_key' => '',
            'brain_id' => Config::brainId(),
            'robot_name' => (string) Config::get('robot_name', Config::DEFAULT_ROBOT_NAME),
            'robot_id' => (string) Config::get('robot_id', ''),
            'robot_description' => (string) Config::get('robot_description', ''),
            'voice_mode' => (string) Config::get('voice_mode', Voices::MODE_AUTO),
            'voice' => trim((string) Config::get('voice', '')) !== '' ? (string) Config::get('voice') : Voices::DEFAULT_VOICE,
            'mockmode' => Config::mockMode(),
            'killswitch' => Config::killswitch(),
            'ratelimit' => Config::rateLimit(),
            Help::KEY => (string) Config::get(Help::KEY, ''),
        ]);
        /** @var ilImageFileInputGUI $avatar */
        $avatar = $form->getItemByPostVar('robot_avatar');
        $image = Config::robotAvatar();
        if ($image !== '') {
            $avatar->setImage($image);
        }
    }

    private function saveSettings(): void
    {
        $form = $this->initSettingsForm();
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->configure($form);
            return;
        }

        $url = rtrim(trim((string) $form->getInput('base_url')), '/');
        if ($url !== '' && !preg_match('~^https?://[^\s/]+~i', $url)) {
            $form->getItemByPostVar('base_url')->setAlert($this->txt('error_baseurl'));
            $form->setValuesByPost();
            $this->configure($form);
            return;
        }
        Config::set('base_url', $url);

        $apikey = trim((string) $form->getInput('api_key'));
        if ($apikey !== '') {
            Config::set('api_key', $apikey);
        }
        Config::set('brain_id', trim((string) $form->getInput('brain_id')));
        Config::set('robot_name', Config::bmp(trim((string) $form->getInput('robot_name'))));
        Config::set('robot_id', Config::bmp(trim((string) $form->getInput('robot_id'))));
        Config::set('robot_description', Config::bmp(trim((string) $form->getInput('robot_description'))));
        $mode = (string) $form->getInput('voice_mode');
        Config::set('voice_mode', $mode === Voices::MODE_FIXED ? Voices::MODE_FIXED : Voices::MODE_AUTO);
        Config::set('voice', Config::bmp(trim((string) $form->getInput('voice'))));
        Config::set('mockmode', $form->getInput('mockmode') ? '1' : '0');
        Config::set('killswitch', $form->getInput('killswitch') ? '1' : '0');
        Config::set('ratelimit', (string) max(1, (int) $form->getInput('ratelimit')));
        Config::set(Help::KEY, Config::bmp(trim((string) $form->getInput(Help::KEY))));

        /** @var ilImageFileInputGUI $avatar */
        $avatar = $form->getItemByPostVar('robot_avatar');
        if ($avatar->getDeletionFlag()) {
            Config::set('robot_avatar', '');
        } elseif (!empty($_FILES['robot_avatar']['tmp_name']) && is_uploaded_file($_FILES['robot_avatar']['tmp_name'])) {
            $uri = $this->avatarDataUri((string) $_FILES['robot_avatar']['tmp_name']);
            if ($uri === null) {
                $this->tpl->setOnScreenMessage('failure', $this->txt('error_avatar'), true);
            } else {
                Config::set('robot_avatar', $uri);
            }
        }

        $this->tpl->setOnScreenMessage('success', $this->lng->txt('settings_saved'), true);
        $this->ctrl->redirect($this, 'configure');
    }

    /**
     * The uploaded image, downscaled and encoded as a data URI; null when
     * it is no usable image.
     */
    private function avatarDataUri(string $path): ?string
    {
        if (!is_file($path) || filesize($path) > self::AVATAR_MAX_BYTES) {
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        $data = (string) file_get_contents($path);
        if (function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($data);
            if ($image !== false) {
                $width = imagesx($image);
                $height = imagesy($image);
                $scale = min(1, self::AVATAR_SIZE / max(1, max($width, $height)));
                $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                imagefill($target, 0, 0, $transparent);
                imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
                ob_start();
                imagepng($target, null, 8);
                $png = (string) ob_get_clean();
                imagedestroy($target);
                imagedestroy($image);
                if ($png !== '') {
                    return 'data:image/png;base64,' . base64_encode($png);
                }
            }
        }
        if (strlen($data) > 300000) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    private function refreshVoices(): void
    {
        try {
            $count = count(Voices::catalogue(true));
            $this->tpl->setOnScreenMessage('success', $this->txt('voices_refreshed', ['count' => $count]), true);
        } catch (Throwable $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('transport_error'), true);
        }
        $this->ctrl->redirect($this, 'configure');
    }

    // ------------------------------------------------------------------
    // Plan and credits
    // ------------------------------------------------------------------

    private function showPlan(?ilPropertyFormGUI $form = null): void
    {
        $this->tabs->activateSubTab('cfg_plan');
        if ($form === null) {
            $form = $this->initPlanForm();
            $form->setValuesByArray([
                'plan' => Plan::current(),
                'plan_credits' => Plan::isPayAsYouGrow() ? '' : (string) Plan::includedCredits(),
                'plan_price' => number_format(Plan::pricePerRequest(), 2, '.', ''),
                'plan_currency' => Plan::currency(),
                'monthly_limit' => (string) Budget::limit(),
                'notify_emails' => (string) Config::get('notify_emails', ''),
            ]);
        }
        $this->tpl->setContent('<div class="tarsus-app">' . $this->usagePanel() . '</div>' . $form->getHTML());
    }

    private function initPlanForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'savePlan'));
        $form->setTitle($this->txt('cfg_tab_plan'));

        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('plan_section'));
        $section->setInfo($this->txt('plan_section_desc'));
        $form->addItem($section);

        $plan = new ilRadioGroupInputGUI($this->txt('plan'), 'plan');
        foreach (Plan::ALL as $key) {
            $plan->addOption(new ilRadioOption($this->txt('plan_' . $key), $key, $this->txt('plan_' . $key . '_desc')));
        }
        $plan->setRequired(true);
        $form->addItem($plan);

        $credits = new ilNumberInputGUI($this->txt('plan_credits'), 'plan_credits');
        $credits->setInfo($this->txt('plan_credits_desc', ['default' => number_format(Plan::DEFAULT_CREDITS)]));
        $credits->setMinValue(0);
        $credits->setSize(10);
        $credits->allowDecimals(false);
        $form->addItem($credits);

        $price = new ilNumberInputGUI($this->txt('plan_price'), 'plan_price');
        $price->setInfo($this->txt('plan_price_desc', ['default' => number_format(Plan::DEFAULT_PRICE, 2)]));
        $price->setMinValue(0);
        $price->setSize(8);
        $price->allowDecimals(true);
        $price->setDecimals(2);
        $form->addItem($price);

        $currency = new ilTextInputGUI($this->txt('plan_currency'), 'plan_currency');
        $currency->setMaxLength(8);
        $currency->setSize(6);
        $form->addItem($currency);

        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->txt('limit_section'));
        $section->setInfo($this->txt('limit_section_desc'));
        $form->addItem($section);

        $limit = new ilNumberInputGUI($this->txt('monthly_limit'), 'monthly_limit');
        $limit->setInfo($this->txt('monthly_limit_desc', [
            'used' => number_format(Budget::used()),
            'included' => number_format(Plan::includedCredits()),
            'reset' => Views::date(Budget::nextMonthStart()),
        ]));
        $limit->setMinValue(0);
        $limit->setSize(10);
        $limit->allowDecimals(false);
        $limit->setRequired(true);
        $form->addItem($limit);

        $emails = new ilTextAreaInputGUI($this->txt('notify_emails'), 'notify_emails');
        $emails->setInfo($this->txt('notify_emails_desc', ['admin' => Html::e(Ilias::setting('admin_email')), 'thresholds' => implode(' %, ', Budget::THRESHOLDS) . ' %']));
        $emails->setRows(2);
        $form->addItem($emails);

        $form->addCommandButton('savePlan', $this->lng->txt('save'));
        return $form;
    }

    /**
     * Read and validate the plan form; null when it is invalid (the form
     * is shown again with the alerts).
     *
     * @return array<string, string>|null
     */
    private function planInput(ilPropertyFormGUI $form): ?array
    {
        if (!$form->checkInput()) {
            $form->setValuesByPost();
            $this->showPlan($form);
            return null;
        }
        $plan = (string) $form->getInput('plan');
        if (!in_array($plan, Plan::ALL, true)) {
            $plan = Plan::LEARN;
        }
        $emails = trim((string) $form->getInput('notify_emails'));
        foreach (preg_split('/[\s,;]+/', $emails) ?: [] as $address) {
            if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $form->getItemByPostVar('notify_emails')->setAlert($this->txt('error_email', ['email' => Html::e($address)]));
                $form->setValuesByPost();
                $this->showPlan($form);
                return null;
            }
        }
        return [
            'plan' => $plan,
            'plan_credits' => (string) max(0, (int) $form->getInput('plan_credits')),
            'plan_price' => number_format(max(0, (float) str_replace(',', '.', (string) $form->getInput('plan_price'))), 2, '.', ''),
            'plan_currency' => Config::bmp(mb_substr(trim((string) $form->getInput('plan_currency')), 0, 8)),
            'monthly_limit' => (string) max(0, (int) $form->getInput('monthly_limit')),
            'notify_emails' => Config::bmp($emails),
        ];
    }

    private function savePlan(): void
    {
        $form = $this->initPlanForm();
        $values = $this->planInput($form);
        if ($values === null) {
            return;
        }
        $old = Budget::limit();
        $new = (int) $values['monthly_limit'];
        if ($new > 0 && ($old === 0 || $new < $old)) {
            // Lowering the limit (or introducing one) can block requests
            // this very month: confirm it first.
            $this->tabs->activateSubTab('cfg_plan');
            $confirm = new ilConfirmationGUI();
            $confirm->setFormAction($this->ctrl->getFormAction($this, 'confirmPlan'));
            $confirm->setHeaderText($this->txt('limit_reduce_confirm', [
                'old' => $old > 0 ? number_format($old) : $this->txt('no_limit'),
                'new' => number_format($new),
                'used' => number_format(Budget::used()),
            ]));
            $confirm->setConfirm($this->lng->txt('confirm'), 'confirmPlan');
            $confirm->setCancel($this->lng->txt('cancel'), 'showPlan');
            foreach ($values as $key => $value) {
                $confirm->addHiddenItem('plan_' . $key, $value);
            }
            $this->tpl->setContent($confirm->getHTML());
            return;
        }
        $this->storePlan($values);
    }

    private function confirmPlan(): void
    {
        $body = Ilias::postBody();
        $values = [];
        foreach (['plan', 'plan_credits', 'plan_price', 'plan_currency', 'monthly_limit', 'notify_emails'] as $key) {
            $values[$key] = (string) ($body['plan_' . $key] ?? '');
        }
        if (!in_array($values['plan'], Plan::ALL, true)) {
            $this->ctrl->redirect($this, 'showPlan');
        }
        $values['plan_credits'] = (string) max(0, (int) $values['plan_credits']);
        $values['plan_price'] = number_format(max(0, (float) $values['plan_price']), 2, '.', '');
        $values['monthly_limit'] = (string) max(0, (int) $values['monthly_limit']);
        $this->storePlan($values);
    }

    /**
     * @param array<string, string> $values
     */
    private function storePlan(array $values): void
    {
        $old = Budget::limit();
        $planchanged = Plan::current() !== $values['plan'];
        Config::set('plan', $values['plan']);
        Config::set('plan_credits', $values['plan_credits']);
        Config::set('plan_price', $values['plan_price']);
        Config::set('plan_currency', $values['plan_currency']);
        Config::set('monthly_limit', $values['monthly_limit']);
        Config::set('notify_emails', $values['notify_emails']);
        $new = (int) $values['monthly_limit'];
        if ($new !== $old) {
            // The thresholds moved: warn afresh when they are crossed again.
            Budget::resetWarnings();
            Budget::checkThresholds();
        }
        if ($planchanged) {
            // Another plan means other active modes, and with them another
            // score and progress for every learner.
            foreach (ilObject::_getObjectsByType('xtar') as $object) {
                \TarsusIlias\Activity\Scoring::pushAll((int) $object['obj_id']);
            }
        }
        $this->tpl->setOnScreenMessage('success', $this->lng->txt('settings_saved'), true);
        $this->ctrl->redirect($this, 'showPlan');
    }

    private function refreshUsage(): void
    {
        $before = Budget::remote();
        $after = Budget::refreshRemote(true);
        if ($after === null || ($before !== null && $after['fetched'] === $before['fetched'])) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('usage_refresh_failed'), true);
        } else {
            $this->tpl->setOnScreenMessage('success', $this->txt('usage_refreshed', ['credits' => number_format($after['credits'])]), true);
        }
        $this->ctrl->redirect($this, 'showStatus');
    }

    /**
     * The plan, this month's usage and the cost estimate.
     */
    private function usagePanel(): string
    {
        $remote = Budget::remote();
        $overage = Budget::overage();
        $local = Budget::localCredits();
        $rows = [
            [$this->txt('plan_included'), Plan::isPayAsYouGrow()
                ? $this->txt('plan_payg_note', ['price' => Html::e(Plan::money(Plan::pricePerRequest()))])
                : $this->txt('plan_included_value', ['credits' => number_format(Plan::includedCredits()), 'price' => Html::e(Plan::money(Plan::pricePerRequest()))])],
            [$this->txt('usage_local'), $this->txt('usage_local_value', ['credits' => number_format($local), 'requests' => number_format(Budget::localRequests())])],
            [$this->txt('usage_remote'), $remote === null
                ? $this->txt('usage_remote_none')
                : $this->txt('usage_remote_value', ['credits' => number_format($remote['credits']), 'fetched' => Html::e(Views::date($remote['fetched']))])],
            [$this->txt('monthly_limit'), Budget::hasLimit()
                ? $this->txt('monthly_limit_value', ['limit' => number_format(Budget::limit()), 'remaining' => number_format(Budget::remaining())])
                : $this->txt('no_limit')],
            [$this->txt('usage_charged'), $overage['charged'] > 0
                ? $this->txt('usage_charged_value', ['requests' => number_format($overage['charged']), 'cost' => Html::e(Plan::money($overage['cost']))])
                : $this->txt('usage_charged_none')],
            [$this->txt('usage_reset'), Html::e(Views::date(Budget::nextMonthStart()))],
        ];
        return Views::planUsage([
            'plan' => Plan::label() . (Plan::isConfigured() ? '' : ' (' . $this->txt('plan_default_note') . ')'),
            'used' => Budget::used(),
            'limit' => Budget::limit(),
            'level' => Budget::level(),
            'rows' => $rows,
            'items' => $remote === null ? [] : $remote['items'],
        ]);
    }

    // ------------------------------------------------------------------
    // Brains
    // ------------------------------------------------------------------

    private function showBrains(): void
    {
        $this->tabs->activateSubTab('cfg_brains');
        $current = Config::brainId();

        try {
            $brains = ClientFactory::getClient()->listBrains();
        } catch (ConfigurationException $e) {
            $this->tpl->setOnScreenMessage('info', $this->txt($e->langKey));
            $this->tpl->setContent('');
            return;
        } catch (TransportException | BlockedException $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('transport_error') . ' ' . Html::e($e->getMessage()));
            $this->tpl->setContent('');
            return;
        }

        if ($brains === []) {
            $this->tpl->setOnScreenMessage('info', $this->txt('brain_none_found'));
            $this->tpl->setContent('');
            return;
        }

        $ids = array_column($brains, 'brainid');
        if ($current === '') {
            $this->tpl->setOnScreenMessage('info', $this->txt('brain_none_selected'));
        } elseif (!in_array($current, $ids, true)) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('brain_missing', ['brain' => Html::e($current)]));
        }

        $html = '<div class="tarsus-app"><div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr>'
            . '<th>' . $this->txt('brain_col_id') . '</th>'
            . '<th>' . $this->txt('brain_col_status') . '</th>'
            . '<th>' . $this->txt('brain_col_language') . '</th>'
            . '<th>' . $this->txt('brain_col_tag') . '</th>'
            . '<th>' . $this->txt('brain_col_created') . '</th>'
            . '<th>' . $this->txt('brain_col_action') . '</th>'
            . '</tr></thead><tbody>';
        $action = $this->ctrl->getFormAction($this, 'activateBrain');
        foreach ($brains as $brain) {
            $id = (string) $brain['brainid'];
            $deleted = strpos($id, 'deleted-') === 0;
            $active = !empty($brain['active']) && !$deleted;
            $inuse = $id === $current;
            $state = $deleted ? ['muted', 'brain_deleted'] : ($active ? ['success', 'brain_active'] : ['neutral', 'brain_inactive']);
            $html .= '<tr>'
                . '<td><code>' . Html::e($id) . '</code>' . ($inuse ? ' <span class="tarsus-badge tarsus-badge--info">' . $this->txt('brain_inuse') . '</span>' : '') . '</td>'
                . '<td><span class="tarsus-badge tarsus-badge--' . $state[0] . '">' . $this->txt($state[1]) . '</span></td>'
                . '<td>' . Html::e((string) $brain['language']) . '</td>'
                . '<td>' . Html::e((string) $brain['tag']) . '</td>'
                . '<td class="tarsus-nowrap">' . ((int) $brain['timecreated'] > 0 ? Html::e(Views::date((int) $brain['timecreated'])) : '&ndash;') . '</td>'
                . '<td>';
            if ($active && !$inuse) {
                $html .= '<form method="post" action="' . Html::e($action) . '" class="tarsus-inline-form">'
                    . '<input type="hidden" name="brainid" value="' . Html::e($id) . '">'
                    . '<button type="submit" name="cmd[activateBrain]" class="btn btn-default btn-sm tarsus-btn tarsus-btn--sm">' . $this->txt('brain_activate') . '</button>'
                    . '</form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';
        $this->tpl->setContent($html);
    }

    private function activateBrain(): void
    {
        $body = Ilias::postBody();
        $brainid = trim((string) ($body['brainid'] ?? ''));
        $ok = false;
        if ($brainid !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $brainid)) {
            try {
                foreach (ClientFactory::getClient()->listBrains() as $brain) {
                    if ((string) $brain['brainid'] === $brainid && !empty($brain['active']) && strpos($brainid, 'deleted-') !== 0) {
                        $ok = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        if ($ok) {
            Config::set('brain_id', $brainid);
            $this->tpl->setOnScreenMessage('success', $this->txt('brain_activated', ['brain' => Html::e($brainid)]), true);
        } else {
            $this->tpl->setOnScreenMessage('failure', $this->txt('brain_activate_failed'), true);
        }
        $this->ctrl->redirect($this, 'showBrains');
    }

    // ------------------------------------------------------------------
    // Deletions
    // ------------------------------------------------------------------

    private function showDeletions(): void
    {
        $this->tabs->activateSubTab('cfg_deletions');
        $rows = (new Reconciler())->outstanding();
        if ($rows === []) {
            $this->tpl->setOnScreenMessage('success', $this->txt('deletions_none'));
            $this->tpl->setContent('');
            return;
        }
        $this->tpl->setOnScreenMessage('info', $this->txt('deletions_pending', ['count' => count($rows)]));

        $html = '<div class="tarsus-app">'
            . '<form method="post" action="' . Html::e($this->ctrl->getFormAction($this, 'retryDeletions')) . '" class="tarsus-inline-form">'
            . '<button type="submit" name="cmd[retryDeletions]" class="btn btn-default tarsus-btn tarsus-btn--primary">' . $this->txt('deletions_retry') . '</button>'
            . '</form>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr>'
            . '<th>' . $this->txt('deletions_resource') . '</th>'
            . '<th>' . $this->txt('deletions_container') . '</th>'
            . '<th>' . $this->txt('deletions_since') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td><code>' . Html::e($row->resourceid) . '</code></td>'
                . '<td>' . Html::e($this->containerTitle((int) $row->containerref)) . '</td>'
                . '<td class="tarsus-nowrap">' . Html::e(Views::date((int) $row->timemodified)) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';
        $this->tpl->setContent($html);
    }

    private function containerTitle(int $refid): string
    {
        if (!Ilias::refAlive($refid)) {
            return $this->txt('container_gone', ['ref' => $refid]);
        }
        try {
            return ilObject::_lookupTitle(ilObject::_lookupObjId($refid)) . ' (' . $refid . ')';
        } catch (Throwable $e) {
            return (string) $refid;
        }
    }

    private function retryDeletions(): void
    {
        $cleared = (new Reconciler())->sweep(true);
        $this->tpl->setOnScreenMessage($cleared > 0 ? 'success' : 'info', $this->txt('deletions_retried', ['count' => $cleared]), true);
        $this->ctrl->redirect($this, 'showDeletions');
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    private function showStatus(): void
    {
        $this->tabs->activateSubTab('cfg_status');
        $snapshot = (new Pipeline())->snapshot();
        $breaker = $snapshot['breaker'];
        $bucket = $snapshot['bucket'];

        $rows = [
            [$this->txt('mockmode'), Config::mockMode() ? $this->lng->txt('yes') : $this->lng->txt('no')],
            [$this->txt('status_configured'), (Config::baseUrl() !== '' && Config::apiKey() !== '' && Config::brainId() !== '') ? $this->lng->txt('yes') : $this->lng->txt('no')],
            [$this->txt('killswitch'), Config::killswitch() ? $this->lng->txt('yes') : $this->lng->txt('no')],
            [$this->txt('status_breaker'), ($breaker->state === 'open' ? $this->txt('status_breaker_open', ['since' => Views::date((int) $breaker->opensince)]) : $this->txt('status_breaker_closed'))
                . ' (' . $this->txt('status_failures', ['count' => (int) $breaker->failurecount]) . ')'],
            [$this->txt('status_bucket'), $this->txt('status_bucket_value', [
                // A bucket never used yet is full.
                'tokens' => number_format((int) $bucket->lastrefill === 0 ? (float) Config::rateLimit() : (float) $bucket->tokens, 1),
                'limit' => Config::rateLimit(),
            ])],
        ];

        $html = '<div class="tarsus-app">';
        $html .= '<div class="tarsus-dashboard__bulk">'
            . '<form method="post" action="' . Html::e($this->ctrl->getFormAction($this, 'testConnection')) . '" class="tarsus-inline-form">'
            . '<button type="submit" name="cmd[testConnection]" class="btn btn-default tarsus-btn tarsus-btn--primary">' . $this->txt('status_test') . '</button></form>'
            . '<form method="post" action="' . Html::e($this->ctrl->getFormAction($this, 'resetBreaker')) . '" class="tarsus-inline-form">'
            . '<button type="submit" name="cmd[resetBreaker]" class="btn btn-default tarsus-btn">' . $this->txt('status_reset_breaker') . '</button></form>'
            . '<form method="post" action="' . Html::e($this->ctrl->getFormAction($this, 'refreshUsage')) . '" class="tarsus-inline-form">'
            . '<button type="submit" name="cmd[refreshUsage]" class="btn btn-default tarsus-btn">' . Html::icon('refresh') . '<span>' . $this->txt('usage_refresh') . '</span></button></form>'
            . '</div>';
        $html .= $this->usagePanel();
        $html .= '<h3 class="tarsus-h3">' . $this->txt('status_guardrails') . '</h3>';
        $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><tbody>';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th scope="row" style="width:30%">' . $label . '</th><td>' . Html::e($value) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        // Index map totals.
        $html .= '<h3 class="tarsus-h3">' . $this->txt('status_index') . '</h3>';
        $counts = (new Repository())->countByStatus();
        if ($counts === []) {
            $html .= '<p class="tarsus-muted">' . $this->txt('status_index_none') . '</p>';
        } else {
            $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><tbody>';
            foreach ($counts as $status => $count) {
                $html .= '<tr><th scope="row" style="width:30%">' . Html::e(Status::label((string) $status)) . '</th><td>' . (int) $count . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // Usage of the last 24 hours.
        $html .= '<h3 class="tarsus-h3">' . $this->txt('status_usage') . '</h3>';
        $summary = (new UsageMeter())->summary(time() - 86400);
        if ($summary === []) {
            $html .= '<p class="tarsus-muted">' . $this->txt('status_usage_none') . '</p>';
        } else {
            $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . $this->txt('status_operation') . '</th><th>'
                . $this->txt('status_calls') . '</th><th>' . $this->txt('status_credits') . '</th></tr></thead><tbody>';
            foreach ($summary as $operation => $figures) {
                $html .= '<tr><td>' . Html::e($operation) . '</td><td>' . (int) $figures['calls'] . '</td><td>' . (int) $figures['credits'] . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div>';
        $this->tpl->setContent($html);
    }

    private function resetBreaker(): void
    {
        (new Pipeline())->resetBreaker();
        $this->tpl->setOnScreenMessage('success', $this->txt('status_breaker_reset'), true);
        $this->ctrl->redirect($this, 'showStatus');
    }

    private function testConnection(): void
    {
        try {
            $brains = (new Pipeline())->execute(fn() => ClientFactory::getClient()->listBrains(), 0);
            $this->tpl->setOnScreenMessage('success', $this->txt('status_test_ok', ['count' => count((array) $brains)]), true);
        } catch (ConfigurationException $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt($e->langKey), true);
        } catch (BlockedException $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('guardrail_blocked_' . $e->reason), true);
        } catch (Throwable $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('transport_error') . ' ' . Html::e($e->getMessage()), true);
        }
        $this->ctrl->redirect($this, 'showStatus');
    }
}
