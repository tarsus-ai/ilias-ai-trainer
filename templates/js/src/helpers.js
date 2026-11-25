/**
 * Tarsus for ILIAS - Small helpers: building elements, texts with placeholders.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

import { typeset } from './markdown.js';

export const FEED_SCROLL_THRESHOLD = 80;

// ------------------------------------------------------------------
// Small helpers
// ------------------------------------------------------------------

export function noop() {
}

/**
 * Create an element.
 *
 * @param {string} tag
 * @param {Object} [attrs] Attributes; 'class', 'text', 'html' and 'data-*' are understood.
 * @param {Array|Node|string} [children]
 * @return {Element}
 */
export function el(tag, attrs, children) {
  const node = document.createElement(tag);
  const given = attrs || {};
  Object.keys(given).forEach((key) => {
    const value = given[key];
    if (value === null || value === undefined || value === false) {
      return;
    }
    if (key === 'text') {
      node.textContent = value;
    } else if (key === 'html') {
      node.innerHTML = value;
      typeset(node);
    } else if (key === 'class') {
      node.className = value;
    } else if (value === true) {
      node.setAttribute(key, '');
    } else {
      node.setAttribute(key, String(value));
    }
  });
  append(node, children);
  return node;
}

function append(node, children) {
  if (children === undefined || children === null || children === false) {
    return;
  }
  if (Array.isArray(children)) {
    children.forEach((child) => {
      append(node, child);
    });
  } else if (typeof children === 'string') {
    node.appendChild(document.createTextNode(children));
  } else if (children.nodeType) {
    node.appendChild(children);
  }
}

export function escapeHtml(text) {
  return String(text === undefined || text === null ? '' : text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

export function format(template, params) {
  let text = String(template || '');
  Object.keys(params || {}).forEach((key) => {
    text = text.split(`{${key}}`).join(String(params[key]));
  });
  return text;
}

export function show(node, visible) {
  if (node) {
    node.classList.toggle('tarsus-hidden', !visible);
  }
}

/**
 * A short text Tarsus writes in Markdown (an answer option), rendered
 * as an inline element with the given class.
 */
export function inlineMarkdown(className, text) {
  const span = el('span', { class: `${className} tarsus-md tarsus-md--inline`, text });
  typeset(span);
  return span;
}
