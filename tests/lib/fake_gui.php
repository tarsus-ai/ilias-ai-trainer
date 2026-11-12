<?php

/**
 * The ILIAS form and page classes the plugin's configuration screen uses,
 * reduced to what a test needs: forms collect their items, read their
 * input from $_POST, validate required and numeric fields and render to
 * plain markup; the template remembers messages and content; a redirect
 * is an exception the test catches.
 *
 * @license GPL-3.0-or-later
 */

// phpcs:disable

namespace TarsusIlias\Tests {

    /**
     * Thrown where ILIAS would redirect and end the request.
     */
    final class Redirect extends \Exception
    {
        public string $command;

        public function __construct(string $command)
        {
            $this->command = $command;
            parent::__construct('redirect to ' . $command);
        }
    }

    final class FakeCtrl implements \ilCtrlInterface
    {
        public function getLinkTarget($gui, string $cmd = ''): string
        {
            return 'ilias.php?cmd=' . $cmd;
        }

        public function getLinkTargetByClass($classes, string $cmd = ''): string
        {
            return 'ilias.php?cmd=' . $cmd;
        }

        public function getFormAction($gui, string $fallback = '', string $anchor = '', bool $async = false): string
        {
            return 'ilias.php?cmd=post&fallbackCmd=' . $fallback;
        }

        public function setParameter($gui, string $name, $value): void
        {
        }

        public function clearParameterByClass(string $class, string $name): void
        {
        }

        public function redirect($gui, string $cmd = ''): void
        {
            throw new Redirect($cmd);
        }
    }

    final class FakeTemplate implements \ilGlobalTemplateInterface
    {
        /** @var array<int, array{type: string, text: string, keep: bool}> */
        public array $messages = [];
        public string $content = '';
        /** @var string[] */
        public array $css = [];

        public function setOnScreenMessage(string $type, string $text, bool $keep = false): void
        {
            $this->messages[] = ['type' => $type, 'text' => $text, 'keep' => $keep];
        }

        public function setContent(string $html): void
        {
            $this->content = $html;
        }

        public function addCss(string $url): void
        {
            $this->css[] = $url;
        }

        public function addJavaScript(string $url, bool $batch = true): void
        {
        }

        public function addOnLoadCode(string $code): void
        {
        }

        /**
         * The texts of the messages of one type.
         *
         * @return string[]
         */
        public function texts(string $type): array
        {
            $texts = [];
            foreach ($this->messages as $message) {
                if ($message['type'] === $type) {
                    $texts[] = $message['text'];
                }
            }
            return $texts;
        }
    }
}

namespace {

    use TarsusIlias\Tests\World;

    class ilTabsGUI
    {
        /** @var string[] */
        public array $subtabs = [];
        public string $active = '';

        public function addSubTab(string $id, string $title, string $link): void
        {
            $this->subtabs[$id] = $title;
        }

        public function activateSubTab(string $id): void
        {
            $this->active = $id;
        }

        public function addTab(string $id, string $title, string $link): void
        {
        }

        public function activateTab(string $id): void
        {
            $this->active = $id;
        }
    }

    abstract class ilPluginConfigGUI
    {
        public function getPluginObject(): ?ilPlugin
        {
            return new \TarsusIlias\Tests\FakePlugin();
        }

        abstract public function performCommand(string $cmd): void;
    }

    class ilFormPropertyGUI
    {
        protected string $title;
        protected string $postvar;
        public string $info = '';
        public ?string $alert = null;
        public bool $required = false;
        public bool $disabled = false;
        /** @var mixed */
        public $value = null;

        public function __construct(string $title = '', string $postvar = '')
        {
            $this->title = $title;
            $this->postvar = $postvar;
        }

        public function getTitle(): string
        {
            return $this->title;
        }

        public function getPostVar(): string
        {
            return $this->postvar;
        }

        public function setInfo(string $info): void
        {
            $this->info = $info;
        }

        public function setRequired(bool $required): void
        {
            $this->required = $required;
        }

        public function setAlert(string $alert): void
        {
            $this->alert = $alert;
        }

        public function setDisabled(bool $disabled): void
        {
            $this->disabled = $disabled;
        }

        public function setValue($value): void
        {
            $this->value = $value;
        }

        public function setMaxLength(int $length): void
        {
        }

        public function setSize(int $size): void
        {
        }

        /**
         * @return mixed
         */
        public function getInput()
        {
            // A disabled control posts nothing. ILIAS strips tags but does
            // not trim: that is the caller's business.
            return $this->disabled ? '' : strip_tags((string) ($_POST[$this->postvar] ?? ''));
        }

        public function checkInput(): bool
        {
            if ($this->required && trim((string) $this->getInput()) === '') {
                $this->alert = 'msg_input_is_required';
                return false;
            }
            return true;
        }

