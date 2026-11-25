/**
 * Tarsus for ILIAS - Boot: finds the coach, the material list and the help panel on the page.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { el, show } from './helpers.js';
import Chat from './chat.js';
import Dashboard from './dashboard.js';
import nameUnnamedControls from './names.js';

// ------------------------------------------------------------------
// Boot
// ------------------------------------------------------------------

window.TarsusIlias = window.TarsusIlias || {};
const { TarsusIlias } = window;

// --- help panel -----------------------------------------------------
//
// The help button opens a panel of support links and videos
// (configured by the administrator). Videos are embedded only once the
// panel opens, so a page never loads a player the learner did not ask for.

function bootHelp(panelRoot) {
  const button = panelRoot.querySelector('[data-action="help-toggle"]');
  const panel = panelRoot.querySelector('[data-region="help-panel"]');
  if (!button || !panel || button.getAttribute('data-booted') === '1') {
    return;
  }
  button.setAttribute('data-booted', '1');
  let embedded = false;
  const setOpen = function (open) {
    show(panel, open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && !embedded) {
      embedded = true;
      panel.querySelectorAll('[data-embed]').forEach((slot) => {
        const iframe = el('iframe', {
          class: 'tarsus-help__video',
          src: slot.getAttribute('data-embed'),
          loading: 'lazy',
          allow: 'accelerometer; encrypted-media; picture-in-picture; fullscreen',
          allowfullscreen: true,
          referrerpolicy: 'strict-origin-when-cross-origin',
          title: slot.getAttribute('data-title') || '',
        });
        slot.appendChild(iframe);
      });
    }
  };
  button.addEventListener('click', () => {
    setOpen(panel.classList.contains('tarsus-hidden'));
  });
  panel.querySelectorAll('[data-action="help-close"]').forEach((close) => {
    close.addEventListener('click', () => {
      setOpen(false);
      button.focus();
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.classList.contains('tarsus-hidden')) {
      const inside = panel.contains(document.activeElement);
      setOpen(false);
      if (inside) {
        button.focus();
      }
    }
  });
}

TarsusIlias.boot = function () {
  nameUnnamedControls(document);
  document.querySelectorAll('.tarsus-help').forEach(bootHelp);
  document.querySelectorAll('.tarsus-app[data-config]').forEach((root) => {
    if (root.getAttribute('data-booted') === '1') {
      return;
    }
    root.setAttribute('data-booted', '1');
    let config;
    try {
      config = JSON.parse(root.getAttribute('data-config'));
    } catch (e) {
      return;
    }
    try {
      if (config.kind === 'chat') {
        new Chat(root, config).init();
      } else if (config.kind === 'materials') {
        new Dashboard(root, config).init();
      }
    } catch (e) {
      if (window.console) {
        window.console.error('tarsus: boot failed', e);
      }
    }
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', TarsusIlias.boot);
} else {
  TarsusIlias.boot();
}
