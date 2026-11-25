/**
 * Tarsus for ILIAS - Tarsus's texts: Markdown rendered the way the Tarsus app renders it.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

// ------------------------------------------------------------------
// Tarsus's texts: Markdown rendered the way the Tarsus app renders it
// ------------------------------------------------------------------

/**
 * Render the Markdown texts in and under a node. The server sends each
 * text escaped in a .tarsus-md element; tarsus-markdown.min.js turns it
 * into HTML with React-Markdown's pipeline (GitHub tables, KaTeX
 * formulas), so it looks as it does in the Tarsus app. Without that
 * script a text stays readable as plain text.
 */
export function typeset(node) {
  const renderer = window.TarsusMarkdown;
  if (!node || !node.querySelectorAll || !renderer || typeof renderer.render !== 'function') {
    return;
  }
  const pending = Array.prototype.slice.call(node.querySelectorAll('.tarsus-md:not(.tarsus-md--rendered)'));
  if (node.classList && node.classList.contains('tarsus-md') && !node.classList.contains('tarsus-md--rendered')) {
    pending.unshift(node);
  }
  pending.forEach((holder) => {
    renderMarkdown(holder, renderer);
  });
}

function renderMarkdown(holder, renderer) {
  let html;
  try {
    html = renderer.render(holder.textContent);
  } catch (e) {
    return;
  }
  holder.innerHTML = html;
  holder.classList.add('tarsus-md--rendered');
  if (holder.classList.contains('tarsus-md--inline')) {
    unwrapParagraph(holder);
  }
}

/** A short text of one paragraph (an answer option) reads inline, without the paragraph. */
function unwrapParagraph(holder) {
  const parts = Array.prototype.filter.call(holder.childNodes, (child) => child.nodeType === 1 || (child.nodeType === 3 && child.nodeValue.trim() !== ''));
  if (parts.length !== 1 || parts[0].nodeType !== 1 || parts[0].tagName !== 'P') {
    return;
  }
  const paragraph = parts[0];
  while (paragraph.firstChild) {
    holder.insertBefore(paragraph.firstChild, paragraph);
  }
  holder.removeChild(paragraph);
}

/**
 * The text of rendered Markdown as a reader would quote it: a formula
 * as its TeX rather than KaTeX's two copies of it (MathML and HTML).
 */
export function readableText(node) {
  if (!node) {
    return '';
  }
  const copy = node.cloneNode(true);
  Array.prototype.forEach.call(copy.querySelectorAll('.tarsus-sr-only'), (hidden) => {
    hidden.parentNode.removeChild(hidden);
  });
  Array.prototype.forEach.call(copy.querySelectorAll('td, th'), (cell) => {
    cell.appendChild(document.createTextNode(' '));
  });
  Array.prototype.forEach.call(copy.querySelectorAll('p, li, tr, h1, h2, h3, h4, h5, h6, pre, blockquote, label, br'), (block) => {
    block.parentNode.insertBefore(document.createTextNode('\n'), block.nextSibling);
  });
  Array.prototype.forEach.call(copy.querySelectorAll('.katex'), (math) => {
    const tex = math.querySelector('annotation[encoding="application/x-tex"]');
    if (math.parentNode) {
      math.parentNode.replaceChild(document.createTextNode(tex ? tex.textContent : ''), math);
    }
  });
  return copy.textContent;
}