        /**
         * @return ilFormPropertyGUI[]
         */
        public function getSubItems(): array
        {
            return [];
        }

        public function render(): string
        {
            $value = is_array($this->value) ? implode(',', $this->value) : (string) ($this->value ?? '');
            return '<div class="item" data-postvar="' . htmlspecialchars($this->postvar) . '"><label>' . $this->title . '</label>'
                . '<span class="value">' . htmlspecialchars($value) . '</span>'
                . ($this->info !== '' ? '<span class="info">' . $this->info . '</span>' : '')
                . ($this->alert !== null ? '<span class="alert">' . $this->alert . '</span>' : '') . '</div>';
        }
    }

    class ilTextInputGUI extends ilFormPropertyGUI
    {
    }

    class ilTextAreaInputGUI extends ilFormPropertyGUI
    {
        public function setRows(int $rows): void
        {
        }
    }

    class ilPasswordInputGUI extends ilFormPropertyGUI
    {
        public function setRetype(bool $retype): void
        {
        }

        public function setSkipSyntaxCheck(bool $skip): void
        {
        }

        public function render(): string
        {
            // As in ILIAS: a value that was set is written into the field.
            return '<div class="item" data-postvar="' . htmlspecialchars($this->postvar) . '"><label>' . $this->title . '</label>'
                . '<input type="password" value="' . htmlspecialchars((string) ($this->value ?? '')) . '">'
                . ($this->info !== '' ? '<span class="info">' . $this->info . '</span>' : '')
                . ($this->alert !== null ? '<span class="alert">' . $this->alert . '</span>' : '') . '</div>';
        }
    }

    class ilNumberInputGUI extends ilFormPropertyGUI
    {
        private ?float $min = null;
        private ?float $max = null;
        private bool $decimals = false;

        public function setMinValue(float $min): void
        {
            $this->min = $min;
        }

        public function setMaxValue(float $max): void
        {
            $this->max = $max;
        }

        public function allowDecimals(bool $allow): void
        {
            $this->decimals = $allow;
        }

        public function setDecimals(int $decimals): void
        {
        }

        public function setSuffix(string $suffix): void
        {
        }

        public function checkInput(): bool
        {
            $input = str_replace(',', '.', (string) $this->getInput());
            if ($input === '') {
                if ($this->required) {
                    $this->alert = 'msg_input_is_required';
                    return false;
                }
                return true;
            }
            if (!is_numeric($input) || (!$this->decimals && (float) $input !== floor((float) $input))) {
                $this->alert = 'form_msg_numeric_value_required';
                return false;
            }
            if (($this->min !== null && (float) $input < $this->min) || ($this->max !== null && (float) $input > $this->max)) {
                $this->alert = 'form_msg_value_out_of_range';
                return false;
            }
            return true;
        }
    }

    class ilCheckboxInputGUI extends ilFormPropertyGUI
    {
        public function getInput()
        {
            return !$this->disabled && !empty($_POST[$this->postvar]);
        }

        public function checkInput(): bool
        {
            return true;
        }
    }

    class ilSelectInputGUI extends ilFormPropertyGUI
    {
        /** @var array<string, string> */
        public array $options = [];

        public function setOptions(array $options): void
        {
            $this->options = $options;
        }
    }

    class ilRadioOption
    {
        public string $title;
        public string $value;
        public string $info;
        public bool $disabled = false;

        public function __construct(string $title = '', string $value = '', string $info = '')
        {
            $this->title = $title;
            $this->value = $value;
            $this->info = $info;
        }

        public function setDisabled(bool $disabled): void
        {
            $this->disabled = $disabled;
        }

        public function setInfo(string $info): void
        {
            $this->info = $info;
        }
    }

    class ilRadioGroupInputGUI extends ilFormPropertyGUI
    {
        /** @var ilRadioOption[] */
        public array $options = [];

        public function addOption(ilRadioOption $option): void
        {
            $this->options[] = $option;
        }

        public function render(): string
        {
            $html = '<div class="item" data-postvar="' . htmlspecialchars($this->postvar) . '"><label>' . $this->title . '</label>';
            foreach ($this->options as $option) {
                $html .= '<span class="option" data-value="' . htmlspecialchars($option->value) . '"'
                    . ((string) $this->value === $option->value ? ' data-checked' : '') . ($option->disabled ? ' data-disabled' : '') . '>'
                    . $option->title . ' <span class="info">' . $option->info . '</span></span>';
            }
            return $html . ($this->info !== '' ? '<span class="info">' . $this->info . '</span>' : '')
                . ($this->alert !== null ? '<span class="alert">' . $this->alert . '</span>' : '') . '</div>';
        }
    }

    class ilCheckboxOption
    {
        public string $title;
        public string $value;

        public function __construct(string $title = '', string $value = '')
        {
            $this->title = $title;
            $this->value = $value;
        }
    }

