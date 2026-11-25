/**
 * Tarsus for ILIAS - Names for screen readers that ILIAS 9's UI framework leaves out.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { nextId } from './a11y.js';

/**
 * On the plugin's pages ILIAS 9 shows two controls without a name for
 * screen readers: the options of a multiple choice (ILIAS 10 wraps each
 * in a label) and the confirmation dialog (ILIAS 10 names it by its
 * title). Name them the same way; controls that have a name keep it.
 *
 * @param {Document|Element} scope
 */
export default function nameUnnamedControls(scope) {
  scope.querySelectorAll('ul.il-input-multiselect li').forEach((item) => {
    const input = item.querySelector('input[type="checkbox"]');
    const text = item.querySelector('span');
    if (!input || !text || input.closest('label') || input.hasAttribute('aria-labelledby') || input.hasAttribute('aria-label')) {
      return;
    }
    if (!text.id) {
      text.id = nextId('tarsus-option-');
    }
    input.setAttribute('aria-labelledby', text.id);
  });
  scope.querySelectorAll('.c-modal--interruptive[role="dialog"]').forEach((modal) => {
    const title = modal.querySelector('.modal-title');
    if (!title || modal.hasAttribute('aria-labelledby') || modal.hasAttribute('aria-label')) {
      return;
    }
    if (!title.id) {
      title.id = nextId('tarsus-dialog-title-');
    }
    modal.setAttribute('aria-labelledby', title.id);
  });
}
