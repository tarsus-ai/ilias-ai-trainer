/**
 * Tarsus for ILIAS - A question with two answers in a modal dialog, in place of the browser's
 * confirm().
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { el } from './helpers.js';
import { nextId, trapTab } from './a11y.js';

/**
 * Ask a question in a modal dialog: keyboard focus starts on the
 * cancelling button, stays inside the dialog and goes back where it came
 * from; Escape cancels.
 *
 * @param {Element} root The screen the dialog belongs to (for its colours).
 * @param {Object} options text: the question; confirm and cancel: the
 *   labels of the two buttons.
 * @return {Promise<boolean>} true when the person confirmed
 */
export default function confirmDialog(root, options) {
  const opener = document.activeElement;
  const textId = nextId('tarsus-dialog-text-');
  const confirmButton = el('button', { type: 'button', class: 'tarsus-btn tarsus-btn--primary', text: options.confirm });
  const cancelButton = el('button', { type: 'button', class: 'tarsus-btn', text: options.cancel });
  const dialog = el('dialog', {
    class: 'tarsus-dialog', role: 'alertdialog', 'aria-labelledby': textId,
  }, [
    el('p', { class: 'tarsus-dialog__text', id: textId, text: options.text }),
    el('div', { class: 'tarsus-dialog__actions' }, [cancelButton, confirmButton]),
  ]);
  return new Promise((resolve) => {
    let confirmed = false;
    confirmButton.addEventListener('click', () => {
      confirmed = true;
      dialog.close();
    });
    cancelButton.addEventListener('click', () => {
      dialog.close();
    });
    dialog.addEventListener('keydown', (event) => {
      trapTab(dialog, event);
    });
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
        opener.focus();
      }
      resolve(confirmed);
    });
    root.appendChild(dialog);
    dialog.showModal();
    cancelButton.focus();
  });
}
