/**
 * Tarsus Markdown for the LMS plugins: the brain's texts shown the way the
 * Tarsus app shows them with React-Markdown - CommonMark plus GitHub tables,
 * strikethrough, task lists and footnotes (remark-gfm) and formulas with
 * KaTeX (remark-math, rehype-katex, mhchem for chemistry).
 *
 * Safe to insert as-is, by React-Markdown's own rules: raw HTML in the text
 * is shown as text, and an address with any protocol but http(s), mailto,
 * irc(s) and xmpp is emptied. On top: links open in a new tab, an image
 * without an allowed address shows its alt text, and tables scroll
 * sideways on narrow screens.
 *
 * Built into the plugin's tarsus-markdown.min.js (npm run build).
 *
 * @license GPL-3.0-or-later (this file); the bundled libraries are MIT.
 */
import {unified} from 'unified';
import remarkParse from 'remark-parse';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';
import remarkRehype from 'remark-rehype';
import rehypeKatex from 'rehype-katex';
import rehypeStringify from 'rehype-stringify';
import 'katex/contrib/mhchem';

/** The protocols React-Markdown allows in addresses. */
const SAFE_PROTOCOLS = ['http', 'https', 'irc', 'ircs', 'mailto', 'xmpp'];

/** Properties that hold an address (React-Markdown checks the same ones). */
const URL_PROPERTIES = ['href', 'src', 'cite', 'action', 'formAction', 'poster', 'data', 'icon', 'manifest', 'ping', 'itemId'];

/**
 * React-Markdown's defaultUrlTransform: relative addresses and the safe
 * protocols stay, anything else (javascript:, data:, ...) becomes ''.
 */
function safeUrl(value) {
  const url = String(value);
  const colon = url.indexOf(':');
  const questionMark = url.indexOf('?');
  const numberSign = url.indexOf('#');
  const slash = url.indexOf('/');
  if (colon === -1
      || (slash !== -1 && colon > slash)
      || (questionMark !== -1 && colon > questionMark)
      || (numberSign !== -1 && colon > numberSign)
      || SAFE_PROTOCOLS.indexOf(url.slice(0, colon).toLowerCase()) !== -1) {
    return url;
  }
  return '';
}

/** The alt text of an image as a text node. */
function altText(image) {
  const alt = image.properties && image.properties.alt;
  return {type: 'text', value: alt === undefined || alt === null ? '' : String(alt)};
}

/** Elements whose whitespace-only text React leaves out. */
const TABLE_ELEMENTS = ['table', 'tbody', 'thead', 'tfoot', 'tr'];

/** Whether a text is empty or only spaces, tabs and line breaks. */
function blank(value) {
  for (let i = 0; i < value.length; i++) {
    if (' \t\n\f\r'.indexOf(value.charAt(i)) === -1) {
      return false;
    }
  }
  return true;
}

/** Whether a property is a data-* attribute (dataFootnoteRef, ...). */
function isDataProperty(name) {
  const fifth = name.charAt(4);
  return name.length > 4 && name.slice(0, 4) === 'data' && fifth !== fifth.toLowerCase();
}

/**
 * What React does when it renders the tree: a table cell's alignment
 * becomes its text-align style, whitespace between table rows and cells
 * goes, and a data-* attribute that is just "on" reads "true".
 */
function asReactRenders(element) {
  const props = element.properties;
  if ((element.tagName === 'td' || element.tagName === 'th') && typeof props.align === 'string') {
    const style = typeof props.style === 'string' && props.style !== '' ? props.style + ';' : '';
    props.style = style + 'text-align:' + props.align;
    delete props.align;
  }
  if (TABLE_ELEMENTS.indexOf(element.tagName) !== -1) {
    element.children = element.children.filter((child) => child.type !== 'text' || !blank(child.value));
  }
  for (const name of Object.keys(props)) {
    if (props[name] === true && isDataProperty(name)) {
      props[name] = 'true';
    }
  }
}

/** React-Markdown's safety rules plus the plugins' touches, on the HTML tree. */
function rehypeTarsus() {
  const visit = (node) => {
    const children = node.children || [];
    for (let i = 0; i < children.length; i++) {
      let child = children[i];
      if (child.type === 'raw') {
        // HTML written in the text: shown as text, as React-Markdown does.
        children[i] = {type: 'text', value: child.value};
        continue;
      }
      if (child.type !== 'element') {
        continue;
      }
      const props = child.properties || (child.properties = {});
      child.children = child.children || [];
      asReactRenders(child);
      for (const name of URL_PROPERTIES) {
        if (Object.prototype.hasOwnProperty.call(props, name) && props[name] !== null && props[name] !== undefined) {
          props[name] = safeUrl(Array.isArray(props[name]) ? props[name].join(' ') : props[name]);
        }
      }
      if (child.tagName === 'img' && props.src === '') {
        children[i] = altText(child);
        continue;
      }
      if (child.tagName === 'a' && typeof props.href === 'string' && props.href !== '' && props.href.charAt(0) !== '#') {
        props.target = '_blank';
        props.rel = ['noopener', 'noreferrer'];
      }
      if (child.tagName === 'table') {
        child = {type: 'element', tagName: 'div', properties: {className: ['tarsus-table-wrap']}, children: [child]};
        children[i] = child;
        visit(child.children[0]);
        continue;
      }
      visit(child);
    }
  };
  return (tree) => {
    visit(tree);
  };
}

const processor = unified()
  .use(remarkParse)
  .use(remarkGfm)
  .use(remarkMath)
  // Keep HTML in the text so it can be shown as text, as React-Markdown does.
  .use(remarkRehype, {allowDangerousHtml: true})
  // Same rendering as KaTeX's default strict mode, without its console notes.
  .use(rehypeKatex, {strict: 'ignore'})
  .use(rehypeTarsus)
  .use(rehypeStringify)
  .freeze();

window.TarsusMarkdown = {
  /** HTML of a Markdown text; '' for an empty one. */
  render(markdown) {
    return String(processor.processSync(String(markdown === undefined || markdown === null ? '' : markdown)));
  },
};
