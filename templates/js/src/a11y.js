/**
 * Tarsus for ILIAS - Accessibility: what screen readers hear, where keyboard focus goes.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { el } from './helpers.js';

// ------------------------------------------------------------------
// Accessibility: what screen readers hear, where keyboard focus goes
// ------------------------------------------------------------------

let uid = 0;

/** A fresh element id. */
export function nextId(prefix) {
  uid += 1;
  return prefix + uid;
}

/**
 * Move keyboard focus to a node that does not take it by itself (a
 * question, a feedback box, a heading): screen readers then read it.
 */
export function focusOn(node) {
  if (!node || typeof node.focus !== 'function') {
    return;
  }
  if (!node.hasAttribute('tabindex') && !node.matches('a[href], button, input, select, textarea, iframe')) {
    node.setAttribute('tabindex', '-1');
  }
  node.focus({ preventScroll: true });
}

/** Text only screen readers read. */
export function srText(text) {
  return el('span', { class: 'tarsus-sr-only', text });
}

/** What keyboard focus can reach inside a container, in order. */
function tabbables(container) {
  return Array.prototype.filter.call(container.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]),'
        + ' iframe, audio[controls], video[controls], [tabindex]:not([tabindex="-1"])',
  ), (node) => node.getClientRects().length > 0);
}

/** Keep Tab and Shift+Tab inside a dialog. */
export function trapTab(container, event) {
  if (!container || event.key !== 'Tab') {
    return;
  }
  const nodes = tabbables(container);
  if (!nodes.length) {
    event.preventDefault();
    focusOn(container);
    return;
  }
  const first = nodes[0];
  const last = nodes[nodes.length - 1];
  const active = document.activeElement;
  if (event.shiftKey && (active === first || active === container || !container.contains(active))) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && (active === last || !container.contains(active))) {
    event.preventDefault();
    first.focus();
  }
}