    class ilCheckboxGroupInputGUI extends ilFormPropertyGUI
    {
        public function addOption(ilCheckboxOption $option): void
        {
        }

        public function getInput()
        {
            return (array) ($_POST[$this->postvar] ?? []);
        }
    }

    class ilNonEditableValueGUI extends ilFormPropertyGUI
    {
        public function __construct(string $title = '', string $postvar = '', bool $escape = false)
        {
            parent::__construct($title, $postvar);
        }

        public function checkInput(): bool
        {
            return true;
        }

        public function render(): string
        {
            return '<div class="item" data-postvar="' . htmlspecialchars($this->postvar) . '"><label>' . $this->title . '</label>'
                . '<span class="value">' . (string) $this->value . '</span></div>';
        }
    }

    class ilImageFileInputGUI extends ilFormPropertyGUI
    {
        public string $image = '';

        public function setSuffixes(array $suffixes): void
        {
        }

        public function setAllowDeletion(bool $allow): void
        {
        }

        public function setImage(string $image): void
        {
            $this->image = $image;
        }

        public function getDeletionFlag(): bool
        {
            return !empty($_POST[$this->postvar . '_delete']);
        }

        public function checkInput(): bool
        {
            return true;
        }
    }

    class ilFormSectionHeaderGUI
    {
        public string $title = '';
        public string $info = '';

        public function setTitle(string $title): void
        {
            $this->title = $title;
        }

        public function setInfo(string $info): void
        {
            $this->info = $info;
        }

        public function render(): string
        {
            return '<h3>' . $this->title . '</h3>' . ($this->info !== '' ? '<p class="info">' . $this->info . '</p>' : '');
        }
    }

    class ilPropertyFormGUI
    {
        /** @var array<int, ilFormPropertyGUI|ilFormSectionHeaderGUI> */
        public array $items = [];
        public string $title = '';
        public string $action = '';
        /** @var array<string, string> */
        public array $buttons = [];

        public function setFormAction(string $action): void
        {
            $this->action = $action;
        }

        public function setTitle(string $title): void
        {
            $this->title = $title;
        }

        public function addItem($item): void
        {
            $this->items[] = $item;
        }

        public function addCommandButton(string $cmd, string $label): void
        {
            $this->buttons[$cmd] = $label;
        }

        public function getItemByPostVar(string $postvar): ?ilFormPropertyGUI
        {
            foreach ($this->items as $item) {
                if ($item instanceof ilFormPropertyGUI && $item->getPostVar() === $postvar) {
                    return $item;
                }
            }
            return null;
        }

        public function checkInput(): bool
        {
            $ok = true;
            foreach ($this->items as $item) {
                if ($item instanceof ilFormPropertyGUI && !$item->checkInput()) {
                    $ok = false;
                }
            }
            return $ok;
        }

        /**
         * @return mixed
         */
        public function getInput(string $postvar)
        {
            $item = $this->getItemByPostVar($postvar);
            if ($item === null) {
                throw new \LogicException('The form has no item ' . $postvar);
            }
            return $item->getInput();
        }

        public function setValuesByPost(): void
        {
            foreach ($this->items as $item) {
                if ($item instanceof ilFormPropertyGUI && !($item instanceof ilNonEditableValueGUI)) {
                    $item->setValue($item->getInput());
                }
            }
        }

        public function setValuesByArray(array $values): void
        {
            foreach ($this->items as $item) {
                if ($item instanceof ilFormPropertyGUI && array_key_exists($item->getPostVar(), $values)) {
                    $item->setValue($values[$item->getPostVar()]);
                }
            }
        }

        public function getHTML(): string
        {
            $html = '<form action="' . htmlspecialchars($this->action) . '"><h2>' . $this->title . '</h2>';
            foreach ($this->items as $item) {
                $html .= $item->render();
            }
            foreach ($this->buttons as $cmd => $label) {
                $html .= '<button name="cmd[' . $cmd . ']">' . $label . '</button>';
            }
            return $html . '</form>';
        }
    }

    class ilConfirmationGUI
    {
        public string $header = '';
        /** @var array<string, string> */
        public array $hidden = [];
        public string $confirm = '';
        public string $cancel = '';

        public function setFormAction(string $action): void
        {
        }

        public function setHeaderText(string $text): void
        {
            $this->header = $text;
        }

        public function setConfirm(string $label, string $cmd): void
        {
            $this->confirm = $cmd;
        }

        public function setCancel(string $label, string $cmd): void
        {
            $this->cancel = $cmd;
        }

        public function addHiddenItem(string $name, string $value): void
        {
            $this->hidden[$name] = $value;
        }

        public function getHTML(): string
        {
            $html = '<form class="confirmation"><p>' . $this->header . '</p>';
            foreach ($this->hidden as $name => $value) {
                $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
            }
            return $html . '</form>';
        }
    }
}
