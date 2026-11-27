<?php

/**
 * Plugin configuration (Administration > Extending ILIAS > Plugins > Tarsus
 * > Configure): the connection to the Tarsus brain, the assistant's
 * identity, the guardrails, the brain picker, outstanding deletions and
 * the live status.
 *
 * @author  Tarsus GmbH
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
use TarsusIlias\Activity\Didactics;
use TarsusIlias\Material\BitType;
use TarsusIlias\Output\Html;
use TarsusIlias\Output\Views;
use TarsusIlias\Service\Messages;
use TarsusIlias\Support\Budget;
use TarsusIlias\Support\Config;
use TarsusIlias\Support\Help;
use TarsusIlias\Support\Ilias;
use TarsusIlias\Support\Plan;
use TarsusIlias\Support\UsageMeter;
use TarsusIlias\Support\Voices;
use ILIAS\UI\Component\Input\Container\Form\Standard as StandardForm;

class ilTarsusConfigGUI extends ilPluginConfigGUI implements ilCtrlSecurityInterface
{
    /** Largest avatar kept, in pixels (longest side). */
    private const AVATAR_SIZE = 160;

    /** Largest avatar upload accepted, in bytes. */
    private const AVATAR_MAX_BYTES = 2097152;

    /** Session key of plan values that wait for the confirmation of a lower limit. */
    private const PENDING_PLAN = 'xtar_pending_plan';

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

    /**
     * Links that change something: ILIAS adds its token to them and checks
     * it, as it does for every form sent here.
     *
     * @return string[]
     */
    public function getUnsafeGetCommands(): array
    {
        return ['refreshVoices', 'confirmPlan'];
    }

    /**
     * @return string[]
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    public function performCommand(string $cmd): void
    {
        if ($this->getPluginObject() !== null) {
            Ilias::setPlugin($this->getPluginObject());
        }
        if ($this->ctrl->getNextClass($this) === strtolower(ilTarsusAvatarUploadHandlerGUI::class)) {
            // The picture field uploads (and removes) its file here.
            $this->ctrl->forwardCommand(new ilTarsusAvatarUploadHandlerGUI());
            return;
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

    private function configure(?StandardForm $form = null): void
    {
        $this->tabs->activateSubTab('cfg_settings');
        if ($form === null) {
            // A fresh form: pictures uploaded before but never saved go.
            ilTarsusAvatarUploadHandlerGUI::discardPending();
        }
        $this->planHint();
        $this->tpl->setContent(Ilias::ui()->renderer()->render($form ?? $this->settingsForm()));
    }

    /**
     * The partner agreement asks the partner to enforce the booked package
     * in the plugin. While none is set on a live connection, every feature
     * is reachable - say so on every configuration page.
     */
    private function planHint(): void
    {
        if (Plan::needsConfiguration()) {
            $this->tpl->setOnScreenMessage('info', $this->txt('plan_not_set_hint', ['plan' => Plan::label()])
                . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'showPlan')) . '">' . $this->txt('cfg_tab_plan') . '</a>');
        }
    }

    /**
     * The connection to Tarsus, the assistant, the guardrails and the help
     * links, filled with the stored values; keys are never shown.
     */
    private function settingsForm(): StandardForm
    {
        $field = Ilias::ui()->factory()->input()->field();

        // Connection.
        $connection = [
            'base_url' => $field->text($this->txt('baseurl'), $this->txt('baseurl_desc'))
                ->withMaxLength(255)
                ->withValue(Config::baseUrl())
                ->withAdditionalTransformation(Ilias::check(
                    static fn($value): bool => self::baseUrlOk((string) $value),
                    $this->txt('error_baseurl')
                )),
            'api_key' => $field->password(
                $this->txt('apikey'),
                $this->txt('apikey_desc') . ' ' . $this->txt(Config::apiKey() !== '' ? 'apikey_keep' : 'apikey_none')
            ),
            'brain_id' => $field->text(
                $this->txt('brainid'),
                $this->txt('brainid_desc') . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'showBrains')) . '">'
                    . $this->txt('cfg_tab_brains') . '</a> ' . $this->txt('brainid_sandbox')
            )->withMaxLength(255)->withValue(Config::brainId()),
            // Existing Tarsus customers: a license key in place of a brain
            // ID, billed by Tarsus.
            'license_key' => $field->password(
                $this->txt('licensekey'),
                $this->txt('licensekey_desc') . ' ' . $this->txt(Config::licenseKey() !== '' ? 'licensekey_keep' : 'licensekey_none')
            ),
        ];
        if (Config::licenseKey() !== '') {
            $connection['license_key_clear'] = $field->checkbox($this->txt('licensekey_clear'), $this->txt('licensekey_clear_desc'));
        }

        // Assistant.
        $image = Config::robotAvatar();
        $assistant = [
            'robot_name' => $field->text($this->txt('robotname'), $this->txt('robotname_desc'))
                ->withMaxLength(100)
                ->withValue((string) Config::get('robot_name', Config::DEFAULT_ROBOT_NAME)),
            'robot_id' => $field->text($this->txt('robotid'), $this->txt('robotid_desc'))
                ->withMaxLength(255)
                ->withValue((string) Config::get('robot_id', '')),
            'robot_description' => $field->textarea($this->txt('robotdescription'), $this->txt('robotdescription_desc'))
                ->withValue((string) Config::get('robot_description', '')),
        ];
        $avatar = $this->avatarField($image);
        if ($avatar !== null) {
            $assistant['robot_avatar'] = $avatar;
        }
        if ($image !== '') {
            $assistant['robot_avatar_remove'] = $field->checkbox($this->txt('robotavatar_remove'));
        }
        $assistant['voice_mode'] = $field->radio($this->txt('voice_mode'), $this->txt('voice_mode_desc'))
            ->withOption(Voices::MODE_AUTO, $this->txt('voice_mode_auto'))
            ->withOption(Voices::MODE_FIXED, $this->txt('voice_mode_fixed'))
            ->withValue((string) Config::get('voice_mode', Voices::MODE_AUTO) === Voices::MODE_FIXED ? Voices::MODE_FIXED : Voices::MODE_AUTO);
        $voices = $this->voiceOptions();
        $voice = trim((string) Config::get('voice', '')) !== '' ? (string) Config::get('voice') : Voices::DEFAULT_VOICE;
        $assistant['voice'] = $field->select(
            $this->txt('voice'),
            $voices,
            $this->txt('voice_desc') . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'refreshVoices')) . '">'
                . $this->txt('voice_refresh') . '</a>'
        )
            ->withRequired(true)
            ->withValue(array_key_exists($voice, $voices) ? $voice : null);

        // Guardrails; the monthly limit is set with the plan.
        $guardrails = [
            'mockmode' => $field->checkbox($this->txt('mockmode'), $this->txt('mockmode_desc'))->withValue(Config::mockMode()),
            'killswitch' => $field->checkbox($this->txt('killswitch'), $this->txt('killswitch_desc'))->withValue(Config::killswitch()),
            'ratelimit' => $field->numeric($this->txt('ratelimit'), $this->txt('ratelimit_desc'))
                ->withValue(Config::rateLimit())
                ->withAdditionalTransformation($this->atLeast(1)),
        ];
        $limitnote = $this->txt('monthly_limit') . ': '
            . $this->txt('monthly_limit_elsewhere', ['limit' => Budget::hasLimit() ? number_format(Budget::limit()) : $this->txt('no_limit')])
            . ' <a href="' . Html::e($this->ctrl->getLinkTarget($this, 'showPlan')) . '">' . $this->txt('cfg_tab_plan') . '</a>';

        // Help.
        $help = [
            Help::KEY => $field->textarea($this->txt('help_links'), $this->txt('help_links_desc'))
                ->withValue((string) Config::get(Help::KEY, '')),
        ];

        return Ilias::ui()->factory()->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'saveSettings'),
            [
                'connection' => $field->section($connection, $this->txt('settings_connection'), $this->txt('settings_connection_desc')),
                'assistant' => $field->section($assistant, $this->txt('settings_assistant'), $this->txt('settings_assistant_desc')),
                'guardrails' => $field->section($guardrails, $this->txt('settings_guardrails'), $this->txt('settings_guardrails_desc') . '<br>' . $limitnote),
                'help' => $field->section($help, $this->txt('settings_help'), $this->txt('settings_help_desc')),
            ]
        )->withSubmitLabel($this->lng->txt('save'));
    }

    /**
     * The file field of the assistant's picture, with the current one
     * shown; null while ILIAS does not know the upload handler yet (the
     * page then works without it).
     */
    private function avatarField(string $image): ?\ILIAS\UI\Component\Input\Field\File
    {
        $byline = $this->txt('robotavatar_desc');
        if ($image !== '') {
            $byline .= '<br><img class="tarsus-avatar-preview" src="' . Html::e($image) . '" alt="">';
        }
        try {
            $handler = new ilTarsusAvatarUploadHandlerGUI();
            // Fails when ILIAS's list of GUI classes misses the handler.
            $handler->getUploadURL();
            return Ilias::ui()->factory()->input()->field()->file($handler, $this->txt('robotavatar'), $byline)
                ->withAcceptedMimeTypes(['image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                ->withMaxFileSize(self::AVATAR_MAX_BYTES);
        } catch (Throwable $e) {
            Ilias::log('picture upload unavailable: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    /**
     * An empty base URL, or one starting with http:// or https:// and a host.
     */
    private static function baseUrlOk(string $value): bool
    {
        $url = rtrim(trim($value), '/');
        return $url === '' || preg_match('~^https?://[^\s/]+~i', $url) === 1;
    }

    /**
     * The text of a password field ('' when left empty).
     *
     * @param mixed $value
     */
    private static function secret($value): string
    {
        if ($value instanceof \ILIAS\Data\Password) {
            return $value->toString();
        }
        return is_string($value) ? $value : '';
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

    private function saveSettings(): void
    {
        $form = $this->settingsForm()->withRequest(Ilias::request());
        $data = $form->getData();
        if (!is_array($data)) {
            $this->configure($form);
            return;
        }
        $values = [];
        foreach ($data as $section) {
            $values += (array) $section;
        }

        $target = Config::target();
        Config::set('base_url', rtrim(trim((string) $values['base_url']), '/'));

        $apikey = trim(self::secret($values['api_key'] ?? null));
        if ($apikey !== '') {
            Config::set('api_key', $apikey);
        }
        Config::set('brain_id', trim((string) $values['brain_id']));
        if (!empty($values['license_key_clear'])) {
            Config::set('license_key', '');
        } else {
            $licensekey = trim(self::secret($values['license_key'] ?? null));
            if ($licensekey !== '') {
                Config::set('license_key', $licensekey);
            }
        }
        Config::set('robot_name', Config::bmp(trim((string) $values['robot_name'])));
        Config::set('robot_id', Config::bmp(trim((string) $values['robot_id'])));
        Config::set('robot_description', Config::bmp(trim((string) $values['robot_description'])));
        $mode = (string) $values['voice_mode'];
        Config::set('voice_mode', $mode === Voices::MODE_FIXED ? Voices::MODE_FIXED : Voices::MODE_AUTO);
        Config::set('voice', Config::bmp(trim((string) $values['voice'])));
        Config::set('mockmode', !empty($values['mockmode']) ? '1' : '0');
        Config::set('killswitch', !empty($values['killswitch']) ? '1' : '0');
        Config::set('ratelimit', (string) max(1, (int) $values['ratelimit']));
        Config::set(Help::KEY, Config::bmp(trim((string) $values[Help::KEY])));

        $uploads = array_values(array_filter((array) ($values['robot_avatar'] ?? []), 'is_string'));
        if (!empty($values['robot_avatar_remove'])) {
            Config::set('robot_avatar', '');
        } elseif ($uploads !== []) {
            $uri = $this->avatarDataUri((string) ilTarsusAvatarUploadHandlerGUI::read($uploads[0]));
            if ($uri === null) {
                $this->tpl->setOnScreenMessage('failure', $this->txt('error_avatar'), true);
            } else {
                Config::set('robot_avatar', $uri);
            }
        }
        ilTarsusAvatarUploadHandlerGUI::discardPending();

        $this->retargetIfMoved($target);
        $this->tpl->setOnScreenMessage('success', $this->lng->txt('settings_saved'), true);
        $this->ctrl->redirect($this, 'configure');
    }

    /**
     * After the connection moved to another brain (demo mode switched,
     * another server, brain ID or license key): what the previous brain
     * held is sent to the new one, and the admin is told. Saving never
     * fails over it.
     */
    private function retargetIfMoved(string $before): void
    {
        if (Config::target() === $before) {
            return;
        }
        try {
            $count = (new Reconciler())->retarget();
        } catch (Throwable $e) {
            Ilias::log('retarget after a connection change: ' . $e->getMessage(), 'error');
            return;
        }
        if ($count > 0) {
            $this->tpl->setOnScreenMessage('info', $this->txt('retarget_notice', ['count' => $count]), true);
        }
    }

    /**
     * The uploaded image, downscaled and encoded as a data URI; null when
     * it is no usable image.
     */
    private function avatarDataUri(string $data): ?string
    {
        if ($data === '' || strlen($data) > self::AVATAR_MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($data);
        if ($info === false) {
            return null;
        }
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }
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
                // No imagedestroy(): it does nothing since PHP 8.0 and is
                // deprecated in PHP 8.5; the images are freed on return.
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

    private function showPlan(?StandardForm $form = null): void
    {
        $this->tabs->activateSubTab('cfg_plan');
        $this->planHint();
        // Leaving the confirmation of a lower limit drops what it held.
        ilSession::clear(self::PENDING_PLAN);
        $this->tpl->setContent('<div class="tarsus-app">' . $this->usagePanel() . $this->featurePanel() . '</div>'
            . Ilias::ui()->renderer()->render($form ?? $this->planForm()));
    }

    /**
     * "Funktionen je Paket": which features each package includes, the
     * current one marked. The table of the partner agreement, so that the
     * administrator sees what the plugin will and will not offer.
     */
    private function featurePanel(): string
    {
        $current = Plan::current();
        $html = '<details class="tarsus-details tarsus-details--plan"><summary>' . $this->txt('plan_features_title') . '</summary>'
            . '<p class="tarsus-muted">' . $this->txt('plan_features_desc') . '</p>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table tarsus-table--features"><thead><tr><th>' . $this->txt('plan_feature') . '</th>';
        foreach ([Plan::ASK, Plan::PRACTICE, Plan::LEARN, Plan::PAYG] as $plan) {
            $html .= '<th class="tarsus-center' . ($plan === $current ? ' tarsus-th--current' : '') . '">' . $this->txt('plan_' . $plan . '_short')
                . ($plan === $current ? '<br><span class="tarsus-badge tarsus-badge--info">' . $this->txt('plan_current') . '</span>' : '') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (Plan::ALL_FEATURES as $feature) {
            $html .= '<tr><td>' . $this->txt('plan_feature_' . $feature) . '</td>';
            foreach ([Plan::ASK, Plan::PRACTICE, Plan::LEARN, Plan::PAYG] as $plan) {
                $yes = Plan::allows($feature, $plan);
                $html .= '<td class="tarsus-center' . ($plan === $current ? ' tarsus-td--current' : '') . '">'
                    . '<span class="tarsus-badge tarsus-badge--' . ($yes ? 'success' : 'muted') . '">' . $this->lng->txt($yes ? 'yes' : 'no') . '</span></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div><p class="tarsus-muted">' . $this->txt('plan_features_note') . '</p></details>';
        return $html;
    }

    /**
     * The package, its AI credits and price, and the monthly limit with
     * the addresses warned about it, filled with the stored values.
     */
    private function planForm(): StandardForm
    {
        $field = Ilias::ui()->factory()->input()->field();

        $plan = $field->radio($this->txt('plan'))->withRequired(true);
        foreach (Plan::ALL as $key) {
            $plan = $plan->withOption($key, $this->txt('plan_' . $key), $this->txt('plan_' . $key . '_desc'));
        }
        $plan = $plan->withValue(in_array(Plan::current(), Plan::ALL, true) ? Plan::current() : null);

        $credits = $field->numeric($this->txt('plan_credits'), $this->txt('plan_credits_desc', ['default' => number_format(Plan::DEFAULT_CREDITS)]))
            ->withAdditionalTransformation($this->atLeast(0));
        if (!Plan::isPayAsYouGrow()) {
            $credits = $credits->withValue(Plan::includedCredits());
        }

        $price = $field->text($this->txt('plan_price'), $this->txt('plan_price_desc', ['default' => number_format(Plan::DEFAULT_PRICE, 2)]))
            ->withMaxLength(12)
            ->withValue(number_format(Plan::pricePerRequest(), 2, '.', ''))
            ->withAdditionalTransformation(Ilias::check(
                static fn($value): bool => self::price((string) $value) !== null,
                $this->txt('error_price')
            ));

        $currency = $field->text($this->txt('plan_currency'))->withMaxLength(8)->withValue(Plan::currency());

        $limit = $field->numeric($this->txt('monthly_limit'), $this->txt('monthly_limit_desc', [
            'used' => Budget::format(Budget::used()),
            'included' => number_format(Plan::includedCredits()),
            'reset' => Views::date(Budget::nextMonthStart()),
        ]))
            ->withRequired(true)
            ->withValue(Budget::limit())
            ->withAdditionalTransformation($this->atLeast(0));

        $emails = $field->textarea($this->txt('notify_emails'), $this->txt('notify_emails_desc', [
            'admin' => Html::e(Ilias::setting('admin_email')),
            'thresholds' => implode(' %, ', Budget::THRESHOLDS) . ' %',
        ]))
            ->withValue((string) Config::get('notify_emails', ''))
            ->withAdditionalTransformation(Ilias::check(
                fn($value): bool => $this->invalidEmail((string) $value) === null,
                fn($value): string => $this->txt('error_email', ['email' => Html::e((string) $this->invalidEmail((string) $value))])
            ));

        return Ilias::ui()->factory()->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, 'savePlan'),
            [
                'plan' => $field->section(
                    ['plan' => $plan, 'plan_credits' => $credits, 'plan_price' => $price, 'plan_currency' => $currency],
                    $this->txt('plan_section'),
                    $this->txt('plan_section_desc')
                ),
                'limit' => $field->section(
                    ['monthly_limit' => $limit, 'notify_emails' => $emails],
                    $this->txt('limit_section'),
                    $this->txt('limit_section_desc')
                ),
            ]
        )->withSubmitLabel($this->lng->txt('save'));
    }

    /**
     * A number field may stay empty or hold at least $minimum.
     */
    private function atLeast(int $minimum): \ILIAS\Refinery\Constraint
    {
        return Ilias::check(
            static fn($value): bool => $value === null || $value === '' || (int) $value >= $minimum,
            $this->txt('error_minimum', ['minimum' => $minimum])
        );
    }

    /**
     * The price per request as entered, with a point or a comma; 0 when
     * empty, null when it is no number of 0 or more.
     */
    private static function price(string $value): ?float
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return 0.0;
        }
        if (!is_numeric($value) || (float) $value < 0) {
            return null;
        }
        return (float) $value;
    }

    /**
     * The first entry of the list that is no e-mail address; null when
     * there is none.
     */
    private function invalidEmail(string $emails): ?string
    {
        foreach (preg_split('/[\s,;]+/', trim($emails)) ?: [] as $address) {
            if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return $address;
            }
        }
        return null;
    }

    /**
     * The values of the plan form as stored.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function planValues(array $data): array
    {
        $values = array_merge((array) $data['plan'], (array) $data['limit']);
        $plan = (string) $values['plan'];
        if (!in_array($plan, Plan::ALL, true)) {
            $plan = Plan::LEARN;
        }
        return [
            'plan' => $plan,
            'plan_credits' => (string) max(0, (int) $values['plan_credits']),
            'plan_price' => number_format(max(0, (float) self::price((string) $values['plan_price'])), 2, '.', ''),
            'plan_currency' => Config::bmp(mb_substr(trim((string) $values['plan_currency']), 0, 8)),
            'monthly_limit' => (string) max(0, (int) $values['monthly_limit']),
            'notify_emails' => Config::bmp(trim((string) $values['notify_emails'])),
        ];
    }

    private function savePlan(): void
    {
        $form = $this->planForm()->withRequest(Ilias::request());
        $data = $form->getData();
        if (!is_array($data)) {
            $this->showPlan($form);
            return;
        }
        $values = $this->planValues($data);
        $old = Budget::limit();
        $new = (int) $values['monthly_limit'];
        if ($new > 0 && ($old === 0 || $new < $old)) {
            // Lowering the limit (or introducing one) can block requests
            // this very month: confirm it first. The values wait in the
            // session; the confirming link carries ILIAS's token.
            $this->tabs->activateSubTab('cfg_plan');
            ilSession::set(self::PENDING_PLAN, json_encode($values));
            $factory = Ilias::ui()->factory();
            $box = $factory->messageBox()->confirmation($this->txt('limit_reduce_confirm', [
                'old' => $old > 0 ? number_format($old) : $this->txt('no_limit'),
                'new' => number_format($new),
                'used' => Budget::format(Budget::used()),
            ]))->withButtons([
                $factory->button()->primary($this->lng->txt('confirm'), $this->ctrl->getLinkTarget($this, 'confirmPlan')),
                $factory->button()->standard($this->lng->txt('cancel'), $this->ctrl->getLinkTarget($this, 'showPlan')),
            ]);
            $this->tpl->setContent(Ilias::ui()->renderer()->render($box));
            return;
        }
        $this->storePlan($values);
    }

    private function confirmPlan(): void
    {
        $pending = json_decode((string) ilSession::get(self::PENDING_PLAN), true);
        ilSession::clear(self::PENDING_PLAN);
        if (!is_array($pending) || !in_array($pending['plan'] ?? null, Plan::ALL, true)) {
            $this->ctrl->redirect($this, 'showPlan');
        }
        $values = [];
        foreach (['plan', 'plan_credits', 'plan_price', 'plan_currency', 'monthly_limit', 'notify_emails'] as $key) {
            $values[$key] = (string) ($pending[$key] ?? '');
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
            $this->tpl->setOnScreenMessage('success', $this->txt('usage_refreshed', ['credits' => Budget::format($after['credits'])]), true);
        }
        $this->ctrl->redirect($this, 'showStatus');
    }

    /**
     * The plan, this month's usage and the cost - all from the brain's own
     * usage figure, the only one there is.
     */
    private function usagePanel(): string
    {
        $remote = Budget::refreshRemote();
        $overage = Budget::overage();
        $tarsusbilled = Plan::billedByTarsus();
        $rows = [
            [$this->txt('plan_billing'), $tarsusbilled ? $this->txt('plan_billing_tarsus') : $this->txt('plan_billing_partner')],
            [$this->txt('plan_included'), Plan::isPayAsYouGrow()
                ? ($tarsusbilled ? $this->txt('plan_payg_note_tarsus') : $this->txt('plan_payg_note', ['price' => Html::e(Plan::money(Plan::pricePerRequest()))]))
                : ($tarsusbilled
                    ? $this->txt('plan_included_value_tarsus', ['credits' => number_format(Plan::includedCredits())])
                    : $this->txt('plan_included_value', ['credits' => number_format(Plan::includedCredits()), 'price' => Html::e(Plan::money(Plan::pricePerRequest()))]))],
            [$this->txt('usage_remote'), $remote === null
                ? $this->txt('usage_remote_none')
                : $this->txt('usage_remote_value', ['credits' => Budget::format($remote['credits']), 'fetched' => Html::e(Views::date($remote['fetched']))])],
            [$this->txt('monthly_limit'), Budget::hasLimit()
                ? $this->txt('monthly_limit_value', ['limit' => Budget::format(Budget::limit()), 'remaining' => Budget::format(Budget::remaining())])
                : $this->txt('no_limit')],
            [$this->txt('usage_charged'), $overage['charged'] > 0
                ? ($tarsusbilled
                    ? $this->txt('usage_charged_value_tarsus', ['requests' => Budget::format($overage['charged'])])
                    : $this->txt('usage_charged_value', ['requests' => Budget::format($overage['charged']), 'cost' => Html::e(Plan::money($overage['cost']))]))
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
        $this->planHint();

        try {
            $brains = ClientFactory::getClient()->listBrains();
        } catch (ConfigurationException $e) {
            $this->tpl->setOnScreenMessage('info', $this->txt($e->lang_key));
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

        $html = '<div class="tarsus-app">'
            . '<p class="tarsus-muted tarsus-sandbox-note">' . $this->txt('brain_sandbox_note') . '</p>'
            . '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr>'
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
        $brainid = trim(Ilias::postString('brainid'));
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
            $target = Config::target();
            Config::set('brain_id', $brainid);
            $this->retargetIfMoved($target);
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
        $this->planHint();
        $snapshot = (new Pipeline())->snapshot();
        $breaker = $snapshot['breaker'];
        $bucket = $snapshot['bucket'];

        $rows = [
            [$this->txt('mockmode'), Config::mockMode() ? $this->lng->txt('yes') : $this->lng->txt('no')],
            [$this->txt('status_configured'), Config::isConnected() ? $this->lng->txt('yes') : $this->lng->txt('no')],
            [$this->txt('status_customer'), Config::licenseKey() !== '' ? $this->txt('status_customer_tarsus') : $this->txt('status_customer_partner')],
            [$this->txt('status_plan'), Plan::label() . (Plan::isConfigured() ? '' : ' (' . $this->txt('plan_default_note') . ')')],
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
            // Requests this installation sent; what they cost is Tarsus's figure above.
            $html .= '<div class="tarsus-table-wrap"><table class="tarsus-table"><thead><tr><th>' . $this->txt('status_operation') . '</th><th>'
                . $this->txt('status_calls') . '</th></tr></thead><tbody>';
            foreach ($summary as $operation => $figures) {
                $html .= '<tr><td>' . Html::e($operation) . '</td><td>' . (int) $figures['calls'] . '</td></tr>';
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
            $this->tpl->setOnScreenMessage('failure', $this->txt($e->lang_key), true);
        } catch (BlockedException $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('guardrail_blocked_' . $e->reason), true);
        } catch (TransportException $e) {
            if (Messages::refusesAccess($e)) {
                // Tarsus refuses the key, the brain or the license key: say so instead of "try again".
                Ilias::log('test connection: ' . $e->getMessage() . ' - ' . $e->body);
                $this->tpl->setOnScreenMessage('failure', $this->txt('status_test_access'), true);
            } else {
                $this->tpl->setOnScreenMessage('failure', $this->txt('transport_error') . ' ' . Html::e($e->getMessage()), true);
            }
        } catch (Throwable $e) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('transport_error') . ' ' . Html::e($e->getMessage()), true);
        }
        $this->ctrl->redirect($this, 'showStatus');
    }
}
