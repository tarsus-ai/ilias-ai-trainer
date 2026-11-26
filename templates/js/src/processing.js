/**
 * Tarsus for ILIAS - The processing indicator shown while Tarsus works.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

// ------------------------------------------------------------------
// Processing indicator
// ------------------------------------------------------------------

function step(text, icon, duration, spin) {
  return {
    text, icon, duration, spin,
  };
}

const ASK_STEPS = [
  step('proc_ask_classify', 'proc_diagram', 3200, 3.2),
  step('proc_ask_search', 'proc_atom', 4200, 2.6),
  step('proc_ask_media', 'proc_shapes', 4000, 3.4),
  step('proc_ask_select', 'proc_dot', 3800, 3.6),
  step('proc_ask_cube', 'proc_cube', 3600, 3.2),
  step('proc_ask_dqr', 'proc_gear', 3400, 4),
  step('proc_ask_compose', 'proc_sparkle', 5500, 2.4),
  step('proc_ask_verify', 'proc_notch', 4500, 3.8),
  step('proc_ask_polish', 'proc_sun', 7000, 3),
];
const PRACTICE_FIRST = [
  step('proc_practice_cube_create', 'proc_cube', 3600, 3.2),
  step('proc_practice_baseline', 'proc_chart', 3800, 3.4),
];
const PRACTICE_NEXT = [
  step('proc_practice_evaluate', 'proc_asterisk', 3200, 2.8),
  step('proc_practice_compare', 'proc_chart', 3800, 3.4),
  step('proc_practice_surprise', 'proc_gear', 3600, 4),
  step('proc_practice_reestimate', 'proc_atom', 3200, 2.6),
];
const PRACTICE_STEPS = [
  step('proc_practice_align', 'proc_cube', 4000, 3.2),
  step('proc_practice_cells', 'proc_crosshair', 3800, 3.6),
  step('proc_practice_history', 'proc_hurricane', 3800, 4.2),
  step('proc_practice_action', 'proc_target', 3600, 4),
  step('proc_practice_taxonomy', 'proc_diagram', 3600, 3.2),
  step('proc_practice_difficulty', 'proc_gear', 3400, 4),
  step('proc_practice_format', 'proc_shapes', 3400, 3.4),
  step('proc_practice_generate', 'proc_sparkle', 5000, 2.4),
  step('proc_practice_operators', 'proc_star', 4000, 3.6),
  step('proc_practice_options', 'proc_snowflake', 4500, 2.4),
  step('proc_practice_solution', 'proc_fan', 4200, 2.8),
  step('proc_practice_polish', 'proc_sun', 7000, 3),
];
const LEARN_FIRST = [
  step('proc_learn_path_create', 'proc_hurricane', 3600, 4.2),
];
const LEARN_NEXT = [
  step('proc_learn_progress', 'proc_chart', 3200, 3.4),
  step('proc_learn_objectives', 'proc_notch', 3800, 3.8),
];
const LEARN_STEPS = [
  step('proc_learn_cells', 'proc_cube', 3800, 3.2),
  step('proc_learn_next', 'proc_crosshair', 4000, 3.6),
  step('proc_learn_passages', 'proc_atom', 4500, 2.6),
  step('proc_learn_dqr', 'proc_gear', 3600, 4),
  step('proc_learn_split', 'proc_diagram', 4000, 3.2),
  step('proc_learn_examples', 'proc_seedling', 3800, 3),
  step('proc_learn_link', 'proc_nodes', 4200, 3.4),
  step('proc_learn_check', 'proc_dot', 4200, 3.6),
  step('proc_learn_path_update', 'proc_hurricane', 5000, 2.8),
  step('proc_learn_polish', 'proc_sparkle', 7000, 2.8),
];

const VOICE_FIRST = [
  step('proc_ask_transcribe', 'proc_atom', 3500, 2.6),
];

export default function processingSequence(mode, firstRun) {
  if (mode === 'voice') {
    return VOICE_FIRST.concat(ASK_STEPS);
  }
  if (mode === 'test') {
    return (firstRun ? PRACTICE_FIRST : PRACTICE_NEXT).concat(PRACTICE_STEPS);
  }
  if (mode === 'build') {
    return (firstRun ? LEARN_FIRST : LEARN_NEXT).concat(LEARN_STEPS);
  }
  return ASK_STEPS;
}
