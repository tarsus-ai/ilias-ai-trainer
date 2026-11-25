/**
 * Tarsus for ILIAS - The element that scrolls the page.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

/**
 * The element that scrolls the page: ILIAS scrolls its content column,
 * older skins the window.
 */
export default class Scroller {
  constructor(anchor) {
    this.anchor = anchor;
    this.element = null;
    this.detect();
  }

  detect() {
    let parent = this.anchor.parentElement;
    while (parent && parent !== document.body && parent !== document.documentElement) {
      const style = window.getComputedStyle(parent);
      if (/(auto|scroll)/.test(style.overflowY)) {
        this.element = parent;
        return parent;
      }
      parent = parent.parentElement;
    }
    this.element = null;
    return null;
  }

  target() {
    return this.element || this.detect();
  }

  top() {
    const target = this.target();
    return target ? target.scrollTop : window.pageYOffset;
  }

  scrollTo(y) {
    const target = this.target();
    if (target) {
      target.scrollTop = y;
    } else {
      window.scrollTo(0, y);
    }
  }

  scrollBy(delta) {
    this.scrollTo(this.top() + delta);
  }

  viewportHeight() {
    const target = this.target();
    return target ? target.clientHeight : window.innerHeight;
  }

  viewportTop() {
    const target = this.target();
    return target ? target.getBoundingClientRect().top : 0;
  }

  scrollHeight() {
    const target = this.target();
    return target ? target.scrollHeight : document.documentElement.scrollHeight;
  }

  /** Position of an element's top edge in scroll coordinates. */
  offsetOf(node) {
    return node.getBoundingClientRect().top - this.viewportTop() + this.top();
  }

  listen(handler) {
    const target = this.target();
    (target || window).addEventListener('scroll', handler, { passive: true });
  }
}
