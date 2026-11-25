<?php

namespace TarsusIlias\Client;

/**
 * Tarsus answered HTTP 409 with {"replay": {"widget": {...}}}. In Build
 * knowledge it then sends a widget again instead of a new one: the
 * course-complete card once the learning path is finished, or the learn
 * question that is still unanswered. That is no error and costs nothing;
 * the widget is shown like any other.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class ReplayException extends \TarsusIlias\TarsusException
{
    /** @var array<string, mixed> The widget Tarsus sent again. */
    public array $widget;

    /**
     * @param array<string, mixed> $widget
     */
    public function __construct(array $widget)
    {
        $this->widget = $widget;
        parent::__construct('Tarsus sent a widget again (HTTP 409)', 409);
    }
}
