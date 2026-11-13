<?php

namespace TarsusIlias\Output;

use TarsusIlias\Support\Ilias;

/**
 * Formula support across ILIAS releases.
 *
 * ILIAS 9 and 10 have the MathJax service (ilMathJax): formulas between
 * [tex] and [/tex] are converted to whatever the administration configured
 * - client-side MathJax with its delimiters, or server-rendered images.
 * ILIAS 11 bundles MathJax with the UI framework, configured for [tex]
 * delimiters inside elements with the class c-legacy__content--latex,
 * which is what the Markdown renderer emits.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
final class MathSupport
{
    /**
     * Prepare rendered HTML for the installed MathJax set-up.
     */
    public static function prepare(string $html): string
    {
        if (strpos($html, '[tex]') === false || !class_exists('ilMathJax')) {
            return $html;
        }
        try {
            return \ilMathJax::getInstance()->insertLatexImages($html);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * Make sure MathJax is loaded on the current page.
     */
    public static function includeOnPage(): void
    {
        try {
            if (class_exists('ilMathJax')) {
                \ilMathJax::getInstance()->includeMathJax(Ilias::dic()->ui()->mainTemplate());
                return;
            }
            $factory = Ilias::dic()->ui()->factory();
            $legacy = new \ReflectionMethod($factory, 'legacy');
            if ($legacy->getNumberOfRequiredParameters() === 0) {
                // ILIAS 11+: rendering a latex component registers MathJax.
                $component = $factory->legacy()->latexContent('');
                Ilias::dic()->ui()->renderer()->render($component);
            }
        } catch (\Throwable $e) {
            // Formulas then show as TeX source.
        }
    }
}
