/**
 * Tarsus for ILIAS - builds the coach's script, dist/tarsus.js, from the
 * ES6 modules in src/: run "npm install" once and "npm run build" in
 * this folder.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

const banner = `/**
 * Tarsus for ILIAS - browser side.
 *
 * Two screens share this file: the coach (one interleaved feed of
 * exchange, question and learning-unit cards; four modes - Ask a question,
 * typed or spoken, Test knowledge, Build knowledge and the Live
 * interaction; a panel sliding in from the right for the sources of an
 * answer and, for teachers, the settings, the evaluation and the
 * learners' history) and the teacher's material list (index actions,
 * inclusion switches and the page sync). Everything shown comes from what
 * Tarsus returns; nothing about its behaviour is assumed here. Both boot
 * from the JSON configuration the server puts on their root element. No
 * framework; Tarsus's texts are rendered like in the Tarsus app
 * (React-Markdown with KaTeX formulas) by tarsus-markdown.min.js. Screen
 * readers hear what happens (a live region, headings on every card, words
 * for colours) and everything works with the keyboard alone.
 *
 * Built with Rollup from the ES6 modules in templates/js/src ("npm run
 * build" in templates/js); change those, not this file.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */`;

export default {
  input: './src/index.js',
  output: {
    file: './dist/tarsus.js',
    format: 'iife',
    banner,
  },
};
