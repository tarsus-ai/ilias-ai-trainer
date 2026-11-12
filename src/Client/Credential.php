<?php

namespace TarsusIlias\Client;

use TarsusIlias\Support\Config;

/**
 * How a request tells Tarsus whose brain it is for.
 *
 * Every call carries the partner's API key. Beside it travels either a
 * brain ID - a customer of the partner, billed through the partner - or a
 * License-Key: existing Tarsus customers have one instead of a brain ID,
 * and Tarsus bills them directly. In License-Key mode the key is sent in
 * place of brain_id, never in addition to it and never replaced by a
 * brain ID when it is missing: falling back would silently bill the
 * customer's usage to the wrong party.
 *
 * The form field the License-Key travels in is configurable (license_key
 * unless Tarsus names another), so the mode can be switched on without a
 * new release once the API accepts it.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Credential
{
    /** The customer is identified by a brain ID. */
    public const BRAIN = 'brain';
    /** The customer is identified by a License-Key. */
    public const LICENSE = 'license';

    /** The form field of the brain ID. */
    public const BRAIN_FIELD = 'brain_id';
    /** The form field of the License-Key unless configured otherwise. */
    public const DEFAULT_LICENSE_FIELD = 'license_key';

    /**
     * Form fields the client sends for other purposes. The License-Key may
     * not travel under one of these names: it would overwrite the field.
     *
     * @var string[]
     */
    public const RESERVED_FIELDS = [
        'api_key', 'context_id', 'context_type', 'context_name', 'context_description',
        'robot_id', 'robot_name', 'robot_description', 'default_robot',
        'user_id', 'user_name', 'user_language', 'state', 'input_modality',
        'conversation_style', 'dqr', 'full_text_search', 'full_brain_access', 'operators',
        'anti_cheat', 'learn_tempo', 'interaction_type', 'resource_ids', 'query', 'industry',
        'reply_to_widget', 'taxonomy_levels', 'question_types', 'learning_method', 'gif',
        'widget_id', 'user_answer', 'user_answers', 'voice', 'stack_id',
        'offset', 'page_size', 'newest_first', 'asset_ids', 'start_timestamp', 'end_timestamp',
        'resource_id', 'asset_id', 'url', 'text', 'title', 'document',
        'audio_content', 'video_content', 'think_longer',
    ];

    private string $type;
    private string $field;
    private string $value;

    private function __construct(string $type, string $field, string $value)
    {
        $this->type = $type;
        $this->field = $field;
        $this->value = trim($value);
    }

    public static function brain(string $brainid): self
    {
        return new self(self::BRAIN, self::BRAIN_FIELD, $brainid);
    }

    /**
     * @param string $field The form field to send the key in; an unusable
     *                      name falls back to license_key.
     */
    public static function license(string $key, string $field = self::DEFAULT_LICENSE_FIELD): self
    {
        return new self(self::LICENSE, self::validField($field) ? $field : self::DEFAULT_LICENSE_FIELD, $key);
    }

    /**
     * The credential the plugin configuration describes.
     */
    public static function fromConfig(): self
    {
        return Config::usesLicenseKey()
            ? self::license(Config::licenseKey(), Config::licenseField())
            : self::brain(Config::brainId());
    }

    /**
     * Whether a name can carry the License-Key: a plain form field name
     * that no other field of a request uses.
     */
    public static function validField(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}\z/', $name)
            && !in_array(strtolower($name), self::RESERVED_FIELDS, true);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isLicense(): bool
    {
        return $this->type === self::LICENSE;
    }

    /**
     * The name of the form field the credential travels in.
     */
    public function field(): string
    {
        return $this->field;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * The form field identifying the customer, or fail loudly when the
     * brain ID or the License-Key is missing.
     *
     * @return array<string, string>
     * @throws ConfigurationException
     */
    public function fields(): array
    {
        if ($this->value === '') {
            throw new ConfigurationException($this->isLicense() ? 'err_nolicensekey' : 'err_nobrainselected');
        }
        return [$this->field => $this->value];
    }
}
