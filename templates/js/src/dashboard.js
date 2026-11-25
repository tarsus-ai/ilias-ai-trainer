/**
 * Tarsus for ILIAS - The teacher's material list.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { format } from './helpers.js';
import { post } from './net.js';

// ------------------------------------------------------------------
// Teacher's material list
// ------------------------------------------------------------------

const BADGE_CLASSES = ['tarsus-badge--neutral', 'tarsus-badge--warning', 'tarsus-badge--muted', 'tarsus-badge--success', 'tarsus-badge--danger', 'tarsus-badge--info'];

export default class Dashboard {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.strings = config.strings || {};
  }

  t(key, params) {
    return format(this.strings[key] || key, params);
  }

  call(endpoint, payload) {
    return post(this.config.endpoints[endpoint], payload, this.strings);
  }

  rows() {
    return Array.prototype.slice.call(this.root.querySelectorAll('[data-region="resource"]'));
  }

  message(text, isError) {
    const box = this.root.querySelector('[data-region="bulk-message"]');
    if (!box) {
      return;
    }
    box.textContent = text || '';
    box.classList.toggle('tarsus-dashboard__message--error', Boolean(isError));
  }

  static updateRow(row, data) {
    if (!row || !data) {
      return;
    }
    const badge = row.querySelector('[data-region="status-badge"]');
    if (badge) {
      badge.textContent = data.statuslabel;
      BADGE_CLASSES.forEach((cls) => {
        badge.classList.remove(cls);
      });
      String(data.badgeclass || '').split(/\s+/).forEach((cls) => {
        if (cls) {
          badge.classList.add(cls);
        }
      });
    }
    const spinner = row.querySelector('[data-region="status-spinner"]');
    if (spinner) {
      spinner.hidden = !data.inflight;
    }
    const map = {
      index: data.canindex, reindex: data.canreindex, remove: data.canremove, retry: data.canretry,
    };
    row.querySelectorAll('[data-action]').forEach((button) => {
      const action = button.getAttribute('data-action');
      button.hidden = !map[action];
      button.disabled = false;
    });
    // The inclusion switch is only live for content the brain holds;
    // it keeps showing the teacher's choice meanwhile.
    const toggle = row.querySelector('[data-region="include-toggle"]');
    if (toggle) {
      toggle.disabled = !data.caninclude;
      if (typeof data.included === 'boolean') {
        toggle.checked = data.included;
      }
    }
  }

  sendAction(row, resourceid, action) {
    const self = this;
    row.querySelectorAll('[data-action]').forEach((button) => {
      button.disabled = true;
    });
    const spinner = row.querySelector('[data-region="status-spinner"]');
    if (spinner) {
      spinner.hidden = false;
    }
    return this.call('action', { resourceid, action }).then((data) => {
      Dashboard.updateRow(row, data.row);
      if (!data.ok) {
        self.message(data.error || self.t('unexpected_error'), true);
      } else {
        self.message('');
      }
      if (data.row && data.row.inflight) {
        self.scheduleConfirmation();
      }
      return data;
    }).catch((error) => {
      row.querySelectorAll('[data-action]').forEach((button) => {
        button.disabled = false;
      });
      if (spinner) {
        spinner.hidden = true;
      }
      self.message(error.message || self.t('unexpected_error'), true);
      return null;
    });
  }

  sendInclusion(row, checkbox) {
    const self = this;
    const resourceid = row.getAttribute('data-resourceid');
    const included = checkbox.checked;
    checkbox.disabled = true;
    return this.call('include', { resourceid, included }).then((data) => {
      checkbox.disabled = false;
      if (!data.ok) {
        checkbox.checked = !included;
        self.message(data.error || self.t('unexpected_error'), true);
      } else {
        self.message('');
        Dashboard.updateRow(row, data.row);
      }
      return data;
    }).catch((error) => {
      checkbox.checked = !included;
      checkbox.disabled = false;
      self.message(error.message || self.t('unexpected_error'), true);
      return null;
    });
  }

  setBulkBusy(busy) {
    this.root.querySelectorAll('[data-bulk]').forEach((button) => {
      button.disabled = busy;
    });
    const spinner = this.root.querySelector('[data-region="bulk-spinner"]');
    if (spinner) {
      spinner.hidden = !busy;
    }
  }

  /** Run one request per row, in sequence, so each stays short. */
  runQueue(rows, task, then) {
    const self = this;
    this.setBulkBusy(true);
    const queue = rows.slice();
    const next = function () {
      if (!queue.length) {
        self.setBulkBusy(false);
        self.message(self.t('bulk_done'));
        if (then) {
          then();
        }
        return Promise.resolve();
      }
      return task(queue.shift()).then(next, next);
    };
    return next();
  }

  /**
   * Rows still awaiting the brain's confirmation are asked about once
   * more a moment later, so a quick confirmation shows without a reload.
   */
  scheduleConfirmation() {
    const self = this;
    if (this.confirmTimer) {
      window.clearTimeout(this.confirmTimer);
    }
    this.confirmTimer = window.setTimeout(() => {
      self.confirmTimer = null;
      const pending = self.rows().some((row) => {
        const spinner = row.querySelector('[data-region="status-spinner"]');
        return spinner && !spinner.hidden;
      });
      if (pending) {
        self.bulkSync(true);
      }
    }, 1500);
  }

  bulkIndex() {
    const self = this;
    const rows = this.rows().filter((row) => {
      const button = row.querySelector('[data-action="index"]');
      return button && !button.hidden;
    });
    if (!rows.length) {
      return;
    }
    this.runQueue(rows, (row) => self.sendAction(row, row.getAttribute('data-resourceid'), 'index'), () => {
      self.scheduleConfirmation();
    });
  }

  bulkInclude() {
    const self = this;
    const rows = this.rows().filter((row) => {
      const toggle = row.querySelector('[data-region="include-toggle"]');
      return toggle && !toggle.disabled && !toggle.checked;
    });
    if (!rows.length) {
      return;
    }
    this.runQueue(rows, (row) => {
      const toggle = row.querySelector('[data-region="include-toggle"]');
      toggle.checked = true;
      return self.sendInclusion(row, toggle);
    });
  }

  bulkSync(silent) {
    const self = this;
    const ids = this.rows().map((row) => row.getAttribute('data-resourceid'));
    if (!ids.length) {
      return;
    }
    this.setBulkBusy(true);
    this.call('sync', { resourceids: ids.slice(0, this.config.batchLimit || 10) }).then((data) => {
      (data.rows || []).forEach((entry) => {
        const row = self.root.querySelector(`[data-region="resource"][data-resourceid="${window.CSS.escape(entry.resourceid)}"]`);
        Dashboard.updateRow(row, entry);
      });
      self.setBulkBusy(false);
      if (!data.ok) {
        if (!silent) {
          self.message(data.error || self.t('unexpected_error'), true);
        }
      } else if (!silent) {
        self.message(self.t('sync_done'));
      }
    }).catch((error) => {
      self.setBulkBusy(false);
      if (!silent) {
        self.message(error.message || self.t('unexpected_error'), true);
      }
    });
  }

  init() {
    const self = this;
    this.root.addEventListener('click', (event) => {
      const { target } = event;
      if (!(target instanceof window.Element)) {
        return;
      }
      const bulk = target.closest('[data-bulk]');
      if (bulk && !bulk.disabled) {
        const kind = bulk.getAttribute('data-bulk');
        if (kind === 'index') {
          self.bulkIndex();
        } else if (kind === 'include') {
          self.bulkInclude();
        } else {
          self.bulkSync(false);
        }
        return;
      }
      const button = target.closest('[data-action]');
      if (!button || button.disabled) {
        return;
      }
      const row = button.closest('[data-region="resource"]');
      if (!row) {
        return;
      }
      const resourceid = row.getAttribute('data-resourceid');
      const action = button.getAttribute('data-action');
      if (action === 'remove' && !window.confirm(self.t('confirmremove_body'))) {
        return;
      }
      self.sendAction(row, resourceid, action);
    });
    this.root.addEventListener('change', (event) => {
      const toggle = event.target instanceof window.Element ? event.target.closest('[data-region="include-toggle"]') : null;
      if (!toggle || toggle.disabled) {
        return;
      }
      const row = toggle.closest('[data-region="resource"]');
      if (row) {
        self.sendInclusion(row, toggle);
      }
    });
    // The listed statuses are read live on open, one call per page.
    this.bulkSync(true);
  }
}
