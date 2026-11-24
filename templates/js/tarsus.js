/**
 * Tarsus for ILIAS - browser side.
 *
 * Two screens share this file: the coach (one interleaved feed of
 * exchange, question and learning-unit cards; four modes - Ask a question,
 * typed or spoken, Test knowledge, Build knowledge and the Live
 * interaction; a panel sliding in from the right for the sources of an
 * answer and, for teachers, the settings, the evaluation and the
 * learners' history) and the teacher's material list (index actions,
 * inclusion switches and the page sync). Everything shown comes from what
 * Tarsus returns; nothing about its behaviour is assumed here. Both boot from the JSON configuration the server puts
 * on their root element. No framework; Tarsus's texts are rendered like in
 * the Tarsus app (React-Markdown with KaTeX formulas) by
 * tarsus-markdown.min.js. Screen readers hear what happens (a live
 * region, headings on every card, words for colours) and everything works
 * with the keyboard alone.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */
(function () {
    'use strict';

    var FEED_SCROLL_THRESHOLD = 80;

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    function noop() {
    }

    /**
     * Create an element.
     *
     * @param {string} tag
     * @param {Object} [attrs] Attributes; 'class', 'text', 'html' and 'data-*' are understood.
     * @param {Array|Node|string} [children]
     * @return {Element}
     */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (key) {
            var value = attrs[key];
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
            children.forEach(function (child) {
                append(node, child);
            });
        } else if (typeof children === 'string') {
            node.appendChild(document.createTextNode(children));
        } else if (children.nodeType) {
            node.appendChild(children);
        }
    }

    function escapeHtml(text) {
        return String(text === undefined || text === null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function format(template, params) {
        var text = String(template || '');
        Object.keys(params || {}).forEach(function (key) {
            text = text.split('{' + key + '}').join(String(params[key]));
        });
        return text;
    }

    function show(node, visible) {
        if (node) {
            node.classList.toggle('tarsus-hidden', !visible);
        }
    }

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
    function typeset(node) {
        var renderer = window.TarsusMarkdown;
        if (!node || !node.querySelectorAll || !renderer || typeof renderer.render !== 'function') {
            return;
        }
        var pending = Array.prototype.slice.call(node.querySelectorAll('.tarsus-md:not(.tarsus-md--rendered)'));
        if (node.classList && node.classList.contains('tarsus-md') && !node.classList.contains('tarsus-md--rendered')) {
            pending.unshift(node);
        }
        pending.forEach(function (holder) {
            renderMarkdown(holder, renderer);
        });
    }

    function renderMarkdown(holder, renderer) {
        var html;
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
        var parts = Array.prototype.filter.call(holder.childNodes, function (child) {
            return child.nodeType === 1 || (child.nodeType === 3 && child.nodeValue.trim() !== '');
        });
        if (parts.length !== 1 || parts[0].nodeType !== 1 || parts[0].tagName !== 'P') {
            return;
        }
        var paragraph = parts[0];
        while (paragraph.firstChild) {
            holder.insertBefore(paragraph.firstChild, paragraph);
        }
        holder.removeChild(paragraph);
    }

    /**
     * A short text Tarsus writes in Markdown (an answer option), rendered
     * as an inline element with the given class.
     */
    function inlineMarkdown(className, text) {
        var span = el('span', {'class': className + ' tarsus-md tarsus-md--inline', text: text});
        typeset(span);
        return span;
    }

    /**
     * The text of rendered Markdown as a reader would quote it: a formula
     * as its TeX rather than KaTeX's two copies of it (MathML and HTML).
     */
    function readableText(node) {
        if (!node) {
            return '';
        }
        var copy = node.cloneNode(true);
        Array.prototype.forEach.call(copy.querySelectorAll('.tarsus-sr-only'), function (hidden) {
            hidden.parentNode.removeChild(hidden);
        });
        Array.prototype.forEach.call(copy.querySelectorAll('td, th'), function (cell) {
            cell.appendChild(document.createTextNode(' '));
        });
        Array.prototype.forEach.call(copy.querySelectorAll('p, li, tr, h1, h2, h3, h4, h5, h6, pre, blockquote, label, br'), function (block) {
            block.parentNode.insertBefore(document.createTextNode('\n'), block.nextSibling);
        });
        Array.prototype.forEach.call(copy.querySelectorAll('.katex'), function (math) {
            var tex = math.querySelector('annotation[encoding="application/x-tex"]');
            if (math.parentNode) {
                math.parentNode.replaceChild(document.createTextNode(tex ? tex.textContent : ''), math);
            }
        });
        return copy.textContent;
    }

    // ------------------------------------------------------------------
    // Accessibility: what screen readers hear, where keyboard focus goes
    // ------------------------------------------------------------------

    var uid = 0;

    /** A fresh element id. */
    function nextId(prefix) {
        uid += 1;
        return prefix + uid;
    }

    /**
     * Move keyboard focus to a node that does not take it by itself (a
     * question, a feedback box, a heading): screen readers then read it.
     */
    function focusOn(node) {
        if (!node || typeof node.focus !== 'function') {
            return;
        }
        if (!node.hasAttribute('tabindex') && !node.matches('a[href], button, input, select, textarea, iframe')) {
            node.setAttribute('tabindex', '-1');
        }
        node.focus({preventScroll: true});
    }

    /** Text only screen readers read. */
    function srText(text) {
        return el('span', {'class': 'tarsus-sr-only', text: text});
    }

    /** What keyboard focus can reach inside a container, in order. */
    function tabbables(container) {
        return Array.prototype.filter.call(container.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]),'
            + ' iframe, audio[controls], video[controls], [tabindex]:not([tabindex="-1"])'
        ), function (node) {
            return node.getClientRects().length > 0;
        });
    }

    /** Keep Tab and Shift+Tab inside a dialog. */
    function trapTab(container, event) {
        if (!container || event.key !== 'Tab') {
            return;
        }
        var nodes = tabbables(container);
        if (!nodes.length) {
            event.preventDefault();
            focusOn(container);
            return;
        }
        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        var active = document.activeElement;
        if (event.shiftKey && (active === first || active === container || !container.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !container.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }

    /**
     * POST a JSON payload to one of the plugin's endpoints.
     *
     * @return {Promise<Object>}
     */
    function post(url, payload, strings) {
        var body = new URLSearchParams();
        body.set('payload', JSON.stringify(payload || {}));
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body.toString()
        }).then(function (response) {
            return readJson(response, strings);
        });
    }

    /**
     * POST a multipart form (a recording) to one of the plugin's endpoints.
     *
     * @return {Promise<Object>}
     */
    function postForm(url, form, strings) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
            body: form
        }).then(function (response) {
            return readJson(response, strings);
        });
    }

    function readJson(response, strings) {
        return response.text().then(function (text) {
            var data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null;
            }
            if (!data || typeof data !== 'object') {
                // A login page instead of JSON: the session is gone.
                throw new Error(strings.session_lost || 'Session expired');
            }
            return data;
        });
    }

    /**
     * The element that scrolls the page: ILIAS scrolls its content column,
     * older skins the window.
     */
    function Scroller(anchor) {
        this.anchor = anchor;
        this.element = null;
        this.detect();
    }

    Scroller.prototype.detect = function () {
        var parent = this.anchor.parentElement;
        while (parent && parent !== document.body && parent !== document.documentElement) {
            var style = window.getComputedStyle(parent);
            if (/(auto|scroll)/.test(style.overflowY)) {
                this.element = parent;
                return parent;
            }
            parent = parent.parentElement;
        }
        this.element = null;
        return null;
    };

    Scroller.prototype.target = function () {
        return this.element || this.detect();
    };

    Scroller.prototype.top = function () {
        var target = this.target();
        return target ? target.scrollTop : window.pageYOffset;
    };

    Scroller.prototype.scrollTo = function (y) {
        var target = this.target();
        if (target) {
            target.scrollTop = y;
        } else {
            window.scrollTo(0, y);
        }
    };

    Scroller.prototype.scrollBy = function (delta) {
        this.scrollTo(this.top() + delta);
    };

    Scroller.prototype.viewportHeight = function () {
        var target = this.target();
        return target ? target.clientHeight : window.innerHeight;
    };

    Scroller.prototype.viewportTop = function () {
        var target = this.target();
        return target ? target.getBoundingClientRect().top : 0;
    };

    Scroller.prototype.scrollHeight = function () {
        var target = this.target();
        return target ? target.scrollHeight : document.documentElement.scrollHeight;
    };

    /** Position of an element's top edge in scroll coordinates. */
    Scroller.prototype.offsetOf = function (node) {
        return node.getBoundingClientRect().top - this.viewportTop() + this.top();
    };

    Scroller.prototype.listen = function (handler) {
        var target = this.target();
        (target || window).addEventListener('scroll', handler, {passive: true});
    };

    // ------------------------------------------------------------------
    // Processing indicator
    // ------------------------------------------------------------------

    function step(text, icon, duration, spin) {
        return {text: text, icon: icon, duration: duration, spin: spin};
    }

    var ASK_STEPS = [
        step('proc_ask_classify', 'proc_diagram', 3200, 3.2),
        step('proc_ask_search', 'proc_atom', 4200, 2.6),
        step('proc_ask_media', 'proc_shapes', 4000, 3.4),
        step('proc_ask_select', 'proc_dot', 3800, 3.6),
        step('proc_ask_cube', 'proc_cube', 3600, 3.2),
        step('proc_ask_dqr', 'proc_gear', 3400, 4),
        step('proc_ask_compose', 'proc_sparkle', 5500, 2.4),
        step('proc_ask_verify', 'proc_notch', 4500, 3.8),
        step('proc_ask_polish', 'proc_sun', 7000, 3)
    ];
    var PRACTICE_FIRST = [
        step('proc_practice_cube_create', 'proc_cube', 3600, 3.2),
        step('proc_practice_baseline', 'proc_chart', 3800, 3.4)
    ];
    var PRACTICE_NEXT = [
        step('proc_practice_evaluate', 'proc_asterisk', 3200, 2.8),
        step('proc_practice_compare', 'proc_chart', 3800, 3.4),
        step('proc_practice_surprise', 'proc_gear', 3600, 4),
        step('proc_practice_reestimate', 'proc_atom', 3200, 2.6)
    ];
    var PRACTICE_STEPS = [
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
        step('proc_practice_polish', 'proc_sun', 7000, 3)
    ];
    var LEARN_FIRST = [
        step('proc_learn_path_create', 'proc_hurricane', 3600, 4.2)
    ];
    var LEARN_NEXT = [
        step('proc_learn_progress', 'proc_chart', 3200, 3.4),
        step('proc_learn_objectives', 'proc_notch', 3800, 3.8)
    ];
    var LEARN_STEPS = [
        step('proc_learn_cells', 'proc_cube', 3800, 3.2),
        step('proc_learn_next', 'proc_crosshair', 4000, 3.6),
        step('proc_learn_passages', 'proc_atom', 4500, 2.6),
        step('proc_learn_dqr', 'proc_gear', 3600, 4),
        step('proc_learn_split', 'proc_diagram', 4000, 3.2),
        step('proc_learn_examples', 'proc_seedling', 3800, 3),
        step('proc_learn_link', 'proc_nodes', 4200, 3.4),
        step('proc_learn_check', 'proc_dot', 4200, 3.6),
        step('proc_learn_path_update', 'proc_hurricane', 5000, 2.8),
        step('proc_learn_polish', 'proc_sparkle', 7000, 2.8)
    ];

    var VOICE_FIRST = [
        step('proc_ask_transcribe', 'proc_atom', 3500, 2.6)
    ];

    function processingSequence(mode, firstRun) {
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

    // ------------------------------------------------------------------
    // Chat
    // ------------------------------------------------------------------

    function Chat(root, config) {
        this.root = root;
        this.config = config;
        this.strings = config.strings || {};
        this.icons = config.icons || {};
        this.modes = config.modes || [];
        this.scroller = new Scroller(root);
        this.navOffset = 0;
        this.state = {
            mode: 'ask',
            replyTo: null,
            practiceStarted: false,
            learnStarted: false,
            feedOffset: 0,
            feedHasMore: false,
            feedLoading: false,
            stacks: {},
            programmaticScroll: false,
            progress: null,
            voiceInput: false,
            live: null,
            stats: null
        };
        this.voice = {state: 'idle', recorder: null, stream: null, chunks: [], blob: null, url: null, timer: null, elapsed: 0, discarding: false};
        this.init();
    }

    Chat.prototype.q = function (selector) {
        return this.root.querySelector(selector);
    };

    Chat.prototype.qa = function (selector) {
        return Array.prototype.slice.call(this.root.querySelectorAll(selector));
    };

    Chat.prototype.t = function (key, params) {
        return format(this.strings[key] || key, params);
    };

    Chat.prototype.icon = function (name, cls) {
        var path = this.icons[name] || '';
        if (!path) {
            return '';
        }
        return '<svg class="' + (cls || 'tarsus-icon') + '" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'
            + path + '</svg>';
    };

    Chat.prototype.call = function (endpoint, payload) {
        return post(this.config.endpoints[endpoint], payload, this.strings);
    };

    Chat.prototype.alert = function (message) {
        var box = this.q('[data-region="alert"]');
        if (!box) {
            return;
        }
        if (!message) {
            box.textContent = '';
            show(box, false);
            return;
        }
        box.textContent = message;
        show(box, true);
    };

    // --- accessibility ---------------------------------------------------

    Chat.prototype.robotName = function () {
        return this.config.robotName || 'Tarsus';
    };

    /**
     * Tell screen-reader users what happened, without moving focus (a
     * polite live region). Messages of the same moment are read together.
     */
    Chat.prototype.announce = function (text) {
        var self = this;
        var region = this.q('[data-region="announcer"]');
        if (!region || !text) {
            return;
        }
        this.pendingAnnouncement = (this.pendingAnnouncement ? this.pendingAnnouncement + ' ' : '') + text;
        if (this.announceTimer) {
            window.clearTimeout(this.announceTimer);
        }
        region.textContent = '';
        this.announceTimer = window.setTimeout(function () {
            region.textContent = self.pendingAnnouncement;
            self.pendingAnnouncement = '';
            self.announceTimer = null;
            // Gone after a while, so reading the page later does not meet it again.
            if (self.announceClear) {
                window.clearTimeout(self.announceClear);
            }
            self.announceClear = window.setTimeout(function () {
                region.textContent = '';
            }, 15000);
        }, 80);
    };

    /** The answer of a new exchange card, read out. */
    Chat.prototype.announceAnswer = function (card) {
        var body = card ? card.querySelector('[data-region="card-answer"]') : null;
        if (!body) {
            return;
        }
        var text = readableText(body).trim();
        if (body.querySelector('iframe')) {
            text = (text ? text + ' ' : '') + this.t('frame_title');
        }
        this.announce(this.t('a11y_answered', {name: this.robotName()}) + ' ' + text);
    };

    /** How to go on in a mode, for the announcement of a mode change. */
    Chat.prototype.modeHint = function (mode) {
        if (mode === 'ask') {
            return this.t('a11y_mode_ask');
        }
        if (mode === 'discuss') {
            var live = this.q('[data-region="live-label"]');
            return this.t('a11y_mode_discuss', {button: live ? live.textContent : this.t('live_start')});
        }
        var label = this.q('[data-region="practice-label"]');
        return this.t('a11y_mode_next', {button: label ? label.textContent : this.t('practice_next')});
    };

    /** Correct, not correct or partly correct, in words. */
    Chat.prototype.verdictText = function (correct, score) {
        if (typeof score === 'number' && score > 0 && score < 100) {
            return this.t('a11y_partial', {score: Math.round(score)});
        }
        return this.t(correct ? 'a11y_correct' : 'a11y_incorrect');
    };

    /** The learner's experience points and result as they are shown. */
    Chat.prototype.scoreText = function () {
        var parts = [];
        var badge = this.q('[data-region="xp-badge"]');
        var chip = this.q('[data-region="grade-chip"]');
        [badge, chip].forEach(function (node) {
            if (node && !node.classList.contains('tarsus-hidden') && node.textContent.trim() !== '') {
                parts.push(node.textContent.split('\u00a0').join(' ').trim());
            }
        });
        return parts.join(', ');
    };

    /** The heading of a question card: "Question, Knowledge, easy". */
    Chat.prototype.questionHeading = function (widget) {
        var parts = [this.t('a11y_question')];
        if (widget.taxonomy) {
            parts.push(widget.taxonomy);
        }
        if (widget.difficulty) {
            parts.push(widget.difficulty);
        }
        return parts.join(', ');
    };

    /**
     * Keyboard focus on a new card, where reading starts: the slide's
     * title, the question, the card's title or its text.
     */
    Chat.prototype.focusCard = function (card) {
        if (!card) {
            return;
        }
        var slide = card.querySelector('[data-region="slide"]:not(.tarsus-hidden)');
        var scope = slide || card;
        var heading = scope.querySelector('[data-region="question-heading"], .tarsus-learn-slide__title, .tarsus-card__title');
        var body = scope.querySelector('.tarsus-practice-card__question, .tarsus-learn-slide__text, .tarsus-card__welcome-text');
        focusOn(heading || body || scope);
        if (heading && body) {
            this.announce(readableText(body).trim());
        }
    };

    /** The skip link: to the control the current mode starts with. */
    Chat.prototype.focusComposer = function () {
        var mode = this.state.mode;
        var target = null;
        if (mode === 'ask') {
            target = this.state.voiceInput ? this.q('[data-region="voice"] button:not(.tarsus-hidden):not([disabled])') : this.q('.tarsus-chat-input');
        } else if (mode === 'discuss') {
            target = this.q('[data-action="live-start"]');
        } else {
            target = this.q('[data-action="next"]');
        }
        focusOn(target || this.q('[data-region="composer"]'));
    };

    /** Check pressed before anything was chosen or filled in. */
    Chat.prototype.missingAnswer = function (card) {
        this.alert(this.t('a11y_answer_missing'));
        var empty = null;
        card.querySelectorAll('input:not([type="radio"]), textarea').forEach(function (input) {
            if (!empty && !input.disabled && input.value.trim() === '') {
                empty = input;
            }
        });
        focusOn(empty || card.querySelector('input[type="radio"]'));
    };

    Chat.prototype.flow = function () {
        return this.q('[data-region="flow"]');
    };

    // --- scrolling -----------------------------------------------------

    Chat.prototype.scrollPage = function (top) {
        var self = this;
        this.state.programmaticScroll = true;
        this.scroller.scrollTo(Math.max(0, top));
        window.setTimeout(function () {
            self.state.programmaticScroll = false;
            self.toggleJumpTop();
        }, 150);
    };

    /** Scroll so the end of the feed shows above the composer. */
    Chat.prototype.scrollToEnd = function () {
        var flow = this.flow();
        var dock = this.q('[data-region="composer"]');
        if (!flow) {
            return;
        }
        var bottom = this.scroller.offsetOf(flow) + flow.offsetHeight;
        var visible = this.scroller.viewportHeight() - (dock ? dock.offsetHeight : 0);
        this.scrollPage(bottom - visible + 8);
    };

    /** Scroll so a card's top sits just under the pinned progress bar. */
    Chat.prototype.scrollToCard = function (card) {
        if (!card) {
            return;
        }
        var offset = this.navOffset;
        var progress = this.q('[data-region="progress"]');
        if (progress && !progress.classList.contains('tarsus-hidden')) {
            offset += progress.offsetHeight + 10;
        }
        this.scrollPage(this.scroller.offsetOf(card) - offset - 16);
    };

    /** Run a change that adds content above the viewport, keeping the reader's place. */
    Chat.prototype.keepViewport = function (change) {
        var flow = this.flow();
        var above = flow ? flow.getBoundingClientRect().top < this.scroller.viewportTop() : true;
        var before = this.scroller.scrollHeight();
        change();
        if (above) {
            this.scroller.scrollBy(this.scroller.scrollHeight() - before);
        }
    };

    Chat.prototype.toggleJumpTop = function () {
        var button = this.q('[data-action="jump-top"]');
        if (!button) {
            return;
        }
        show(button, this.root.getBoundingClientRect().top < this.scroller.viewportTop() + this.navOffset - 40);
    };

    Chat.prototype.nearFeedTop = function () {
        var flow = this.flow();
        if (!flow) {
            return false;
        }
        return flow.getBoundingClientRect().top - this.scroller.viewportTop() - this.navOffset > -FEED_SCROLL_THRESHOLD;
    };

    /**
     * Measure what the skin pins to the top of the scrolling area (ILIAS
     * keeps the breadcrumbs there): the progress bar and the back-to-top
     * button sit right under it, and scroll targets allow for it.
     */
    Chat.prototype.measureChrome = function () {
        var top = this.scroller.viewportTop();
        var offset = 0;
        var nodes = document.querySelectorAll('.breadcrumbs, .breadcrumb, [class*="breadcrumb"], [class*="sticky"], header, nav');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (node.closest('.tarsus-app')) {
                continue;
            }
            var style = window.getComputedStyle(node);
            if (style.position !== 'sticky' && style.position !== 'fixed') {
                continue;
            }
            var rect = node.getBoundingClientRect();
            if (rect.height > 0 && rect.height < 160 && Math.abs(rect.top - top) <= 2) {
                offset = Math.max(offset, rect.bottom - top);
            }
        }
        this.navOffset = Math.round(offset);
        this.root.style.setProperty('--tarsus-nav-offset', this.navOffset + 'px');
    };

    // --- cards ---------------------------------------------------------

    Chat.prototype.noticeCard = function (variant, title, html, fallbackTitleKey, id) {
        var image = this.config.images[variant === 'complete' ? 'success' : (variant === 'motivation' ? 'motivation' : 'welcome')];
        var heading = title || (fallbackTitleKey ? this.t(fallbackTitleKey) : '');
        var card = el('div', {
            'class': 'tarsus-card tarsus-card--welcome' + (variant ? ' tarsus-card--' + variant : ''),
            'data-region': variant === 'complete' ? 'learn-complete' : (variant === 'motivation' ? 'learn-motivation' : 'tarsus-chat-card'),
            'data-widget-id': id || null
        }, el('div', {'class': 'tarsus-card__inner tarsus-center'}, [
            el('div', {'class': 'tarsus-welcome-art'}, el('img', {src: image, alt: ''})),
            heading ? el('h3', {'class': 'tarsus-card__title', text: heading}) : null,
            el('div', {'class': 'tarsus-card__welcome-text', html: html || ''})
        ]));
        return card;
    };

    /**
     * A question and its answer. meta: id and state of the answer widget
     * (for its sources), sources (Tarsus named the bits it used), voice
     * ({src, duration} of the learner's recording). The sources sit at the
     * top right of the answer; "ask about this" belongs to learning stacks
     * and quiz questions only.
     */
    Chat.prototype.exchangeCard = function (question, answerHtml, reply, pageHtml, meta) {
        meta = meta || {};
        var body = el('div', {'class': 'tarsus-card__answer-body', 'data-region': 'card-answer', html: answerHtml || ''});
        if (pageHtml) {
            // AI-driven interaction: the answer is an interactive page.
            body.appendChild(this.frame(pageHtml, question));
        }
        var sources = meta.id && meta.sources && this.config.references
            ? el('button', {type: 'button', 'class': 'tarsus-chip-btn', 'data-action': 'sources',
                'data-widget-id': meta.id, 'data-state': meta.state || 'ask', html: this.icon('sources')}, el('span', {text: this.t('sources_show')}))
            : null;
        var replied = reply ? el('div', {'class': 'tarsus-card__reply', 'aria-label': this.t('chat_reply_about', {title: reply}), html: this.icon('reply')},
            el('span', {text: reply})) : null;
        var top = sources || replied ? el('div', {'class': 'tarsus-card__top'}, [replied, sources]) : null;
        var voice = null;
        if (meta.voice && meta.voice.src) {
            voice = el('div', {'class': 'tarsus-card__voice'}, [
                el('span', {'class': 'tarsus-card__voice-label', html: this.icon('mic')},
                    el('span', {text: this.t('voice_question') + (meta.voice.duration ? ' · ' + clock(meta.voice.duration) : '')})),
                el('audio', {'class': 'tarsus-card__voice-audio', controls: true, preload: 'metadata', src: meta.voice.src}),
                el('span', {'class': 'tarsus-card__voice-note', text: this.t('voice_transcript_note')})
            ]);
        }
        return el('div', {'class': 'tarsus-card', 'data-region': 'tarsus-chat-card', 'data-kind': 'exchange', 'data-widget-id': meta.id || null},
            el('div', {'class': 'tarsus-card__inner'}, [
                top,
                el('div', {'class': 'tarsus-card__question'}, [
                    this.avatar(this.config.userAvatar, ''),
                    el('div', {'class': 'tarsus-card__question-main'}, [
                        voice,
                        el('h3', {'class': 'tarsus-card__question-text'}, [srText(this.t('a11y_you_asked') + ' '), question])
                    ])
                ]),
                el('hr', {'class': 'tarsus-card__divider'}),
                el('div', {'class': 'tarsus-card__answer'}, [
                    this.avatar(this.config.robotAvatar, this.config.robotName),
                    el('div', {'class': 'tarsus-card__answer-main'}, [body])
                ])
            ]));
    };

    /** Seconds as m:ss. */
    function clock(seconds) {
        seconds = Math.max(0, Math.round(seconds || 0));
        return Math.floor(seconds / 60) + ':' + ('0' + (seconds % 60)).slice(-2);
    }

    Chat.prototype.avatar = function (src, alt) {
        if (!src) {
            return el('span', {'class': 'tarsus-card__avatar tarsus-card__avatar--empty', 'aria-hidden': 'true'});
        }
        return el('img', {'class': 'tarsus-card__avatar', src: src, alt: alt || ''});
    };

    // --- interactive pages (ai-driven interaction) ----------------------
    //
    // The brain's HTML pages run in a sandboxed frame: scripts may run,
    // but the page has an opaque origin and no access to ILIAS, its
    // cookies or this document. A small reporter appended to the page
    // posts its height so the frame fits its content.

    // The content's real extent is the lowest edge of any element (a
    // document's scrollHeight never drops below the frame's own height, so
    // it could only ever grow the frame).
    var FRAME_REPORTER = '<script>(function(){var l=0;function r(){try{var b=document.body;if(!b){return;}var h=0,e=b.getElementsByTagName("*"),y=window.pageYOffset||0;'
        + 'for(var i=0;i<e.length&&i<5000;i++){var c=e[i].getBoundingClientRect();if(c.bottom+y>h){h=c.bottom+y;}}'
        + 'h=Math.ceil(h)+parseFloat(getComputedStyle(b).marginBottom||0)+8;'
        + 'if(Math.abs(h-l)>2){l=h;parent.postMessage({type:"tarsus:height",height:h},"*");}}catch(x){}}'
        + 'window.addEventListener("load",r);document.addEventListener("DOMContentLoaded",r);'
        + 'if(window.ResizeObserver&&document.documentElement){new ResizeObserver(r).observe(document.documentElement);}setInterval(r,1500);})();<' + '/script>';

    function withReporter(html) {
        var index = html.search(/<\/body\s*>/i);
        if (index === -1) {
            return html + FRAME_REPORTER;
        }
        return html.slice(0, index) + FRAME_REPORTER + html.slice(index);
    }

    // Tarsus's pages report their own height (genericWebWidget:resize);
    // once a page does, the reporter's figure is ignored for it.
    function sizeFrames(event) {
        var data = event.data;
        if (!data || typeof data !== 'object') {
            return;
        }
        var own = data.type === 'genericWebWidget:resize';
        if (!own && data.type !== 'tarsus:height') {
            return;
        }
        var frames = document.querySelectorAll('.tarsus-frame__iframe, .tarsus-live__frame');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow !== event.source) {
                continue;
            }
            if (!own && frames[i].getAttribute('data-sized') === 'page') {
                return;
            }
            if (own) {
                frames[i].setAttribute('data-sized', 'page');
            }
            var live = frames[i].classList.contains('tarsus-live__frame');
            var height = Math.max(live ? 420 : 120, Math.min(2400, parseInt(data.height, 10) || 0));
            frames[i].style.height = height + 'px';
            return;
        }
    }
    if (!window.__tarsusFrameSizer) {
        window.__tarsusFrameSizer = true;
        window.addEventListener('message', sizeFrames);
    }

    Chat.prototype.frame = function (html, title) {
        var iframe = el('iframe', {
            'class': 'tarsus-frame__iframe', sandbox: 'allow-scripts allow-forms allow-pointer-lock',
            referrerpolicy: 'no-referrer', loading: 'lazy', title: title || this.t('frame_title')
        });
        iframe.setAttribute('srcdoc', withReporter(html || ''));
        var frame = el('div', {'class': 'tarsus-frame', 'data-region': 'frame'}, [
            iframe,
            el('button', {type: 'button', 'class': 'tarsus-frame__enlarge', 'data-action': 'frame-enlarge',
                title: this.t('frame_enlarge'), 'aria-label': this.t('frame_enlarge'), html: this.icon('enlarge')})
        ]);
        frame.__html = html || '';
        frame.__title = title || '';
        return frame;
    };

    Chat.prototype.openPage = function (html, title) {
        var self = this;
        var dialog = document.querySelector('.tarsus-lightbox--page');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'tarsus-lightbox tarsus-lightbox--page';
            var close = el('button', {type: 'button', 'class': 'tarsus-lightbox__close', 'aria-label': this.t('learn_image_close'), text: '×'});
            close.addEventListener('click', function () {
                dialog.close();
            });
            dialog.addEventListener('close', function () {
                var old = dialog.querySelector('iframe');
                if (old) {
                    old.remove();
                }
            });
            dialog.appendChild(close);
            document.body.appendChild(dialog);
        }
        var old = dialog.querySelector('iframe');
        if (old) {
            old.remove();
        }
        var iframe = el('iframe', {
            'class': 'tarsus-lightbox__page', sandbox: 'allow-scripts allow-forms allow-pointer-lock',
            referrerpolicy: 'no-referrer', title: title || self.t('frame_title')
        });
        iframe.setAttribute('srcdoc', html || '');
        dialog.appendChild(iframe);
        dialog.setAttribute('aria-label', title || self.t('frame_title'));
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        }
    };

    Chat.prototype.objectivesCard = function (item) {
        var list = el('ol', {'class': 'tarsus-learn-objectives'});
        (item.objectives || []).forEach(function (objective, index) {
            list.appendChild(el('li', {'class': 'tarsus-learn-objectives__item' + (objective.done ? ' tarsus-learn-objectives__item--done' : '')}, [
                el('span', {'class': 'tarsus-learn-objectives__number', text: String(index + 1)}),
                el('span', {'class': 'tarsus-learn-objectives__text', text: objective.objective}),
                objective.taxonomy ? el('span', {'class': 'tarsus-badge tarsus-badge--light', text: objective.taxonomy}) : null
            ]));
        });
        return el('div', {'class': 'tarsus-card tarsus-card--welcome tarsus-card--objectives', 'data-region': 'learn-objectives'},
            el('div', {'class': 'tarsus-card__inner'}, [
                el('div', {'class': 'tarsus-welcome-art tarsus-center'}, el('img', {src: this.config.images.objectives, alt: ''})),
                item.title ? el('h3', {'class': 'tarsus-card__title tarsus-center', text: item.title}) : null,
                el('div', {'class': 'tarsus-card__welcome-text tarsus-center', html: item.message || ''}),
                list
            ]));
    };

    /** The difficulty bars of a question (null when the grade is unknown). */
    Chat.prototype.difficulty = function (widget, extraClass) {
        var level = widget.difficultylevel || 0;
        if (!(level > 0)) {
            return null;
        }
        var box = el('span', {
            'class': 'tarsus-difficulty tarsus-difficulty--' + level + (extraClass ? ' ' + extraClass : ''),
            title: widget.difficulty || '',
            role: 'img',
            'aria-label': widget.difficulty || ''
        });
        [1, 2, 3].forEach(function (bar) {
            box.appendChild(el('span', {'class': 'tarsus-difficulty__bar' + (bar <= level ? ' tarsus-difficulty__bar--filled' : '')}));
        });
        return box;
    };

    Chat.prototype.askButton = function () {
        if (this.modes.indexOf('ask') === -1) {
            return null;
        }
        return el('button', {type: 'button', 'class': 'tarsus-learn-ask', 'data-action': 'ask-about', title: this.t('learn_ask_about'),
            html: this.icon('reply')}, el('span', {text: this.t('learn_ask_about')}));
    };

    /** The inside of a question card: question, inputs, Check, score, feedback. */
    Chat.prototype.practiceBody = function (widget) {
        var self = this;
        var inner = el('div', {'class': 'tarsus-practice-card__inner'});
        var qid = nextId('tarsus-question-');
        inner.appendChild(el('div', {'class': 'tarsus-practice-card__question', id: qid, html: widget.question || ''}));

        var choices = widget.choices || [];
        var variables = widget.variables || [];
        var table = widget.table || [];

        if (choices.length) {
            var group = el('div', {'class': 'tarsus-practice-card__choices', 'data-region': 'choices', role: 'radiogroup', 'aria-labelledby': qid});
            choices.forEach(function (choice) {
                group.appendChild(el('label', {'class': 'tarsus-practice-choice', 'data-value': choice.value}, [
                    el('input', {type: 'radio', 'class': 'tarsus-practice-choice__input', name: 'practice-' + widget.id, value: choice.value}),
                    inlineMarkdown('tarsus-practice-choice__label', choice.label),
                    el('span', {'class': 'tarsus-practice-choice__clarification tarsus-hidden', 'data-region': 'clarification'})
                ]));
            });
            inner.appendChild(group);
        } else if (variables.length) {
            var vars = el('div', {'class': 'tarsus-practice-card__variables', 'data-region': 'variables', role: 'group', 'aria-labelledby': qid});
            variables.forEach(function (name) {
                vars.appendChild(el('label', {'class': 'tarsus-practice-variable'}, [
                    el('span', {'class': 'tarsus-practice-variable__name', text: name}),
                    el('input', {type: 'text', 'class': 'tarsus-practice-variable__input tarsus-input', 'data-variable': name,
                        placeholder: self.t('practice_variable_placeholder'), autocomplete: 'off'})
                ]));
            });
            inner.appendChild(vars);
        } else if (table.length) {
            // A task table under the columns Tarsus named; a '?' cell is
            // the learner's to fill in.
            var columns = widget.columns || [];
            var tbody = el('tbody');
            table.forEach(function (row, r) {
                tbody.appendChild(el('tr', {'class': 'tarsus-practice-table__row', 'data-row': String(r)}, row.map(function (cell, c) {
                    return el('td', {'class': 'tarsus-practice-table__cell', 'data-col': String(c)}, cell.ask
                        ? el('input', {type: 'text', 'class': 'tarsus-practice-table__input tarsus-input', 'data-col': String(c),
                            autocomplete: 'off', 'aria-label': columns[c] || ''})
                        : el('span', {'class': 'tarsus-practice-table__value', text: cell.value}));
                })));
            });
            inner.appendChild(el('div', {'class': 'tarsus-practice-card__table-wrap'}, el('table', {'class': 'tarsus-practice-table', 'data-region': 'table', 'aria-labelledby': qid}, [
                el('thead', {}, el('tr', {}, columns.map(function (column) {
                    return el('th', {text: column});
                }))),
                tbody
            ])));
        } else {
            inner.appendChild(el('textarea', {'class': 'tarsus-practice-card__input tarsus-input', rows: '3',
                placeholder: this.t('practice_answer_placeholder'), 'aria-label': this.t('practice_answer_placeholder'), 'aria-describedby': qid}));
        }

        inner.appendChild(el('div', {'class': 'tarsus-practice-card__actions'},
            el('button', {type: 'button', 'class': 'tarsus-btn tarsus-btn--primary tarsus-practice-check'}, [
                el('span', {'class': 'tarsus-spinner tarsus-spinner--light tarsus-practice-check__spinner tarsus-hidden', 'aria-hidden': 'true'}),
                el('span', {text: this.t('practice_check')})
            ])));
        inner.appendChild(el('div', {'class': 'tarsus-practice-card__score tarsus-hidden', 'data-region': 'score', role: 'meter',
            'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0', 'aria-label': this.t('practice_score')}, [
            el('div', {'class': 'tarsus-practice-card__score-track'}, el('div', {'class': 'tarsus-practice-card__score-fill', 'data-region': 'score-fill'})),
            el('span', {'class': 'tarsus-practice-card__score-value', 'data-region': 'score-value'})
        ]));
        inner.appendChild(el('div', {'class': 'tarsus-practice-card__feedback tarsus-hidden', 'data-region': 'feedback', tabindex: '-1'}));
        return inner;
    };

    Chat.prototype.practiceCard = function (widget) {
        var card = el('div', {
            'class': 'tarsus-card tarsus-practice-card',
            'data-region': 'practice-card',
            'data-state': 'practice',
            'data-widget-id': widget.id,
            'data-widget-type': widget.type,
            'data-columns': (widget.table || []).length ? JSON.stringify(widget.columns || []) : null
        });
        var band = el('div', {'class': 'tarsus-card__band'});
        var bars = this.difficulty(widget);
        if (bars) {
            band.appendChild(bars);
        }
        if (widget.taxonomy) {
            band.appendChild(el('span', {'class': 'tarsus-badge tarsus-badge--light', text: widget.taxonomy}));
        }
        var ask = this.askButton();
        if (ask) {
            band.appendChild(ask);
        }
        card.appendChild(el('h3', {'class': 'tarsus-sr-only', 'data-region': 'question-heading', text: this.questionHeading(widget)}));
        card.appendChild(band);
        card.appendChild(this.practiceBody(widget));
        return card;
    };

    function ringOffset(shown, total) {
        var circumference = 2 * Math.PI * 9;
        var fraction = total > 0 ? shown / total : 1;
        return Math.round(circumference * (1 - fraction) * 100) / 100;
    }

    Chat.prototype.stackCard = function (item) {
        var self = this;
        var slides = item.slides || [];
        this.state.stacks[item.id] = slides;
        var total = slides.length;

        var card = el('div', {'class': 'tarsus-card tarsus-learn-card', 'data-region': 'learn-card', 'data-stack-id': item.id, 'data-total': String(total)});
        card.appendChild(el('h3', {'class': 'tarsus-sr-only', text: this.t('a11y_learning_unit')}));
        var band = el('div', {'class': 'tarsus-card__band tarsus-learn-card__meta'});
        band.innerHTML = '<svg class="tarsus-learn-ring" data-region="ring" viewBox="0 0 24 24" width="24" height="24" role="img"'
            + ' aria-label="' + escapeHtml(this.t('learn_slide_of', {current: 1, total: total})) + '">'
            + '<circle class="tarsus-learn-ring__track" cx="12" cy="12" r="9"></circle>'
            + '<circle class="tarsus-learn-ring__fill" cx="12" cy="12" r="9" style="stroke-dashoffset: ' + ringOffset(1, total) + '"></circle></svg>';

        slides.forEach(function (slide, index) {
            if (slide.kind === 'question' && slide.question) {
                var bars = self.difficulty(slide.question, index === 0 ? '' : 'tarsus-hidden');
                if (bars) {
                    bars.setAttribute('data-region', 'slide-badge');
                    bars.setAttribute('data-index', String(index));
                    band.appendChild(bars);
                }
            }
        });

        var player = this.config.audio === false ? null : el('span', {'class': 'tarsus-learn-player', 'data-region': 'audio-player', 'data-state': 'idle'}, [
            el('button', {type: 'button', 'class': 'tarsus-learn-player__toggle', 'data-action': 'audio-toggle', title: this.t('learn_audio'), 'aria-label': this.t('learn_audio')}, [
                el('span', {'data-region': 'audio-speaker', html: this.icon('audio')}),
                el('span', {'data-region': 'audio-play', 'class': 'tarsus-hidden', html: this.icon('audio_play')}),
                el('span', {'data-region': 'audio-pause', 'class': 'tarsus-hidden', html: this.icon('audio_pause')})
            ]),
            el('span', {'class': 'tarsus-learn-player__wave tarsus-hidden', 'data-region': 'audio-wave', 'aria-hidden': 'true'}),
            el('audio', {'data-region': 'audio', preload: 'auto'})
        ]);
        if (player) {
            band.appendChild(player);
        }

        slides.forEach(function (slide, index) {
            var label = slide.taxonomy || (slide.question && slide.question.taxonomy) || self.t('mode_build');
            band.appendChild(el('span', {
                'class': 'tarsus-badge tarsus-badge--light tarsus-learn-badge' + (index === 0 ? '' : ' tarsus-hidden'),
                'data-region': 'slide-badge', 'data-index': String(index), text: label
            }));
        });
        var ask = this.askButton();
        if (ask) {
            band.appendChild(ask);
        }
        card.appendChild(band);

        var inner = el('div', {'class': 'tarsus-learn-card__inner'});
        slides.forEach(function (slide, index) {
            var node = el('div', {
                'class': 'tarsus-learn-slide' + (index === 0 ? '' : ' tarsus-hidden'),
                'data-region': 'slide', 'data-index': String(index), 'data-slide-id': slide.id || '', 'data-slide-title': slide.title || ''
            });
            if (slide.kind === 'question' && slide.question) {
                var question = slide.question;
                var qcard = el('div', {
                    'class': 'tarsus-practice-card tarsus-practice-card--inslide', 'data-region': 'practice-card', 'data-state': 'learn',
                    'data-widget-id': question.id, 'data-widget-type': question.type,
                    'data-columns': (question.table || []).length ? JSON.stringify(question.columns || []) : null
                });
                qcard.appendChild(el('h4', {'class': 'tarsus-sr-only', 'data-region': 'question-heading', text: self.questionHeading(question)}));
                qcard.appendChild(self.practiceBody(question));
                node.appendChild(qcard);
            } else if (slide.kind === 'html' && slide.html) {
                // An interactive page (ai-driven interaction).
                if (slide.title) {
                    node.appendChild(el('h4', {'class': 'tarsus-learn-slide__title', text: slide.title}));
                }
                node.appendChild(self.frame(slide.html, slide.title));
            } else {
                if (slide.title) {
                    node.appendChild(el('h4', {'class': 'tarsus-learn-slide__title', text: slide.title}));
                }
                // The picture goes where Tarsus puts it: above the text
                // ("top") or beside it ("left", "right").
                var position = ['top', 'left', 'right'].indexOf(slide.imageposition) !== -1 ? slide.imageposition : 'right';
                var body = el('div', {'class': 'tarsus-learn-slide__body tarsus-learn-slide__body--' + (slide.image ? position : 'text')});
                if (slide.image) {
                    body.appendChild(el('img', {'class': 'tarsus-learn-slide__image', src: slide.image, alt: '', tabindex: '0', role: 'button',
                        'data-action': 'enlarge', 'data-title': slide.title || '', title: self.t('learn_image_enlarge'),
                        'aria-label': self.t('learn_image_enlarge') + (slide.title ? ': ' + slide.title : '')}));
                }
                if (slide.text) {
                    body.appendChild(el('div', {'class': 'tarsus-learn-slide__text', html: slide.text}));
                }
                node.appendChild(body);
                if (slide.audio) {
                    node.appendChild(el('audio', {'class': 'tarsus-learn-slide__audio', controls: true, preload: 'none', src: slide.audio}));
                }
            }
            inner.appendChild(node);
        });
        inner.appendChild(el('div', {'class': 'tarsus-learn-card__nav'}, [
            el('button', {type: 'button', 'class': 'tarsus-btn tarsus-btn--sm tarsus-learn-nav', 'data-dir': '-1', disabled: true, text: this.t('learn_previous')}),
            el('button', {type: 'button', 'class': 'tarsus-btn tarsus-btn--sm tarsus-learn-nav', 'data-dir': '1', disabled: total < 2, text: this.t('learn_next')})
        ]));
        card.appendChild(inner);
        return card;
    };

    /** The cards of one learn item (objectives, stack, motivation, complete, message). */
    Chat.prototype.learnCards = function (item) {
        if (item.kind === 'complete') {
            return [this.noticeCard('complete', item.title, item.text, 'learn_complete_title', item.id)];
        }
        if (item.kind === 'motivation') {
            return [this.noticeCard('motivation', item.title, item.text, 'learn_motivation_title', item.id)];
        }
        if (item.kind === 'message') {
            return [this.noticeCard('', item.title, item.text)];
        }
        if (item.kind === 'objectives') {
            return [this.objectivesCard(item)];
        }
        return [this.stackCard(item)];
    };

    Chat.prototype.processingCard = function () {
        return el('div', {'class': 'tarsus-card tarsus-processing', 'data-region': 'processing', 'aria-hidden': 'true'}, [
            el('div', {'class': 'tarsus-processing__row'}, [
                el('span', {'class': 'tarsus-processing__icon', 'data-region': 'icon'}),
                el('span', {'class': 'tarsus-processing__text', 'data-region': 'text'})
            ]),
            el('div', {'class': 'tarsus-processing__bar'}, el('div', {'class': 'tarsus-processing__fill'}))
        ]);
    };

    /** Show the processing indicator under the feed; returns {stop}. */
    Chat.prototype.startProcessing = function (mode, firstRun) {
        var self = this;
        var flow = this.flow();
        var steps = processingSequence(mode, firstRun);
        var element = this.processingCard();
        var timer = null;
        var stopped = false;
        if (!flow) {
            return {stop: noop};
        }
        flow.appendChild(element);
        var iconSlot = element.querySelector('[data-region="icon"]');
        var textSlot = element.querySelector('[data-region="text"]');
        var showStep = function (index) {
            if (stopped) {
                return;
            }
            iconSlot.innerHTML = self.icon(steps[index].icon, 'tarsus-icon tarsus-processing__glyph');
            var glyph = iconSlot.querySelector('svg');
            if (glyph) {
                glyph.style.animationDuration = steps[index].spin + 's';
            }
            textSlot.textContent = self.t(steps[index].text);
            if (index < steps.length - 1) {
                timer = window.setTimeout(function () {
                    showStep(index + 1);
                }, steps[index].duration);
            }
        };
        showStep(0);
        this.announce(this.t('a11y_working', {name: this.robotName()}));
        this.scrollToEnd();
        return {
            stop: function () {
                stopped = true;
                if (timer) {
                    window.clearTimeout(timer);
                }
                if (element.parentNode) {
                    element.parentNode.removeChild(element);
                }
            }
        };
    };

    // --- attaching -----------------------------------------------------

    Chat.prototype.attach = function (card, before, noscroll) {
        var flow = this.flow();
        if (!flow || !card) {
            return null;
        }
        if (before && before.parentNode === flow) {
            this.keepViewport(function () {
                flow.insertBefore(card, before);
            });
        } else {
            flow.appendChild(card);
            if (!noscroll) {
                this.scrollToEnd();
            }
        }
        typeset(card);
        return card;
    };

    // --- verdicts ------------------------------------------------------

    function storedVerdict(item) {
        return {
            correct: item.correct,
            score: item.score,
            feedback: item.feedback,
            useranswer: item.useranswer,
            useranswers: item.useranswers || [],
            answers: item.answers || [],
            usertable: item.usertable || [],
            answertable: item.answertable || []
        };
    }

    /** Freeze an answered question card: mark the pick, show the feedback. */
    Chat.prototype.finalizePracticeCard = function (card, verdict) {
        var self = this;
        card.classList.add('tarsus-practice-card--answered');

        var verdictByVariable = {};
        (verdict.answers || []).forEach(function (entry) {
            verdictByVariable[entry.variable] = entry;
        });
        var givenByVariable = {};
        (verdict.useranswers || []).forEach(function (entry) {
            givenByVariable[entry.variable] = entry.answer;
        });
        card.querySelectorAll('.tarsus-practice-variable__input').forEach(function (input) {
            var name = input.getAttribute('data-variable');
            if (input.value === '' && givenByVariable[name] !== undefined) {
                input.value = givenByVariable[name];
            }
            input.disabled = true;
            var row = input.closest('.tarsus-practice-variable');
            if (row && verdictByVariable[name] && !verdictByVariable[name].iscorrect) {
                row.classList.add('tarsus-practice-variable--incorrect');
                var nameNode = row.querySelector('.tarsus-practice-variable__name');
                if (nameNode && !nameNode.querySelector('.tarsus-sr-only')) {
                    nameNode.appendChild(srText(' ' + self.t('a11y_not_correct')));
                    row.appendChild(el('span', {'class': 'tarsus-verdict-mark tarsus-verdict-mark--incorrect', 'aria-hidden': 'true', html: self.icon('close')}));
                }
            }
        });

        // A task table: the learner's cells back in place, every wrong one
        // marked with the value of Tarsus's model table.
        var userRows = verdict.usertable || [];
        var modelRows = verdict.answertable || [];
        card.querySelectorAll('.tarsus-practice-table__row').forEach(function (row) {
            var r = parseInt(row.getAttribute('data-row'), 10);
            row.querySelectorAll('.tarsus-practice-table__input').forEach(function (input) {
                var c = parseInt(input.getAttribute('data-col'), 10);
                if (input.value === '' && userRows[r]) {
                    input.value = userRows[r][c] || '';
                }
                input.disabled = true;
                var model = modelRows[r] && modelRows[r][c];
                if (model && model.verdict < 0) {
                    var cell = input.parentNode;
                    cell.classList.add('tarsus-practice-table__cell--incorrect');
                    cell.appendChild(el('span', {'class': 'tarsus-practice-table__model', text: self.t('practice_correct_value', {value: model.value})}));
                }
            });
        });

        var chosen = card.querySelector('input[type="radio"]:checked');
        if (!chosen && verdict.useranswer) {
            card.querySelectorAll('input[type="radio"]').forEach(function (input) {
                if (input.value === verdict.useranswer) {
                    input.checked = true;
                    chosen = input;
                }
            });
        }
        card.querySelectorAll('input').forEach(function (input) {
            input.disabled = true;
        });

        var textinput = card.querySelector('.tarsus-practice-card__input');
        if (textinput) {
            if (verdict.useranswer && textinput.value === '') {
                textinput.value = verdict.useranswer;
            }
            textinput.disabled = true;
        }

        var check = card.querySelector('.tarsus-practice-check');
        if (check) {
            show(check, false);
        }

        if (chosen) {
            var box = chosen.closest('.tarsus-practice-choice');
            if (box) {
                box.classList.add(verdict.correct ? 'tarsus-practice-choice--correct' : 'tarsus-practice-choice--incorrect');
                var label = box.querySelector('.tarsus-practice-choice__label');
                if (label && !box.querySelector('.tarsus-verdict-mark')) {
                    label.appendChild(srText(' ' + self.t(verdict.correct ? 'a11y_your_answer_correct' : 'a11y_your_answer_incorrect')));
                    box.appendChild(el('span', {'class': 'tarsus-verdict-mark tarsus-verdict-mark--' + (verdict.correct ? 'correct' : 'incorrect'),
                        'aria-hidden': 'true', html: self.icon(verdict.correct ? 'check' : 'close')}));
                }
            }
        }

        var score = typeof verdict.score === 'number' ? verdict.score : -1;
        var partial = score > 0 && score < 100;
        var scoreBox = card.querySelector('[data-region="score"]');
        if (scoreBox && partial) {
            var rounded = Math.round(score);
            scoreBox.querySelector('[data-region="score-fill"]').style.width = rounded + '%';
            scoreBox.querySelector('[data-region="score-value"]').textContent = rounded + ' %';
            scoreBox.setAttribute('aria-valuenow', rounded);
            show(scoreBox, true);
        }

        var feedback = card.querySelector('[data-region="feedback"]');
        if (feedback && verdict.feedback) {
            show(feedback, true);
            feedback.classList.add(partial ? 'tarsus-practice-card__feedback--partial'
                : (verdict.correct ? 'tarsus-practice-card__feedback--correct' : 'tarsus-practice-card__feedback--incorrect'));
            feedback.innerHTML = verdict.feedback;
            typeset(feedback);
            feedback.insertBefore(el('h4', {'class': 'tarsus-sr-only', 'data-region': 'verdict-heading', text: self.verdictText(verdict.correct, score)}),
                feedback.firstChild);
        }
    };

    // --- feed ----------------------------------------------------------

    /** Build the cards of one feed page, oldest first. */
    Chat.prototype.buildFeedCards = function (items) {
        var self = this;
        var flow = this.flow();
        var built = [];
        items.forEach(function (item) {
            var cards = [];
            if (item.kind === 'message') {
                cards = [self.noticeCard('', item.title, item.text)];
            } else if (item.kind === 'exchange') {
                cards = [self.exchangeCard(item.query, item.answer, '', item.html || '', {id: item.id, state: item.state, sources: item.sources})];
            } else if (['objectives', 'stack', 'complete', 'motivation'].indexOf(item.kind) !== -1) {
                cards = self.learnCards(item);
            } else {
                // A question already on the page (the newer half of a
                // straddling stack) is not built twice.
                if (item.id && flow && flow.querySelector('[data-widget-id="' + item.id + '"]')) {
                    return;
                }
                cards = [self.practiceCard(item)];
            }
            cards.forEach(function (card, index) {
                built.push({
                    card: card,
                    item: item,
                    replaces: index === 0 && card.matches('[data-region="learn-card"]') ? (item.replaces || null) : null
                });
            });
        });
        return built;
    };

    /** Settle a card after it is in the flow: verdicts and formulas. */
    Chat.prototype.settleFeedCard = function (entry) {
        var item = entry.item;
        if (item.kind === 'practice' && item.answered) {
            this.finalizePracticeCard(entry.card, storedVerdict(item));
        }
        if (item.kind === 'stack') {
            var self = this;
            (item.slides || []).forEach(function (slide) {
                if (slide.kind !== 'question' || !slide.question || !slide.question.answered) {
                    return;
                }
                var card = entry.card.querySelector('[data-widget-id="' + slide.question.id + '"]');
                if (card) {
                    self.finalizePracticeCard(card, storedVerdict(slide.question));
                }
            });
        }
        typeset(entry.card);
    };

    Chat.prototype.notePracticeHistory = function (items) {
        var self = this;
        items.forEach(function (item) {
            if (item.kind === 'practice') {
                self.state.practiceStarted = true;
            } else if (item.kind === 'objectives' || item.kind === 'stack' || item.kind === 'motivation' || item.kind === 'complete') {
                self.state.learnStarted = true;
            }
        });
        this.updateNextLabel();
    };

    Chat.prototype.loadFeed = function () {
        var self = this;
        var flow = this.flow();
        if (!flow) {
            return;
        }
        var older = flow.querySelector('[data-region="feed-older"]');
        this.call('feed', {offset: 0}).then(function (result) {
            if (result.status !== 'success') {
                flow.innerHTML = '';
                if (older) {
                    flow.appendChild(older);
                }
                if (result.status === 'noscope' || result.status === 'error' || result.status === 'blocked') {
                    self.alert(result.error || self.t('unexpected_error'));
                }
                return;
            }
            self.updateXp(result.progress);
            self.state.feedOffset = self.config.pageSize;
            self.state.feedHasMore = Boolean(result.hasmore);

            var built = self.buildFeedCards(result.items || []);
            flow.innerHTML = '';
            if (older) {
                flow.appendChild(older);
            }
            built.forEach(function (entry) {
                flow.appendChild(entry.card);
                self.settleFeedCard(entry);
            });
            self.measureChrome();
            self.scrollToEnd();
            self.notePracticeHistory(result.items || []);
            if (self.nearFeedTop()) {
                self.loadOlderFeed();
            }
        }).catch(function (error) {
            flow.innerHTML = '';
            if (older) {
                flow.appendChild(older);
            }
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    Chat.prototype.mergeStraddlingStack = function (flow, items) {
        var last = items[items.length - 1];
        if (!last || last.kind !== 'stack' || !this.state.stacks[last.id]) {
            return items;
        }
        var shown = flow.querySelector('[data-region="learn-card"][data-stack-id="' + last.id + '"]');
        if (!shown) {
            return items;
        }
        var merged = Object.assign({}, last, {
            slides: (last.slides || []).concat(this.state.stacks[last.id]),
            replaces: shown
        });
        return items.slice(0, -1).concat([merged]);
    };

    Chat.prototype.loadOlderFeed = function () {
        var self = this;
        var flow = this.flow();
        if (!flow || this.state.feedLoading || !this.state.feedHasMore) {
            return;
        }
        this.state.feedLoading = true;
        var older = flow.querySelector('[data-region="feed-older"]');
        show(older, true);
        var offset = this.state.feedOffset;

        this.call('feed', {offset: offset}).then(function (result) {
            if (result.status !== 'success') {
                self.state.feedHasMore = false;
                return;
            }
            var items = result.items || [];
            self.state.feedOffset = offset + self.config.pageSize;
            self.state.feedHasMore = Boolean(result.hasmore) && items.length > 0;

            var merged = self.mergeStraddlingStack(flow, items);
            var built = self.buildFeedCards(merged);
            var anchor = older && older.nextSibling ? older.nextSibling : flow.firstChild;
            self.keepViewport(function () {
                built.forEach(function (entry) {
                    if (entry.replaces) {
                        flow.replaceChild(entry.card, entry.replaces);
                    } else {
                        flow.insertBefore(entry.card, anchor);
                    }
                    self.settleFeedCard(entry);
                });
            });
            self.notePracticeHistory(items);
        }).catch(function () {
            self.state.feedHasMore = false;
        }).then(function () {
            self.state.feedLoading = false;
            show(older, false);
        });
    };

    // --- figures -------------------------------------------------------

    Chat.prototype.updateXp = function (progress) {
        if (!progress) {
            return;
        }
        this.state.progress = progress;
        this.renderBadge();
        this.renderProgressBar();
    };

    Chat.prototype.renderBadge = function () {
        this.renderGrade();
        var badge = this.q('[data-region="xp-badge"]');
        if (!badge) {
            return;
        }
        var progress = this.state.progress;
        var build = this.state.mode === 'build';
        var earned = progress ? (build ? progress.earnedunits : progress.earnedxp) : 0;
        var total = progress ? (build ? progress.totalunits : progress.totalxp) : 0;
        if (this.state.mode === 'ask' || this.state.mode === 'discuss' || !(total > 0)) {
            show(badge, false);
            return;
        }
        show(badge, true);
        badge.querySelector('[data-region="xp-earned"]').textContent = Math.round(earned * 100) / 100;
        badge.querySelector('[data-region="xp-total"]').textContent = Math.round(total * 100) / 100;
        badge.querySelector('[data-region="xp-unit"]').textContent = this.t(build ? 'learning_units' : 'xp');
    };

    /**
     * The result in percent, as ILIAS records it: the experience points'
     * share while Test knowledge is active, the learning units' otherwise.
     */
    Chat.prototype.renderGrade = function () {
        var chip = this.q('[data-region="grade-chip"]');
        if (!chip) {
            return;
        }
        var progress = this.state.progress;
        var units = this.config.gradeBasis === 'units';
        var earned = progress ? (units ? progress.earnedunits : progress.earnedxp) : 0;
        var total = progress ? (units ? progress.totalunits : progress.totalxp) : 0;
        if ((this.state.mode !== 'test' && this.state.mode !== 'build') || !(total > 0)) {
            show(chip, false);
            return;
        }
        chip.textContent = this.t('grade_chip', {percent: Math.round(Math.max(0, Math.min(1, earned / total)) * 100)});
        show(chip, true);
    };

    Chat.prototype.renderProgressBar = function () {
        var bar = this.q('[data-region="progress"]');
        var fill = this.q('[data-region="progress-fill"]');
        if (!bar || !fill) {
            return;
        }
        if (this.state.mode === 'ask' || this.state.mode === 'discuss' || !this.state.progress) {
            show(bar, false);
            return;
        }
        var figure = this.state.mode === 'build' ? this.state.progress.learnprogress : this.state.progress.practiceprogress;
        if (typeof figure !== 'number') {
            show(bar, false);
            return;
        }
        var percent = Math.max(0, Math.min(100, Math.round(figure)));
        fill.style.width = percent + '%';
        bar.setAttribute('aria-valuenow', percent);
        bar.setAttribute('title', percent + ' %');
        show(bar, true);
    };

    // --- learning units: slides, pictures, narration -------------------

    Chat.prototype.turnSlide = function (card, dir) {
        var slides = Array.prototype.slice.call(card.querySelectorAll('[data-region="slide"]'));
        var current = slides.findIndex(function (slide) {
            return !slide.classList.contains('tarsus-hidden');
        });
        var target = Math.max(0, Math.min(slides.length - 1, current + dir));
        if (target === current) {
            return;
        }
        var navFocused = document.activeElement && document.activeElement.classList.contains('tarsus-learn-nav') && card.contains(document.activeElement);
        show(slides[current], false);
        show(slides[target], true);
        card.querySelectorAll('[data-region="slide-badge"]').forEach(function (badge) {
            show(badge, parseInt(badge.getAttribute('data-index'), 10) === target);
        });
        var ring = card.querySelector('[data-region="ring"]');
        if (ring) {
            ring.querySelector('.tarsus-learn-ring__fill').style.strokeDashoffset = ringOffset(target + 1, slides.length);
            ring.setAttribute('aria-label', this.t('learn_slide_of', {current: target + 1, total: slides.length}));
        }
        card.querySelectorAll('.tarsus-learn-nav').forEach(function (button) {
            var stepDir = parseInt(button.getAttribute('data-dir'), 10);
            button.disabled = (stepDir < 0 && target === 0) || (stepDir > 0 && target === slides.length - 1);
        });
        typeset(slides[target]);
        var where = this.t('learn_slide_of', {current: target + 1, total: slides.length});
        if (navFocused && (document.activeElement === document.body || document.activeElement.disabled)) {
            // The button pressed is now at its end: reading goes on at the slide.
            this.announce(where);
            this.focusCard(card);
            return;
        }
        var parts = [where];
        slides[target].querySelectorAll('.tarsus-learn-slide__title, .tarsus-learn-slide__text, .tarsus-practice-card__question').forEach(function (part) {
            parts.push(readableText(part).trim());
        });
        this.announce(parts.join('. '));
    };

    Chat.prototype.openLightbox = function (src, title) {
        var self = this;
        var dialog = document.querySelector('.tarsus-lightbox');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'tarsus-lightbox';
            var close = el('button', {type: 'button', 'class': 'tarsus-lightbox__close', 'aria-label': this.t('learn_image_close'), text: '×'});
            var image = el('img', {'class': 'tarsus-lightbox__image', alt: ''});
            dialog.appendChild(close);
            dialog.appendChild(image);
            dialog.addEventListener('click', function () {
                dialog.close();
            });
            document.body.appendChild(dialog);
        }
        dialog.querySelector('.tarsus-lightbox__image').src = src;
        dialog.querySelector('.tarsus-lightbox__image').alt = title || '';
        dialog.setAttribute('aria-label', title || this.t('learn_image_enlarge'));
        if (!dialog.open) {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        }
    };

    Chat.prototype.setAudioState = function (card, stateName) {
        var player = card.querySelector('[data-region="audio-player"]');
        if (!player) {
            return;
        }
        player.setAttribute('data-state', stateName);
        show(player.querySelector('[data-region="audio-speaker"]'), stateName === 'idle');
        show(player.querySelector('[data-region="audio-play"]'), !(stateName === 'idle' || stateName === 'playing'));
        show(player.querySelector('[data-region="audio-pause"]'), stateName === 'playing');
        var key = stateName === 'playing' ? 'learn_audio_pause' : (stateName === 'idle' ? 'learn_audio' : 'learn_audio_play');
        var toggle = player.querySelector('[data-action="audio-toggle"]');
        toggle.setAttribute('title', this.t(key));
        toggle.setAttribute('aria-label', this.t(key));
    };

    Chat.prototype.drawWave = function (card) {
        var wave = card.querySelector('[data-region="audio-wave"]');
        if (!wave || wave.childElementCount) {
            return;
        }
        var seed = 0;
        String(card.getAttribute('data-stack-id') || '').split('').forEach(function (ch) {
            seed = (seed * 31 + ch.charCodeAt(0)) % 2147483647;
        });
        for (var i = 0; i < 36; i++) {
            seed = (seed * 48271) % 2147483647;
            var bar = document.createElement('span');
            bar.className = 'tarsus-learn-player__bar';
            bar.style.height = (25 + (seed % 75)) + '%';
            wave.appendChild(bar);
        }
        show(wave, true);
    };

    Chat.prototype.paintWave = function (card, ratio) {
        var bars = card.querySelectorAll('.tarsus-learn-player__bar');
        var played = Math.round(ratio * bars.length);
        bars.forEach(function (bar, index) {
            bar.classList.toggle('tarsus-learn-player__bar--played', index < played);
        });
    };

    Chat.prototype.wireStackAudio = function (card) {
        var self = this;
        var audio = card.querySelector('[data-region="audio"]');
        audio.addEventListener('play', function () {
            self.setAudioState(card, 'playing');
        });
        audio.addEventListener('pause', function () {
            self.setAudioState(card, 'ready');
        });
        audio.addEventListener('ended', function () {
            self.setAudioState(card, 'ready');
            self.paintWave(card, 0);
        });
        audio.addEventListener('timeupdate', function () {
            if (audio.duration > 0) {
                self.paintWave(card, Math.min(1, audio.currentTime / audio.duration));
            }
        });
    };

    Chat.prototype.fetchStackAudio = function (card) {
        var self = this;
        var audio = card.querySelector('[data-region="audio"]');
        var player = card.querySelector('[data-region="audio-player"]');
        if (!audio || !player || player.getAttribute('data-state') === 'loading') {
            return;
        }
        this.setAudioState(card, 'loading');
        this.call('audio', {stackid: card.getAttribute('data-stack-id')}).then(function (result) {
            if (result.status !== 'success' || !result.audio) {
                self.setAudioState(card, 'idle');
                self.alert(result.error || self.t('unexpected_error'));
                return;
            }
            audio.src = result.audio;
            self.wireStackAudio(card);
            self.drawWave(card);
            self.setAudioState(card, 'ready');
            return audio.play().catch(noop);
        }).catch(function (error) {
            self.setAudioState(card, 'idle');
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    Chat.prototype.toggleStackAudio = function (card) {
        var audio = card.querySelector('[data-region="audio"]');
        if (!audio) {
            return;
        }
        if (!audio.getAttribute('src')) {
            this.fetchStackAudio(card);
            return;
        }
        if (audio.paused) {
            audio.play().catch(noop);
        } else {
            audio.pause();
        }
    };

    // --- Start / Next --------------------------------------------------

    /**
     * Next is never locked here: Tarsus decides what comes (a new step, or
     * the same widget again with HTTP 409).
     */
    Chat.prototype.nextLocked = function () {
        return false;
    };

    Chat.prototype.setNextBusy = function (busy) {
        var button = this.q('[data-action="next"]');
        var spinner = this.q('[data-region="next-spinner"]');
        var icon = this.q('.tarsus-practice-next__icon');
        if (button) {
            button.disabled = busy || this.nextLocked();
            button.setAttribute('data-busy', busy ? '1' : '0');
        }
        show(spinner, busy);
        show(icon, !busy);
    };

    Chat.prototype.updateNextLabel = function () {
        var button = this.q('[data-action="next"]');
        if (button && button.getAttribute('data-busy') !== '1') {
            button.disabled = this.nextLocked();
        }
        var label = this.q('[data-region="practice-label"]');
        if (!label) {
            return;
        }
        var started = this.state.mode === 'build' ? this.state.learnStarted : this.state.practiceStarted;
        label.textContent = this.t(started ? 'practice_next' : 'practice_start');
    };

    Chat.prototype.handleLearnNext = function () {
        var self = this;
        var button = this.q('[data-action="next"]');
        if (!button || button.disabled) {
            return;
        }
        this.setNextBusy(true);
        this.alert('');
        var processing = this.startProcessing('build', !this.state.learnStarted);
        var unlock = function () {
            processing.stop();
            self.setNextBusy(false);
        };
        this.call('learnNext', {}).then(function (result) {
            unlock();
            if (result.status !== 'success' || !result.items || !result.items.length) {
                self.alert(result.error || self.t('unexpected_error'));
                button.focus();
                return;
            }
            if (result.progress) {
                self.updateXp(result.progress);
            }
            var first = null;
            result.items.forEach(function (item) {
                self.learnCards(item).forEach(function (card) {
                    // A widget Tarsus sent again (409) that is already on
                    // the page is shown there instead of a second time.
                    var shown = result.replay ? self.findShown(card) : null;
                    if (shown) {
                        self.flash(shown);
                        first = first || shown;
                        return;
                    }
                    self.attach(card, null, true);
                    first = first || card;
                });
            });
            self.scrollToCard(first);
            self.state.learnStarted = true;
            self.updateNextLabel();
            self.focusCard(first);
        }).catch(function (error) {
            unlock();
            self.alert(error.message || self.t('unexpected_error'));
            button.focus();
        });
    };

    Chat.prototype.handlePracticeNext = function () {
        var self = this;
        if (this.state.mode === 'build') {
            this.handleLearnNext();
            return;
        }
        var button = this.q('[data-action="next"]');
        if (!button || button.disabled) {
            return;
        }
        this.setNextBusy(true);
        this.alert('');
        var processing = this.startProcessing('test', !this.state.practiceStarted);
        var unlock = function () {
            processing.stop();
            self.setNextBusy(false);
        };
        this.call('practiceNext', {}).then(function (result) {
            unlock();
            if (result.status !== 'success' || !result.widgets || !result.widgets.length) {
                self.alert(result.error || self.t('unexpected_error'));
                button.focus();
                return;
            }
            self.updateXp(result.progress);
            var first = null;
            result.widgets.forEach(function (widget) {
                var card = self.attach(self.practiceCard(widget), null, true);
                first = first || card;
            });
            self.scrollToCard(first);
            self.state.practiceStarted = true;
            self.updateNextLabel();
            self.focusCard(first);
        }).catch(function (error) {
            unlock();
            self.alert(error.message || self.t('unexpected_error'));
            button.focus();
        });
    };

    Chat.prototype.handlePracticeCheck = function (card) {
        var self = this;
        var check = card.querySelector('.tarsus-practice-check');
        var spinner = card.querySelector('.tarsus-practice-check__spinner');
        if (!check || check.disabled) {
            return;
        }
        var answer = '';
        var answers = [];
        var table = [];
        var columns = [];
        try {
            columns = JSON.parse(card.getAttribute('data-columns') || '[]');
        } catch (e) {
            columns = [];
        }
        var chosen = card.querySelector('input[type="radio"]:checked');
        var textinput = card.querySelector('.tarsus-practice-card__input');
        var variableInputs = card.querySelectorAll('.tarsus-practice-variable__input');
        var tableRows = card.querySelectorAll('.tarsus-practice-table__row');
        if (tableRows.length) {
            var complete = true;
            tableRows.forEach(function (row) {
                var cells = [];
                row.querySelectorAll('.tarsus-practice-table__cell').forEach(function (cell) {
                    var input = cell.querySelector('input');
                    var given = cell.querySelector('.tarsus-practice-table__value');
                    var value = input ? input.value.trim() : (given ? given.textContent.trim() : '');
                    if (input && value === '') {
                        complete = false;
                    }
                    cells.push(value);
                });
                table.push(cells);
            });
            if (!complete) {
                self.missingAnswer(card);
                return;
            }
        } else if (variableInputs.length) {
            variableInputs.forEach(function (input) {
                if (input.value.trim() !== '') {
                    answers.push({variable: input.getAttribute('data-variable'), answer: input.value.trim()});
                }
            });
            if (answers.length !== variableInputs.length) {
                self.missingAnswer(card);
                return;
            }
        } else if (chosen) {
            answer = chosen.value;
        } else if (textinput) {
            answer = textinput.value.trim();
        }
        if (answer === '' && !answers.length && !table.length) {
            this.missingAnswer(card);
            return;
        }
        this.alert('');

        check.disabled = true;
        show(spinner, true);
        this.call('practiceCheck', {
            widgetid: card.getAttribute('data-widget-id'),
            widgettype: card.getAttribute('data-widget-type'),
            answer: answer,
            answers: answers,
            table: table,
            columns: columns,
            state: card.getAttribute('data-state') || 'practice'
        }).then(function (result) {
            show(spinner, false);
            if (result.status !== 'success') {
                check.disabled = false;
                self.alert(result.error || self.t('unexpected_error'));
                check.focus();
                return;
            }
            self.finalizePracticeCard(card, {
                correct: result.correct,
                score: result.score,
                feedback: result.feedback || result.answer || '',
                useranswer: answer,
                useranswers: answers,
                answers: result.answers || [],
                usertable: result.usertable || table,
                answertable: result.answertable || []
            });
            self.updateXp(result.progress);
            self.scrollToEnd();
            // Focus on the verdict (a heading); the feedback and the result are read out.
            var feedbackBox = card.querySelector('[data-region="feedback"]');
            var shown = feedbackBox && !feedbackBox.classList.contains('tarsus-hidden');
            if (shown) {
                focusOn(feedbackBox.querySelector('[data-region="verdict-heading"]') || feedbackBox);
                self.announce(readableText(feedbackBox).trim() + ' ' + self.scoreText());
            } else {
                focusOn(card.querySelector('[data-region="question-heading"]') || card.querySelector('.tarsus-practice-card__question'));
                self.announce(self.verdictText(result.correct, result.score) + ' ' + self.scoreText());
            }
        }).catch(function (error) {
            check.disabled = false;
            show(spinner, false);
            self.alert(error.message || self.t('unexpected_error'));
            check.focus();
        });
    };

    // --- modes, reply chip, asking -------------------------------------

    Chat.prototype.setMode = function (mode, quiet) {
        if (this.state.mode === 'discuss' && mode !== 'discuss' && this.state.live) {
            // Leaving the live interaction ends the call; a call the
            // learner already hung up needs no question.
            if (this.state.live.getAttribute('data-call') !== 'ended' && !window.confirm(this.t('live_end_confirm'))) {
                return;
            }
            this.endLive();
        }
        this.state.mode = mode;
        this.qa('.tarsus-mode-switch__btn').forEach(function (btn) {
            var active = btn.getAttribute('data-mode') === mode;
            btn.classList.toggle('tarsus-mode-switch__btn--active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        var ask = mode === 'ask';
        var live = mode === 'discuss';
        var spoken = ask && this.state.voiceInput;
        var input = this.q('.tarsus-chat-input');
        var submit = this.q('.tarsus-chat-submit');
        var next = this.q('[data-action="next"]');
        if (input) {
            show(input, ask && !spoken);
            input.disabled = !ask || spoken;
        }
        show(this.q('[data-region="voice"]'), spoken);
        show(this.q('[data-action="voice-toggle"]'), ask && Boolean(this.config.voice));
        show(this.q('[data-region="live-bar"]'), live);
        show(submit, ask);
        if (submit && ask) {
            submit.disabled = spoken && this.voice.state !== 'ready';
        }
        show(next, !ask && !live);
        var chip = this.q('[data-region="reply-chip"]');
        show(chip, ask && Boolean(this.state.replyTo));
        this.renderBadge();
        this.renderProgressBar();
        this.updateNextLabel();
        if (!quiet) {
            this.announce(this.t('mode_' + mode) + '. ' + this.modeHint(mode));
        }
    };

    Chat.prototype.setReply = function (reply) {
        this.state.replyTo = reply && (reply.id || reply.stack) ? reply : null;
        var chip = this.q('[data-region="reply-chip"]');
        if (!chip) {
            return;
        }
        var title = chip.querySelector('[data-region="reply-title"]');
        if (title) {
            title.textContent = this.state.replyTo ? this.state.replyTo.title : '';
        }
        show(chip, Boolean(this.state.replyTo) && this.state.mode === 'ask');
    };

    function shorten(text, max) {
        var clean = (text || '').replace(/\s+/g, ' ').trim();
        if (clean.length <= max) {
            return clean;
        }
        var cut = clean.slice(0, max);
        var space = cut.lastIndexOf(' ');
        return (space > max / 2 ? cut.slice(0, space) : cut) + '…';
    }

    /**
     * "Ask about this": a question about a whole learning unit refers to
     * its stack, one about a question card or an answer to that widget.
     */
    Chat.prototype.askAbout = function (card) {
        var reply = null;
        if (card.matches('[data-region="practice-card"]')) {
            var id = card.getAttribute('data-widget-id') || '';
            var question = card.querySelector('.tarsus-practice-card__question');
            reply = id ? {id: id, title: shorten(readableText(question), 90)} : null;
        } else if (card.matches('[data-region="learn-card"]')) {
            var stack = card.getAttribute('data-stack-id') || '';
            var slide = card.querySelector('[data-region="slide"]:not(.tarsus-hidden)');
            var label = slide ? (slide.getAttribute('data-slide-title') || '') : '';
            reply = stack ? {stack: stack, title: label !== '' ? label : this.t('learn_ask_about_unit')} : null;
        } else {
            var widget = card.getAttribute('data-widget-id') || '';
            var asked = card.querySelector('.tarsus-card__question-text');
            reply = widget ? {id: widget, title: shorten(readableText(asked), 90)} : null;
        }
        if (!reply) {
            return;
        }
        this.setMode('ask', true);
        this.setReply(reply);
        this.announce(this.t('chat_reply_about', {title: reply.title}));
        var input = this.q('.tarsus-chat-input');
        if (input && !this.state.voiceInput) {
            input.focus();
        }
    };

    Chat.prototype.handleSubmit = function (event) {
        var self = this;
        event.preventDefault();
        if (this.state.voiceInput) {
            this.voiceSend();
            return;
        }
        var input = this.q('.tarsus-chat-input');
        var submit = this.q('.tarsus-chat-submit');
        var spinner = this.q('[data-region="send-spinner"]');
        if (!input || !submit) {
            return;
        }
        var query = input.value.trim();
        if (query === '') {
            return;
        }
        // Read-only rather than disabled: the field keeps the keyboard focus
        // (and a screen reader its place) while Tarsus answers.
        input.readOnly = true;
        input.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        show(spinner, true);
        this.alert('');
        input.value = '';
        this.autosize(input);
        var reply = this.state.replyTo;

        var processing = this.startProcessing('ask', false);
        var unlock = function () {
            processing.stop();
            input.readOnly = false;
            input.removeAttribute('aria-busy');
            submit.disabled = false;
            show(spinner, false);
            input.focus();
        };
        this.call('ask', {query: query, replyto: reply && reply.id ? reply.id : '', replystack: reply && reply.stack ? reply.stack : ''}).then(function (result) {
            unlock();
            if (result.status !== 'success') {
                input.value = query;
                self.autosize(input);
                self.alert(result.error || self.t('unexpected_error'));
                return;
            }
            (result.messages || []).forEach(function (message) {
                self.attach(self.noticeCard('', message.title, message.text), null, true);
            });
            var answered = self.attach(self.exchangeCard(result.question || query, result.answer, reply ? reply.title : '', result.html || '',
                {id: result.widgetid, state: 'ask', sources: result.sources}), null, true);
            self.setReply(null);
            self.scrollToEnd();
            self.announceAnswer(answered);
        }).catch(function (error) {
            unlock();
            input.value = query;
            self.autosize(input);
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    Chat.prototype.autosize = function (input) {
        input.style.height = 'auto';
        input.style.height = Math.min(160, Math.max(38, input.scrollHeight)) + 'px';
    };

    // --- widgets sent again (409) ---------------------------------------

    /** The card already on the page for a widget, unit or farewell, if any. */
    Chat.prototype.findShown = function (card) {
        var flow = this.flow();
        if (!flow) {
            return null;
        }
        var escape = window.CSS && CSS.escape ? CSS.escape : function (value) {
            return String(value);
        };
        var stack = card.getAttribute('data-stack-id');
        if (stack) {
            return flow.querySelector('[data-region="learn-card"][data-stack-id="' + escape(stack) + '"]');
        }
        var id = card.getAttribute('data-widget-id');
        if (id) {
            return flow.querySelector('[data-widget-id="' + escape(id) + '"]');
        }
        var question = card.querySelector('[data-region="practice-card"][data-widget-id]');
        if (question) {
            var shown = flow.querySelector('[data-region="practice-card"][data-widget-id="' + escape(question.getAttribute('data-widget-id')) + '"]');
            return shown ? (shown.closest('[data-region="learn-card"]') || shown) : null;
        }
        return null;
    };

    Chat.prototype.flash = function (card) {
        card.classList.remove('tarsus-card--flash');
        void card.offsetWidth;
        card.classList.add('tarsus-card--flash');
    };

    // --- spoken questions ----------------------------------------------
    //
    // Ask a question can be spoken: the recording goes to Tarsus, which
    // turns it into text and answers it; the text comes back as the
    // question. Recording, pausing, listening back and discarding happen
    // in the browser.

    Chat.prototype.voiceSupported = function () {
        return Boolean(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    };

    Chat.prototype.voiceRegion = function () {
        return this.q('[data-region="voice"]');
    };

    Chat.prototype.setVoiceInput = function (on) {
        this.state.voiceInput = Boolean(on) && Boolean(this.config.voice);
        var toggle = this.q('[data-action="voice-toggle"]');
        if (toggle) {
            var key = this.state.voiceInput ? 'voice_to_text' : 'voice_to_voice';
            toggle.setAttribute('title', this.t(key));
            toggle.setAttribute('aria-label', this.t(key));
            toggle.querySelector('[data-region="voice-toggle-label"]').textContent = this.t(key);
            show(toggle.querySelector('[data-region="voice-toggle-mic"]'), !this.state.voiceInput);
            show(toggle.querySelector('[data-region="voice-toggle-text"]'), this.state.voiceInput);
        }
        if (!this.state.voiceInput) {
            this.voiceDiscard();
        } else {
            this.setVoiceState(this.voice.state);
        }
        this.setMode(this.state.mode, true);
    };

    Chat.prototype.setVoiceState = function (name) {
        var region = this.voiceRegion();
        this.voice.state = name;
        if (!region) {
            return;
        }
        region.setAttribute('data-state', name);
        var buttons = {
            start: ['idle'],
            pause: ['recording'],
            resume: ['paused'],
            stop: ['recording', 'paused'],
            play: ['ready'],
            discard: ['recording', 'paused', 'ready']
        };
        Object.keys(buttons).forEach(function (button) {
            show(region.querySelector('[data-action="voice-' + button + '"]'), buttons[button].indexOf(name) !== -1);
        });
        var label = {idle: 'voice_question', recording: 'voice_recording', paused: 'voice_paused', ready: 'voice_ready'}[name] || 'voice_question';
        region.querySelector('[data-region="voice-state"]').textContent = this.t(label);
        var submit = this.q('.tarsus-chat-submit');
        if (submit && this.state.voiceInput && this.state.mode === 'ask') {
            submit.disabled = name !== 'ready';
        }
    };

    /** The longest recording: the configured limit, and what the server accepts. */
    Chat.prototype.voiceLimit = function () {
        var seconds = this.config.voiceMaxSeconds || 180;
        if (this.config.voiceMaxBytes > 0) {
            // Recorded at 32 kbit/s, about 4 KB per second; a tenth is kept
            // for the rest of the request.
            seconds = Math.min(seconds, Math.floor(this.config.voiceMaxBytes * 0.9 / 4000));
        }
        return Math.max(5, seconds);
    };

    Chat.prototype.renderVoiceTime = function () {
        var region = this.voiceRegion();
        if (region) {
            region.querySelector('[data-region="voice-time"]').textContent = clock(this.voice.elapsed);
        }
    };

    Chat.prototype.voiceStart = function () {
        var self = this;
        if (!this.voiceSupported()) {
            this.alert(this.t('voice_unsupported'));
            return;
        }
        this.alert('');
        navigator.mediaDevices.getUserMedia({audio: true}).then(function (stream) {
            var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/ogg'];
            var type = '';
            for (var i = 0; i < types.length; i++) {
                if (typeof MediaRecorder.isTypeSupported === 'function' && MediaRecorder.isTypeSupported(types[i])) {
                    type = types[i];
                    break;
                }
            }
            var recorder;
            try {
                recorder = new MediaRecorder(stream, type ? {mimeType: type, audioBitsPerSecond: 32000} : {audioBitsPerSecond: 32000});
            } catch (e) {
                recorder = new MediaRecorder(stream);
            }
            self.voice.stream = stream;
            self.voice.recorder = recorder;
            self.voice.chunks = [];
            self.voice.blob = null;
            self.voice.elapsed = 0;
            recorder.addEventListener('dataavailable', function (event) {
                if (event.data && event.data.size) {
                    self.voice.chunks.push(event.data);
                }
            });
            recorder.addEventListener('stop', function () {
                self.voiceRelease();
                if (self.voice.discarding) {
                    self.voice.discarding = false;
                    return;
                }
                self.voice.blob = new Blob(self.voice.chunks, {type: recorder.mimeType || type || 'audio/webm'});
                if (self.voice.url) {
                    URL.revokeObjectURL(self.voice.url);
                }
                self.voice.url = URL.createObjectURL(self.voice.blob);
                self.voiceRegion().querySelector('[data-region="voice-audio"]').src = self.voice.url;
                self.setVoiceState('ready');
            });
            recorder.start(1000);
            self.voice.timer = window.setInterval(function () {
                if (self.voice.state !== 'recording') {
                    return;
                }
                self.voice.elapsed += 1;
                self.renderVoiceTime();
                if (self.voice.elapsed >= self.voiceLimit()) {
                    self.alert(self.t('voice_limit', {seconds: self.voiceLimit()}));
                    self.voiceStop();
                }
            }, 1000);
            self.renderVoiceTime();
            self.setVoiceState('recording');
        }).catch(function (error) {
            var name = error && error.name;
            if (name === 'NotAllowedError' || name === 'SecurityError') {
                self.alert(self.t('mic_denied'));
            } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                self.alert(self.t('mic_notfound'));
            } else {
                self.alert(self.t('mic_failed'));
            }
        });
    };

    Chat.prototype.voicePause = function () {
        var recorder = this.voice.recorder;
        if (recorder && recorder.state === 'recording' && typeof recorder.pause === 'function') {
            recorder.pause();
            this.setVoiceState('paused');
        }
    };

    Chat.prototype.voiceResume = function () {
        var recorder = this.voice.recorder;
        if (recorder && recorder.state === 'paused') {
            recorder.resume();
            this.setVoiceState('recording');
        }
    };

    Chat.prototype.voiceStop = function () {
        var recorder = this.voice.recorder;
        if (recorder && recorder.state !== 'inactive') {
            recorder.stop();
        }
    };

    /** Let go of the microphone and the clock. */
    Chat.prototype.voiceRelease = function () {
        if (this.voice.timer) {
            window.clearInterval(this.voice.timer);
            this.voice.timer = null;
        }
        if (this.voice.stream) {
            this.voice.stream.getTracks().forEach(function (track) {
                track.stop();
            });
            this.voice.stream = null;
        }
    };

    Chat.prototype.voiceDiscard = function () {
        var recorder = this.voice.recorder;
        if (recorder && recorder.state !== 'inactive') {
            this.voice.discarding = true;
            recorder.stop();
        }
        this.voiceRelease();
        this.voice.recorder = null;
        this.voice.blob = null;
        this.voice.chunks = [];
        this.voice.elapsed = 0;
        if (this.voice.url) {
            URL.revokeObjectURL(this.voice.url);
            this.voice.url = null;
        }
        var region = this.voiceRegion();
        if (region) {
            var audio = region.querySelector('[data-region="voice-audio"]');
            audio.pause();
            audio.removeAttribute('src');
        }
        this.renderVoiceTime();
        this.setVoiceState('idle');
    };

    Chat.prototype.voicePlay = function () {
        var audio = this.voiceRegion().querySelector('[data-region="voice-audio"]');
        if (audio.getAttribute('src')) {
            if (audio.paused) {
                audio.currentTime = 0;
                audio.play().catch(noop);
            } else {
                audio.pause();
            }
        }
    };

    Chat.prototype.voiceSend = function () {
        var self = this;
        if (!this.voice.blob || this.voice.state !== 'ready') {
            return;
        }
        var blob = this.voice.blob;
        var duration = this.voice.elapsed;
        // The answer card plays the recording back, so its address stays.
        var url = this.voice.url;
        this.voice.url = null;
        this.voice.blob = null;
        var reply = this.state.replyTo;
        var extension = blob.type.indexOf('mp4') !== -1 ? 'm4a' : (blob.type.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
        var form = new FormData();
        form.append('payload', JSON.stringify({replyto: reply && reply.id ? reply.id : '', replystack: reply && reply.stack ? reply.stack : ''}));
        form.append('audio', blob, 'question.' + extension);

        var audio = this.voiceRegion().querySelector('[data-region="voice-audio"]');
        audio.pause();
        audio.removeAttribute('src');
        this.voice.elapsed = 0;
        this.renderVoiceTime();
        this.setVoiceState('idle');

        var submit = this.q('.tarsus-chat-submit');
        var spinner = this.q('[data-region="send-spinner"]');
        if (submit) {
            submit.disabled = true;
        }
        show(spinner, true);
        this.alert('');
        var processing = this.startProcessing('voice', false);
        var unlock = function () {
            processing.stop();
            show(spinner, false);
            self.setVoiceState(self.voice.state);
        };
        postForm(this.config.endpoints.askVoice, form, this.strings).then(function (result) {
            unlock();
            if (result.status !== 'success') {
                self.alert(result.error || self.t('unexpected_error'));
                return;
            }
            (result.messages || []).forEach(function (message) {
                self.attach(self.noticeCard('', message.title, message.text), null, true);
            });
            var answered = self.attach(self.exchangeCard(result.question || self.t('voice_question'), result.answer, reply ? reply.title : '', result.html || '',
                {id: result.widgetid, state: 'ask', sources: result.sources, voice: {src: url, duration: duration}}), null, true);
            self.setReply(null);
            self.scrollToEnd();
            self.announceAnswer(answered);
        }).catch(function (error) {
            unlock();
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    // --- live interaction ----------------------------------------------
    //
    // Tarsus sends the whole call as one page (a genericLiveWidget). Its
    // contract: shown in a frame without sandbox that may use the
    // microphone - the page is Tarsus's own code; the AI-made slides in it
    // run in their own sandbox. The page tells when its call has expired.

    Chat.prototype.setLiveLabel = function (key) {
        var label = this.q('[data-region="live-label"]');
        if (label) {
            label.textContent = this.t(key);
        }
    };

    Chat.prototype.startLive = function () {
        var self = this;
        var button = this.q('[data-action="live-start"]');
        if (!button || button.disabled) {
            return;
        }
        if (this.state.live) {
            // "New conversation": the running one ends first.
            this.endLive(true);
        }
        button.disabled = true;
        show(this.q('[data-region="live-spinner"]'), true);
        this.alert('');
        this.call('discuss', {}).then(function (result) {
            button.disabled = false;
            show(self.q('[data-region="live-spinner"]'), false);
            if (result.status !== 'success' || !result.page) {
                self.alert(result.error || self.t('unexpected_error'));
                return;
            }
            var frame = el('iframe', {'class': 'tarsus-live__frame', allow: 'microphone; autoplay', title: self.t('live_title')});
            frame.setAttribute('srcdoc', result.page);
            var card = el('div', {'class': 'tarsus-card tarsus-live', 'data-region': 'live-card'}, [
                el('div', {'class': 'tarsus-live__notice tarsus-hidden', 'data-region': 'live-notice', role: 'status'}),
                frame
            ]);
            self.state.live = card;
            self.attach(card, null, true);
            self.scrollToCard(card);
            focusOn(frame);
            self.setLiveLabel('live_new');
        }).catch(function (error) {
            button.disabled = false;
            show(self.q('[data-region="live-spinner"]'), false);
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    /** End the call; what the coach answered is saved by Tarsus and read back. */
    Chat.prototype.endLive = function (keepFeed) {
        var card = this.state.live;
        this.state.live = null;
        if (card && card.parentNode) {
            card.parentNode.removeChild(card);
        }
        this.setLiveLabel('live_start');
        if (!keepFeed) {
            this.loadFeed();
        }
    };

    Chat.prototype.onLiveMessage = function (event) {
        var card = this.state.live;
        var data = event.data;
        if (!card || !data || typeof data !== 'object') {
            return;
        }
        var frame = card.querySelector('iframe');
        if (!frame || event.source !== frame.contentWindow) {
            return;
        }
        if (data.type === 'genericLiveWidget:state') {
            // The call page reports its state: Connecting, Connected, and
            // Disconnected (hung up, or ended by the coach) or None (failed).
            var ended = data.state === 'Disconnected' || data.state === 'None';
            card.setAttribute('data-call', ended ? 'ended' : 'running');
        }
        if (data.type === 'genericLiveWidget:expired') {
            var notice = card.querySelector('[data-region="live-notice"]');
            notice.textContent = this.t('live_expired');
            show(notice, true);
            card.setAttribute('data-call', 'ended');
            this.setLiveLabel('live_new');
        }
    };

    // --- the panel sliding in from the right ---------------------------

    Chat.prototype.openDrawer = function (title, wide) {
        var drawer = this.q('[data-region="drawer"]');
        if (!drawer) {
            return el('div');
        }
        drawer.querySelector('[data-region="drawer-title"]').textContent = title;
        var body = drawer.querySelector('[data-region="drawer-body"]');
        body.innerHTML = '';
        drawer.classList.toggle('tarsus-drawer--wide', Boolean(wide));
        this.lastFocus = document.activeElement;
        drawer.hidden = false;
        window.requestAnimationFrame(function () {
            drawer.classList.add('tarsus-drawer--open');
            drawer.querySelector('.tarsus-drawer__panel').focus();
        });
        document.documentElement.classList.add('tarsus-drawer-lock');
        return body;
    };

    Chat.prototype.closeDrawer = function () {
        var drawer = this.q('[data-region="drawer"]');
        if (!drawer || drawer.hidden) {
            return;
        }
        drawer.classList.remove('tarsus-drawer--open');
        window.setTimeout(function () {
            if (!drawer.classList.contains('tarsus-drawer--open')) {
                drawer.hidden = true;
            }
        }, 220);
        document.documentElement.classList.remove('tarsus-drawer-lock');
        if (this.lastFocus && typeof this.lastFocus.focus === 'function') {
            this.lastFocus.focus();
        }
    };

    Chat.prototype.drawerLoading = function (body) {
        body.innerHTML = '';
        body.appendChild(el('div', {'class': 'tarsus-drawer__loading'}, [
            el('span', {'class': 'tarsus-spinner', 'aria-hidden': 'true'}),
            el('span', {text: this.t('loading')})
        ]));
    };

    Chat.prototype.drawerError = function (body, message) {
        body.innerHTML = '';
        body.appendChild(el('p', {'class': 'tarsus-drawer__error', role: 'alert', text: message || this.t('unexpected_error')}));
    };

    // --- sources of an answer ------------------------------------------

    var SOURCE_TYPES = ['text', 'table', 'image', 'audio', 'video', 'question', 'form'];
    var SOURCE_ICONS = {text: 'file', table: 'table', image: 'image', audio: 'audio', video: 'video', question: 'mode_ask', form: 'mode_test'};

    Chat.prototype.openSources = function (widgetid, state) {
        var self = this;
        var body = this.openDrawer(this.t('sources_title'));
        this.drawerLoading(body);
        this.call('references', {widgetid: widgetid, state: state || 'ask'}).then(function (result) {
            if (result.status !== 'success') {
                self.drawerError(body, result.error);
                return;
            }
            body.innerHTML = '';
            var bits = result.bits || [];
            if (!bits.length) {
                body.appendChild(el('p', {'class': 'tarsus-muted', text: self.t('sources_none')}));
                return;
            }
            body.appendChild(el('p', {'class': 'tarsus-drawer__lead', text: self.t('sources_count', {count: bits.length})}));
            bits.forEach(function (bit) {
                body.appendChild(self.sourceItem(bit));
            });
            typeset(body);
        }).catch(function (error) {
            self.drawerError(body, error.message);
        });
    };

    /** One source, shown by its type: text, table, picture, transcript of audio or video, question. */
    Chat.prototype.sourceItem = function (bit) {
        var type = SOURCE_TYPES.indexOf(bit.type) !== -1 ? bit.type : 'other';
        var head = el('div', {'class': 'tarsus-source__head'}, [
            el('span', {'class': 'tarsus-source__type', html: this.icon(SOURCE_ICONS[type] || 'file')}, el('span', {text: this.t('source_type_' + type)})),
            bit.title ? el('span', {'class': 'tarsus-source__title', text: bit.title}) : null,
            bit.link ? el('a', {'class': 'tarsus-source__open', href: bit.link, target: '_top', html: this.icon('link')}, el('span', {text: this.t('sources_open')})) : null
        ]);
        var content = el('div', {'class': 'tarsus-source__content'});
        if (type === 'table' && bit.rows && bit.rows.length) {
            var rows = bit.rows;
            content.appendChild(el('div', {'class': 'tarsus-source__table-wrap'}, el('table', {'class': 'tarsus-source__table'}, [
                el('thead', {}, el('tr', {}, rows[0].map(function (cell) {
                    return el('th', {text: cell});
                }))),
                el('tbody', {}, rows.slice(1).map(function (row) {
                    return el('tr', {}, row.map(function (cell) {
                        return el('td', {text: cell});
                    }));
                }))
            ])));
        } else if (type === 'image' && bit.image) {
            content.appendChild(el('img', {'class': 'tarsus-source__image', src: bit.image, alt: bit.title || '', tabindex: '0', role: 'button',
                'data-action': 'enlarge', title: this.t('learn_image_enlarge'),
                'aria-label': this.t('learn_image_enlarge') + (bit.title ? ': ' + bit.title : '')}));
        }
        if ((type === 'audio' || type === 'video') && bit.text) {
            content.appendChild(el('div', {'class': 'tarsus-source__label', text: this.t('sources_transcript')}));
        }
        if (bit.text && type !== 'table') {
            content.appendChild(el('div', {'class': 'tarsus-source__text', html: bit.text}));
        }
        if (bit.subtitle) {
            content.appendChild(el('div', {'class': 'tarsus-source__subtitle', text: bit.subtitle}));
        }
        return el('article', {'class': 'tarsus-source tarsus-source--' + type}, [head, content]);
    };

    // --- teacher: settings ---------------------------------------------

    Chat.prototype.teacherCall = function (endpoint, payload) {
        return post(this.config.teacher.endpoints[endpoint], payload, this.strings);
    };

    Chat.prototype.openSettingsPanel = function () {
        var self = this;
        var body = this.openDrawer(this.t('panel_settings'), true);
        this.drawerLoading(body);
        this.teacherCall('settings', {}).then(function (result) {
            if (result.status !== 'success') {
                self.drawerError(body, result.error);
                return;
            }
            body.innerHTML = '';
            var form = el('form', {'class': 'tarsus-settings', novalidate: true});
            (result.sections || []).forEach(function (section) {
                var box = el('section', {'class': 'tarsus-settings__section'}, [
                    el('h3', {'class': 'tarsus-settings__title', text: section.title}),
                    section.help ? el('p', {'class': 'tarsus-settings__help', text: section.help}) : null
                ]);
                (section.fields || []).forEach(function (field) {
                    box.appendChild(self.settingsField(field));
                });
                form.appendChild(box);
            });
            var status = el('span', {'class': 'tarsus-settings__status', role: 'status'});
            var save = el('button', {type: 'submit', 'class': 'tarsus-btn tarsus-btn--primary', text: self.t('settings_save')});
            form.appendChild(el('div', {'class': 'tarsus-settings__footer'}, [
                save, status,
                el('a', {'class': 'tarsus-settings__full', href: self.config.teacher.settingsUrl, text: self.t('settings_open_full')})
            ]));
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                save.disabled = true;
                status.textContent = self.t('settings_saving');
                form.querySelectorAll('.tarsus-settings__error').forEach(function (node) {
                    node.remove();
                });
                self.teacherCall('saveSettings', {values: self.settingsValues(form)}).then(function (saved) {
                    save.disabled = false;
                    if (saved.status !== 'success') {
                        status.textContent = saved.error || self.t('form_invalid');
                        Object.keys(saved.errors || {}).forEach(function (name) {
                            var field = form.querySelector('[data-field="' + name + '"]');
                            if (field) {
                                field.appendChild(el('div', {'class': 'tarsus-settings__error', role: 'alert', text: saved.errors[name]}));
                            }
                        });
                        return;
                    }
                    status.textContent = saved.message || self.t('settings_saved');
                    // Modes and switches change the coach itself.
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                }).catch(function (error) {
                    save.disabled = false;
                    status.textContent = error.message || self.t('unexpected_error');
                });
            });
            body.appendChild(form);
        }).catch(function (error) {
            self.drawerError(body, error.message);
        });
    };

    Chat.prototype.settingsField = function (field) {
        var wrap = el('div', {'class': 'tarsus-settings__field tarsus-settings__field--' + field.type, 'data-field': field.name});
        var locked = Boolean(field.locked);
        var label = el('div', {'class': 'tarsus-settings__label', text: field.label});
        if (field.type === 'checkbox') {
            wrap.appendChild(el('label', {'class': 'tarsus-settings__check'}, [
                el('input', {type: 'checkbox', name: field.name, checked: Boolean(field.value), disabled: locked}),
                el('span', {'class': 'tarsus-settings__label', text: field.label})
            ]));
        } else if (field.type === 'radio' || field.type === 'cards') {
            if (field.type === 'radio') {
                wrap.appendChild(label);
            }
            var group = el('div', {'class': field.type === 'cards' ? 'tarsus-settings__cards' : 'tarsus-settings__radios', role: 'radiogroup',
                'aria-label': field.label});
            (field.options || []).forEach(function (option) {
                group.appendChild(el('label', {'class': field.type === 'cards' ? 'tarsus-settings__card' : 'tarsus-settings__radio'}, [
                    el('input', {type: 'radio', name: field.name, value: option.value, checked: String(field.value) === String(option.value),
                        disabled: Boolean(option.locked)}),
                    el('span', {'class': 'tarsus-settings__option'}, [
                        el('strong', {text: option.label}),
                        option.help ? el('span', {text: option.help}) : null,
                        option.lockedtext ? el('em', {text: option.lockedtext}) : null
                    ])
                ]));
            });
            wrap.appendChild(group);
        } else if (field.type === 'select') {
            wrap.appendChild(label);
            var select = el('select', {'class': 'tarsus-input', name: field.name, 'aria-label': field.label});
            (field.options || []).forEach(function (option) {
                select.appendChild(el('option', {value: option.value, text: option.label, selected: String(field.value) === String(option.value)}));
            });
            wrap.appendChild(select);
        } else if (field.type === 'multi') {
            wrap.appendChild(label);
            var values = (field.value || []).map(String);
            var list = el('div', {'class': 'tarsus-settings__multi'});
            (field.options || []).forEach(function (option) {
                list.appendChild(el('label', {'class': 'tarsus-settings__check'}, [
                    el('input', {type: 'checkbox', value: option.value, checked: values.indexOf(String(option.value)) !== -1}),
                    el('span', {text: option.label})
                ]));
            });
            wrap.appendChild(list);
        } else {
            wrap.appendChild(label);
            var input = el('input', {'class': 'tarsus-input', name: field.name, type: field.type === 'number' ? 'number' : 'text',
                value: field.value, min: field.min, max: field.max, maxlength: field.maxlength, 'aria-label': field.label});
            wrap.appendChild(field.suffix ? el('span', {'class': 'tarsus-settings__suffixed'}, [input, el('span', {text: field.suffix})]) : input);
        }
        if (field.help) {
            wrap.appendChild(el('div', {'class': 'tarsus-settings__help', text: field.help}));
        }
        if (locked && field.lockedtext) {
            wrap.appendChild(el('div', {'class': 'tarsus-settings__locked', text: field.lockedtext}));
        }
        return wrap;
    };

    /** The panel's values; a locked switch sends nothing and keeps its value. */
    Chat.prototype.settingsValues = function (form) {
        var values = {};
        form.querySelectorAll('[data-field]').forEach(function (wrap) {
            var name = wrap.getAttribute('data-field');
            if (wrap.classList.contains('tarsus-settings__field--multi')) {
                values[name] = [];
                wrap.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
                    if (box.checked) {
                        values[name].push(box.value);
                    }
                });
            } else if (wrap.classList.contains('tarsus-settings__field--checkbox')) {
                var box = wrap.querySelector('input[type="checkbox"]');
                if (box && !box.disabled) {
                    values[name] = box.checked;
                }
            } else if (wrap.classList.contains('tarsus-settings__field--radio') || wrap.classList.contains('tarsus-settings__field--cards')) {
                var checked = wrap.querySelector('input[type="radio"]:checked');
                if (checked) {
                    values[name] = checked.value;
                }
            } else {
                var input = wrap.querySelector('input, select');
                if (input) {
                    values[name] = input.value;
                }
            }
        });
        return values;
    };

    // --- teacher: evaluation -------------------------------------------

    var LP_COMPLETED = 2;
    var LP_IN_PROGRESS = 1;

    Chat.prototype.loadStats = function () {
        var self = this;
        return this.teacherCall('stats', {}).then(function (result) {
            if (result.status === 'success') {
                self.state.stats = result;
            }
            return result;
        });
    };

    Chat.prototype.openStatsPanel = function () {
        var self = this;
        var body = this.openDrawer(this.t('panel_stats'), true);
        this.drawerLoading(body);
        this.loadStats().then(function (result) {
            if (result.status !== 'success') {
                self.drawerError(body, result.error);
                return;
            }
            self.renderStats(body, result, '');
        }).catch(function (error) {
            self.drawerError(body, error.message);
        });
    };

    Chat.prototype.renderStats = function (body, result, group) {
        var self = this;
        body.innerHTML = '';
        var all = result.learners || [];
        var learners = all.filter(function (learner) {
            return !group || (learner.groups || []).map(String).indexOf(String(group)) !== -1;
        });

        var tools = el('div', {'class': 'tarsus-stats__tools'});
        if ((result.groups || []).length) {
            var select = el('select', {'class': 'tarsus-input tarsus-stats__group', 'aria-label': this.t('stats_group')});
            select.appendChild(el('option', {value: '', text: this.t('stats_group_all')}));
            result.groups.forEach(function (entry) {
                select.appendChild(el('option', {value: String(entry.id), text: entry.title, selected: String(entry.id) === String(group)}));
            });
            select.addEventListener('change', function () {
                self.renderStats(body, result, select.value);
            });
            tools.appendChild(select);
        }
        tools.appendChild(el('a', {'class': 'tarsus-btn tarsus-btn--sm tarsus-stats__export', href: this.config.teacher.exportUrl, html: this.icon('download')},
            el('span', {text: this.t('stats_export')})));
        body.appendChild(tools);
        if (result.warning) {
            body.appendChild(el('p', {'class': 'tarsus-drawer__warning', role: 'status', text: result.warning}));
        }
        if (!learners.length) {
            body.appendChild(el('p', {'class': 'tarsus-muted', text: this.t('stats_none')}));
            return;
        }

        var members = learners.filter(function (learner) {
            return learner.member;
        });
        var counted = members.length ? members : learners;
        var started = counted.filter(function (learner) {
            return learner.started;
        });
        var grades = started.filter(function (learner) {
            return learner.grade !== null && learner.grade !== undefined;
        }).map(function (learner) {
            return learner.grade;
        });
        var completed = counted.filter(function (learner) {
            return learner.status === LP_COMPLETED;
        }).length;
        var inprogress = counted.filter(function (learner) {
            return learner.status === LP_IN_PROGRESS;
        }).length;
        var average = function (values) {
            return values.length ? Math.round(values.reduce(function (a, b) {
                return a + b;
            }, 0) / values.length) : null;
        };
        var avgGrade = average(grades);
        var avgProgress = average(started.map(function (learner) {
            return learner.progress;
        }));

        body.appendChild(el('div', {'class': 'tarsus-stats__kpis'}, [
            this.kpi(String(counted.length), this.t('stats_learners')),
            this.kpi(avgGrade === null ? '–' : avgGrade + ' %', this.t('stats_avg_grade')),
            this.kpi(completed + ' / ' + counted.length, this.t('stats_completed')),
            this.kpi(avgProgress === null ? '–' : avgProgress + ' %', this.t('stats_avg_progress'))
        ]));

        if (result.measurable !== false) {
            body.appendChild(el('div', {'class': 'tarsus-stats__charts'}, [
                el('section', {'class': 'tarsus-stats__chart'}, [
                    el('h3', {text: this.t('stats_distribution')}),
                    el('p', {'class': 'tarsus-stats__chart-help', text: this.t('stats_distribution_help')}),
                    this.histogram(grades)
                ]),
                el('section', {'class': 'tarsus-stats__chart'}, [
                    el('h3', {text: this.t('stats_status')}),
                    el('p', {'class': 'tarsus-stats__chart-help', text: this.t('stats_status_help')}),
                    this.statusBar(completed, inprogress, Math.max(0, counted.length - completed - inprogress))
                ])
            ]));
        }

        var head = el('tr', {}, [
            el('th', {text: this.t('stats_col_name')}),
            el('th', {text: this.t('stats_col_grade')}),
            el('th', {text: this.t('stats_col_progress')}),
            el('th', {text: this.t('stats_col_status')}),
            el('th', {text: this.t('stats_col_build')}),
            el('th', {text: this.t('stats_col_test')}),
            el('th', {text: this.t('stats_col_last')}),
            el('th', {}, srText(this.t('panel_history')))
        ]);
        var rows = learners.map(function (learner) {
            var status = learner.status === LP_COMPLETED ? 'lp_completed' : (learner.status === LP_IN_PROGRESS ? 'lp_in_progress' : 'lp_not_attempted');
            return el('tr', {}, [
                el('td', {'class': 'tarsus-stats__name', text: learner.name}),
                el('td', {'class': 'tarsus-stats__grade', text: learner.grade === null || learner.grade === undefined ? '–' : Math.round(learner.grade) + ' %'}),
                el('td', {text: Math.round(learner.progress || 0) + ' %'}),
                el('td', {}, el('span', {'class': 'tarsus-pill tarsus-pill--' + status, text: self.t(status)})),
                el('td', {}, self.meter(learner.earnedunits, learner.totalunits, self.t('learning_units'), 'units')),
                el('td', {}, self.meter(learner.earnedxp, learner.totalxp, self.t('xp'), 'xp')),
                el('td', {'class': 'tarsus-nowrap', text: learner.lastactivitylabel || '–'}),
                el('td', {}, el('button', {type: 'button', 'class': 'tarsus-link-btn', 'data-action': 'history-open',
                    'data-userid': String(learner.userid), 'data-name': learner.name, text: self.t('stats_history')}))
            ]);
        });
        body.appendChild(el('div', {'class': 'tarsus-stats__table-wrap'}, el('table', {'class': 'tarsus-stats__table'}, [
            el('thead', {}, head),
            el('tbody', {}, rows)
        ])));
    };

    Chat.prototype.kpi = function (value, label) {
        return el('div', {'class': 'tarsus-stats__kpi'}, [
            el('div', {'class': 'tarsus-stats__kpi-value', text: value}),
            el('div', {'class': 'tarsus-stats__kpi-label', text: label})
        ]);
    };

    /** A small bar with "earned / total": shown, never used as the score. */
    Chat.prototype.meter = function (earned, total, unit, kind) {
        var share = total > 0 ? Math.max(0, Math.min(1, earned / total)) : 0;
        var round = function (value) {
            return Math.round((value || 0) * 10) / 10;
        };
        return el('span', {'class': 'tarsus-meter tarsus-meter--' + kind}, [
            el('span', {'class': 'tarsus-meter__track'}, el('span', {'class': 'tarsus-meter__fill', style: 'width:' + Math.round(share * 100) + '%'})),
            el('span', {'class': 'tarsus-meter__text', text: round(earned) + ' / ' + round(total) + ' ' + unit})
        ]);
    };

    var SVG_NS = 'http://www.w3.org/2000/svg';

    function svg(tag, attrs, children) {
        var node = document.createElementNS(SVG_NS, tag);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, String(attrs[key]));
        });
        (children || []).forEach(function (child) {
            if (child) {
                node.appendChild(child);
            }
        });
        return node;
    }

    function svgText(x, y, text, attrs) {
        var node = svg('text', Object.assign({x: x, y: y}, attrs || {}));
        node.textContent = text;
        return node;
    }

    /** How many learners reached which result, in ten bands of ten percent. */
    Chat.prototype.histogram = function (grades) {
        var counts = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        grades.forEach(function (grade) {
            counts[Math.min(9, Math.max(0, Math.floor(grade / 10)))]++;
        });
        var max = Math.max(1, Math.max.apply(null, counts));
        var width = 560;
        var height = 170;
        var left = 30;
        var base = 132;
        var top = 12;
        var band = (width - left - 8) / 10;
        var bar = band - 6;
        var root = svg('svg', {viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'class': 'tarsus-chart', 'aria-label': this.t('stats_distribution')});
        [0, Math.ceil(max / 2), max].forEach(function (tick) {
            var y = base - (tick / max) * (base - top);
            root.appendChild(svg('line', {x1: left, x2: width - 4, y1: y, y2: y, 'class': 'tarsus-chart__grid'}));
            root.appendChild(svgText(left - 8, y + 4, String(tick), {'class': 'tarsus-chart__axis', 'text-anchor': 'end'}));
        });
        counts.forEach(function (count, i) {
            var x = left + i * band + 3;
            if (count > 0) {
                var h = (count / max) * (base - top);
                var y = base - h;
                var r = Math.min(4, h);
                var shape = svg('path', {
                    d: 'M' + x + ' ' + base + 'V' + (y + r) + 'a' + r + ' ' + r + ' 0 0 1 ' + r + ' -' + r + 'h' + (bar - 2 * r) + 'a' + r + ' ' + r + ' 0 0 1 ' + r + ' ' + r + 'V' + base + 'z',
                    'class': 'tarsus-chart__bar'
                });
                var title = svg('title');
                title.textContent = (i * 10) + '–' + ((i + 1) * 10) + ' %: ' + count;
                shape.appendChild(title);
                root.appendChild(shape);
            }
            root.appendChild(svgText(x + bar / 2, base + 16, (i * 10) + '–' + ((i + 1) * 10), {'class': 'tarsus-chart__axis', 'text-anchor': 'middle'}));
        });
        return root;
    };

    /** Completed, in progress and not started, side by side in one bar. */
    Chat.prototype.statusBar = function (completed, inprogress, notstarted) {
        var total = Math.max(1, completed + inprogress + notstarted);
        var parts = [
            {count: completed, key: 'lp_completed', cls: 'completed'},
            {count: inprogress, key: 'lp_in_progress', cls: 'progress'},
            {count: notstarted, key: 'lp_not_attempted', cls: 'notstarted'}
        ];
        var self = this;
        var bar = el('div', {'class': 'tarsus-status-bar', role: 'img',
            'aria-label': parts.map(function (part) {
                return part.count + ' ' + self.t(part.key);
            }).join(', ')});
        parts.forEach(function (part) {
            if (part.count > 0) {
                bar.appendChild(el('span', {'class': 'tarsus-status-bar__part tarsus-status-bar__part--' + part.cls,
                    style: 'flex-grow:' + part.count + ';flex-basis:0', title: part.count + ' ' + self.t(part.key)}));
            }
        });
        var legend = el('div', {'class': 'tarsus-status-bar__legend'}, parts.map(function (part) {
            return el('span', {}, [el('i', {'class': 'tarsus-status-bar__dot tarsus-status-bar__part--' + part.cls}),
                el('span', {text: part.count + ' ' + self.t(part.key)})]);
        }));
        return el('div', {'class': 'tarsus-status'}, [bar, legend]);
    };

    // --- teacher: history (read only) ----------------------------------

    Chat.prototype.openHistoryPanel = function (userid, name) {
        var self = this;
        var body = this.openDrawer(this.t('panel_history'), true);
        if (userid) {
            this.renderHistory(body, userid, name || '');
            return;
        }
        // No learner chosen yet: pick one from the evaluation.
        this.drawerLoading(body);
        var list = this.state.stats ? Promise.resolve(this.state.stats) : this.loadStats();
        list.then(function (result) {
            if (result.status !== 'success') {
                self.drawerError(body, result.error);
                return;
            }
            body.innerHTML = '';
            var learners = (result.learners || []).slice().sort(function (a, b) {
                return (b.started ? 1 : 0) - (a.started ? 1 : 0);
            });
            if (!learners.length) {
                body.appendChild(el('p', {'class': 'tarsus-muted', text: self.t('stats_none')}));
                return;
            }
            var select = el('select', {'class': 'tarsus-input tarsus-history__pick', 'aria-label': self.t('history_pick')});
            select.appendChild(el('option', {value: '', text: self.t('history_pick')}));
            learners.forEach(function (learner) {
                select.appendChild(el('option', {value: String(learner.userid), text: learner.name}));
            });
            var target = el('div', {'class': 'tarsus-history__target'});
            select.addEventListener('change', function () {
                if (select.value) {
                    self.renderHistory(target, parseInt(select.value, 10), select.options[select.selectedIndex].text);
                }
            });
            body.appendChild(el('div', {'class': 'tarsus-stats__tools'}, select));
            body.appendChild(target);
        }).catch(function (error) {
            self.drawerError(body, error.message);
        });
    };

    Chat.prototype.renderHistory = function (body, userid, name) {
        var self = this;
        body.innerHTML = '';
        var header = el('div', {'class': 'tarsus-history__head'}, [
            el('h3', {'class': 'tarsus-history__name', text: this.t('history_of', {name: name})}),
            el('span', {'class': 'tarsus-history__badge', html: this.icon('taxonomy_understanding')}, el('span', {text: this.t('history_readonly')})),
            this.state.stats ? el('button', {type: 'button', 'class': 'tarsus-link-btn', 'data-action': 'panel', 'data-panel': 'stats',
                text: this.t('history_back')}) : null
        ]);
        var side = el('div', {'class': 'tarsus-history__side'});
        var feed = el('div', {'class': 'tarsus-history__feed', 'data-region': 'history-feed'});
        body.appendChild(header);
        body.appendChild(el('div', {'class': 'tarsus-history'}, [side, feed]));

        // The learner's figures and how they grew.
        var learner = null;
        ((this.state.stats && this.state.stats.learners) || []).forEach(function (entry) {
            if (entry.userid === userid) {
                learner = entry;
            }
        });
        if (learner) {
            var status = learner.status === LP_COMPLETED ? 'lp_completed' : (learner.status === LP_IN_PROGRESS ? 'lp_in_progress' : 'lp_not_attempted');
            side.appendChild(el('div', {'class': 'tarsus-history__figures'}, [
                el('div', {'class': 'tarsus-history__grade'}, [
                    el('span', {text: learner.grade === null || learner.grade === undefined ? '–' : Math.round(learner.grade) + ' %'}),
                    el('span', {'class': 'tarsus-pill tarsus-pill--' + status, text: this.t(status)})
                ]),
                el('div', {'class': 'tarsus-history__row'}, [el('span', {text: this.t('stats_col_progress')}), el('span', {text: Math.round(learner.progress || 0) + ' %'})]),
                el('div', {'class': 'tarsus-history__row'}, [el('span', {text: this.t('stats_col_build')}), this.meter(learner.earnedunits, learner.totalunits, this.t('learning_units'), 'units')]),
                el('div', {'class': 'tarsus-history__row'}, [el('span', {text: this.t('stats_col_test')}), this.meter(learner.earnedxp, learner.totalxp, this.t('xp'), 'xp')]),
                el('div', {'class': 'tarsus-history__row'}, [el('span', {text: this.t('stats_col_last')}), el('span', {text: learner.lastactivitylabel || '–'})])
            ]));
        }
        var growth = el('section', {'class': 'tarsus-history__growth'}, [el('h4', {text: this.t('history_growth')})]);
        side.appendChild(growth);
        this.teacherCall('analytics', {userid: userid}).then(function (result) {
            if (result.status !== 'success') {
                growth.appendChild(el('p', {'class': 'tarsus-muted tarsus-small', text: result.error || self.t('unexpected_error')}));
                return;
            }
            growth.appendChild(self.growthChart(result));
        }).catch(noop);

        var offset = 0;
        var more = el('button', {type: 'button', 'class': 'tarsus-btn tarsus-btn--sm tarsus-history__more tarsus-hidden', text: this.t('history_more')});
        var load = function () {
            more.disabled = true;
            var loading = el('div', {'class': 'tarsus-drawer__loading'}, [el('span', {'class': 'tarsus-spinner'}), el('span', {text: self.t('loading')})]);
            feed.insertBefore(loading, feed.firstChild);
            self.teacherCall('history', {userid: userid, offset: offset}).then(function (result) {
                loading.remove();
                more.disabled = false;
                if (result.status !== 'success') {
                    feed.insertBefore(el('p', {'class': 'tarsus-drawer__error', text: result.error || self.t('unexpected_error')}), feed.firstChild);
                    return;
                }
                var items = result.items || [];
                if (!items.length && offset === 0) {
                    feed.appendChild(el('p', {'class': 'tarsus-muted', text: self.t('history_empty')}));
                }
                // Older pages go on top, as in the coach.
                var anchor = more.nextSibling;
                self.buildFeedCards(items).forEach(function (entry) {
                    feed.insertBefore(entry.card, anchor);
                    self.settleFeedCard(entry);
                    self.readOnly(entry.card);
                });
                offset += self.config.pageSize;
                show(more, Boolean(result.hasmore) && items.length > 0);
            }).catch(function (error) {
                loading.remove();
                more.disabled = false;
                feed.insertBefore(el('p', {'class': 'tarsus-drawer__error', text: error.message || self.t('unexpected_error')}), feed.firstChild);
            });
        };
        more.addEventListener('click', load);
        feed.appendChild(more);
        load();
    };

    /** Nothing in the history can be answered, asked about or generated. */
    Chat.prototype.readOnly = function (card) {
        card.classList.add('tarsus-card--readonly');
        card.querySelectorAll('input, textarea, select').forEach(function (input) {
            input.disabled = true;
        });
        card.querySelectorAll('.tarsus-practice-check, [data-action="ask-about"], [data-action="sources"], [data-action="audio-toggle"]').forEach(function (button) {
            button.remove();
        });
    };

    /** XP and learning units reached over time, in percent of all, on one axis. */
    Chat.prototype.growthChart = function (data) {
        var series = [
            {points: data.xp || [], cls: 'xp', label: this.t('history_xp')},
            {points: data.units || [], cls: 'units', label: this.t('history_units')}
        ];
        var start = data.start || 0;
        var end = data.end || 1;
        var width = 360;
        var height = 210;
        var left = 44;
        var right = 14;
        var top = 14;
        var base = 176;
        var x = function (t) {
            return left + (Math.max(start, Math.min(end, t)) - start) / Math.max(1, end - start) * (width - left - right);
        };
        var y = function (v) {
            return base - (Math.max(0, Math.min(100, v)) / 100) * (base - top);
        };
        var root = svg('svg', {viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'class': 'tarsus-chart', 'aria-label': this.t('history_growth')});
        [0, 50, 100].forEach(function (tick) {
            root.appendChild(svg('line', {x1: left, x2: width - right, y1: y(tick), y2: y(tick), 'class': 'tarsus-chart__grid'}));
            root.appendChild(svgText(left - 8, y(tick) + 4, tick + ' %', {'class': 'tarsus-chart__axis', 'text-anchor': 'end'}));
        });
        var day = function (t) {
            var date = new Date(t * 1000);
            return date.getDate() + '.' + (date.getMonth() + 1) + '.';
        };
        root.appendChild(svgText(left, height - 8, day(start), {'class': 'tarsus-chart__axis'}));
        root.appendChild(svgText(width - right, height - 8, day(end), {'class': 'tarsus-chart__axis', 'text-anchor': 'end'}));
        var drawn = false;
        series.forEach(function (entry) {
            var points = entry.points.filter(function (point) {
                return point.x <= end;
            });
            if (!points.length) {
                return;
            }
            drawn = true;
            // A step line: a value holds until it changes, and the last one
            // holds until today.
            var d = '';
            points.forEach(function (point, i) {
                d += (i === 0 ? 'M' : 'H' + x(point.x) + 'V') + (i === 0 ? x(point.x) + ' ' + y(point.y) : y(point.y));
            });
            d += 'H' + x(end);
            root.appendChild(svg('path', {d: d, 'class': 'tarsus-chart__line tarsus-chart__line--' + entry.cls}));
            var last = points[points.length - 1];
            var dot = svg('circle', {cx: x(end), cy: y(last.y), r: 4, 'class': 'tarsus-chart__dot tarsus-chart__line--' + entry.cls});
            var title = svg('title');
            title.textContent = entry.label + ': ' + Math.round(last.y) + ' %';
            dot.appendChild(title);
            root.appendChild(dot);
        });
        var legend = el('div', {'class': 'tarsus-chart__legend'}, series.map(function (entry) {
            return el('span', {}, [el('i', {'class': 'tarsus-chart__key tarsus-chart__line--' + entry.cls}), el('span', {text: entry.label + ' ' + (entry.points.length ? Math.round(entry.points[entry.points.length - 1].y) + ' %' : '–')})]);
        }));
        return el('div', {'class': 'tarsus-growth'}, drawn ? [legend, root] : [el('p', {'class': 'tarsus-muted tarsus-small', text: this.t('history_empty')})]);
    };

    // --- init ----------------------------------------------------------

    Chat.prototype.init = function () {
        var self = this;
        var form = this.q('[data-region="composer"]');
        if (form) {
            form.addEventListener('submit', function (event) {
                self.handleSubmit(event);
            });
        }
        var input = this.q('.tarsus-chat-input');
        if (input) {
            input.addEventListener('input', function () {
                self.autosize(input);
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && self.state.replyTo) {
                    // Escape drops the reference to an earlier card.
                    self.setReply(null);
                    return;
                }
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    if (form && typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        self.handleSubmit(event);
                    }
                }
            });
        }
        this.qa('.tarsus-mode-switch__btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                self.setMode(btn.getAttribute('data-mode'));
            });
        });
        var next = this.q('[data-action="next"]');
        if (next) {
            next.addEventListener('click', function () {
                self.handlePracticeNext();
            });
        }

        var initial = this.modes.indexOf('ask') !== -1 ? 'ask' : (this.modes[0] || 'ask');
        this.setMode(initial, true);

        this.root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var action = target.closest('[data-action]');
            var name = action && self.root.contains(action) ? action.getAttribute('data-action') : '';
            if (name === 'skip-input') {
                event.preventDefault();
                self.focusComposer();
                return;
            }
            if (name === 'drawer-close') {
                self.closeDrawer();
                return;
            }
            if (name === 'sources') {
                self.openSources(action.getAttribute('data-widget-id'), action.getAttribute('data-state'));
                return;
            }
            if (name === 'panel' && self.config.teacher) {
                var panel = action.getAttribute('data-panel');
                if (panel === 'settings') {
                    self.openSettingsPanel();
                } else if (panel === 'stats') {
                    self.openStatsPanel();
                } else if (panel === 'history') {
                    self.openHistoryPanel(0, '');
                }
                return;
            }
            if (name === 'history-open' && self.config.teacher) {
                self.openHistoryPanel(parseInt(action.getAttribute('data-userid'), 10), action.getAttribute('data-name') || '');
                return;
            }
            if (name === 'live-start') {
                self.startLive();
                return;
            }
            if (name === 'voice-toggle') {
                self.setVoiceInput(!self.state.voiceInput);
                return;
            }
            if (name.indexOf('voice-') === 0) {
                var voice = {
                    'voice-start': 'voiceStart', 'voice-pause': 'voicePause', 'voice-resume': 'voiceResume',
                    'voice-stop': 'voiceStop', 'voice-play': 'voicePlay', 'voice-discard': 'voiceDiscard'
                }[name];
                if (voice) {
                    self[voice]();
                }
                return;
            }
            var check = target.closest('.tarsus-practice-check');
            if (check && self.root.contains(check)) {
                var card = check.closest('[data-region="practice-card"]');
                if (card) {
                    self.handlePracticeCheck(card);
                }
                return;
            }
            var nav = target.closest('.tarsus-learn-nav');
            if (nav && self.root.contains(nav)) {
                var learncard = nav.closest('[data-region="learn-card"]');
                if (learncard) {
                    self.turnSlide(learncard, parseInt(nav.getAttribute('data-dir'), 10));
                }
                return;
            }
            var picture = target.closest('[data-action="enlarge"]');
            if (picture && self.root.contains(picture)) {
                self.openLightbox(picture.getAttribute('src'), picture.getAttribute('data-title') || picture.getAttribute('alt') || '');
                return;
            }
            var enlarge = target.closest('[data-action="frame-enlarge"]');
            if (enlarge && self.root.contains(enlarge)) {
                var frame = enlarge.closest('[data-region="frame"]');
                if (frame) {
                    self.openPage(frame.__html || '', frame.__title || '');
                }
                return;
            }
            var toggle = target.closest('[data-action="audio-toggle"]');
            if (toggle && self.root.contains(toggle)) {
                var togglecard = toggle.closest('[data-region="learn-card"]');
                if (togglecard) {
                    self.toggleStackAudio(togglecard);
                }
                return;
            }
            var ask = target.closest('[data-action="ask-about"]');
            if (ask && self.root.contains(ask)) {
                var askcard = ask.closest('[data-region="practice-card"], [data-region="learn-card"], [data-kind="exchange"]');
                if (askcard) {
                    self.askAbout(askcard);
                }
                return;
            }
            var clear = target.closest('[data-action="reply-clear"]');
            if (clear && self.root.contains(clear)) {
                self.setReply(null);
                return;
            }
            var jump = target.closest('[data-action="jump-top"]');
            if (jump && self.root.contains(jump)) {
                self.scrollPage(0);
                // The button hides at the top: focus goes to the first card.
                var firstCard = self.flow() ? self.flow().querySelector('.tarsus-card') : null;
                if (firstCard) {
                    self.focusCard(firstCard);
                }
            }
        });
        this.root.addEventListener('keydown', function (event) {
            if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof Element && event.target.matches('[data-action="enlarge"]')) {
                event.preventDefault();
                self.openLightbox(event.target.getAttribute('src'), event.target.getAttribute('data-title') || event.target.getAttribute('alt') || '');
            }
        });
        // The side panel is a dialog: Tab and Shift+Tab stay inside it.
        this.root.addEventListener('keydown', function (event) {
            var drawer = self.q('.tarsus-drawer');
            if (event.key === 'Tab' && drawer && !drawer.hidden && drawer.contains(event.target)) {
                trapTab(drawer.querySelector('.tarsus-drawer__panel'), event);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                var lightbox = document.querySelector('dialog.tarsus-lightbox[open]');
                if (!lightbox) {
                    self.closeDrawer();
                }
            }
        });
        window.addEventListener('message', function (event) {
            self.onLiveMessage(event);
        });
        window.addEventListener('pagehide', function () {
            self.voiceRelease();
        });

        this.measureChrome();
        window.addEventListener('resize', function () {
            self.measureChrome();
        }, {passive: true});

        this.scroller.listen(function () {
            self.toggleJumpTop();
            if (!self.state.programmaticScroll && self.nearFeedTop()) {
                self.loadOlderFeed();
            }
        });

        this.loadFeed();
    };

    // ------------------------------------------------------------------
    // Teacher's material list
    // ------------------------------------------------------------------

    var BADGE_CLASSES = ['tarsus-badge--neutral', 'tarsus-badge--warning', 'tarsus-badge--muted', 'tarsus-badge--success', 'tarsus-badge--danger', 'tarsus-badge--info'];

    function Dashboard(root, config) {
        this.root = root;
        this.config = config;
        this.strings = config.strings || {};
        this.init();
    }

    Dashboard.prototype.t = function (key, params) {
        return format(this.strings[key] || key, params);
    };

    Dashboard.prototype.call = function (endpoint, payload) {
        return post(this.config.endpoints[endpoint], payload, this.strings);
    };

    Dashboard.prototype.rows = function () {
        return Array.prototype.slice.call(this.root.querySelectorAll('[data-region="resource"]'));
    };

    Dashboard.prototype.message = function (text, isError) {
        var box = this.root.querySelector('[data-region="bulk-message"]');
        if (!box) {
            return;
        }
        box.textContent = text || '';
        box.classList.toggle('tarsus-dashboard__message--error', Boolean(isError));
    };

    Dashboard.prototype.updateRow = function (row, data) {
        if (!row || !data) {
            return;
        }
        var badge = row.querySelector('[data-region="status-badge"]');
        if (badge) {
            badge.textContent = data.statuslabel;
            BADGE_CLASSES.forEach(function (cls) {
                badge.classList.remove(cls);
            });
            String(data.badgeclass || '').split(/\s+/).forEach(function (cls) {
                if (cls) {
                    badge.classList.add(cls);
                }
            });
        }
        var spinner = row.querySelector('[data-region="status-spinner"]');
        if (spinner) {
            spinner.hidden = !data.inflight;
        }
        var map = {index: data.canindex, reindex: data.canreindex, remove: data.canremove, retry: data.canretry};
        row.querySelectorAll('[data-action]').forEach(function (button) {
            var action = button.getAttribute('data-action');
            button.hidden = !map[action];
            button.disabled = false;
        });
        // The inclusion switch is only live for content the brain holds;
        // it keeps showing the teacher's choice meanwhile.
        var toggle = row.querySelector('[data-region="include-toggle"]');
        if (toggle) {
            toggle.disabled = !data.caninclude;
            if (typeof data.included === 'boolean') {
                toggle.checked = data.included;
            }
        }
    };

    Dashboard.prototype.sendAction = function (row, resourceid, action) {
        var self = this;
        row.querySelectorAll('[data-action]').forEach(function (button) {
            button.disabled = true;
        });
        var spinner = row.querySelector('[data-region="status-spinner"]');
        if (spinner) {
            spinner.hidden = false;
        }
        return this.call('action', {resourceid: resourceid, action: action}).then(function (data) {
            self.updateRow(row, data.row);
            if (!data.ok) {
                self.message(data.error || self.t('unexpected_error'), true);
            } else {
                self.message('');
            }
            if (data.row && data.row.inflight) {
                self.scheduleConfirmation();
            }
            return data;
        }).catch(function (error) {
            row.querySelectorAll('[data-action]').forEach(function (button) {
                button.disabled = false;
            });
            if (spinner) {
                spinner.hidden = true;
            }
            self.message(error.message || self.t('unexpected_error'), true);
            return null;
        });
    };

    Dashboard.prototype.sendInclusion = function (row, checkbox) {
        var self = this;
        var resourceid = row.getAttribute('data-resourceid');
        var included = checkbox.checked;
        checkbox.disabled = true;
        return this.call('include', {resourceid: resourceid, included: included}).then(function (data) {
            checkbox.disabled = false;
            if (!data.ok) {
                checkbox.checked = !included;
                self.message(data.error || self.t('unexpected_error'), true);
            } else {
                self.message('');
                self.updateRow(row, data.row);
            }
            return data;
        }).catch(function (error) {
            checkbox.checked = !included;
            checkbox.disabled = false;
            self.message(error.message || self.t('unexpected_error'), true);
            return null;
        });
    };

    Dashboard.prototype.setBulkBusy = function (busy) {
        this.root.querySelectorAll('[data-bulk]').forEach(function (button) {
            button.disabled = busy;
        });
        var spinner = this.root.querySelector('[data-region="bulk-spinner"]');
        if (spinner) {
            spinner.hidden = !busy;
        }
    };

    /** Run one request per row, in sequence, so each stays short. */
    Dashboard.prototype.runQueue = function (rows, task, then) {
        var self = this;
        this.setBulkBusy(true);
        var queue = rows.slice();
        var next = function () {
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
    };

    /**
     * Rows still awaiting the brain's confirmation are asked about once
     * more a moment later, so a quick confirmation shows without a reload.
     */
    Dashboard.prototype.scheduleConfirmation = function () {
        var self = this;
        if (this.confirmTimer) {
            window.clearTimeout(this.confirmTimer);
        }
        this.confirmTimer = window.setTimeout(function () {
            self.confirmTimer = null;
            var pending = self.rows().some(function (row) {
                var spinner = row.querySelector('[data-region="status-spinner"]');
                return spinner && !spinner.hidden;
            });
            if (pending) {
                self.bulkSync(true);
            }
        }, 1500);
    };

    Dashboard.prototype.bulkIndex = function () {
        var self = this;
        var rows = this.rows().filter(function (row) {
            var button = row.querySelector('[data-action="index"]');
            return button && !button.hidden;
        });
        if (!rows.length) {
            return;
        }
        this.runQueue(rows, function (row) {
            return self.sendAction(row, row.getAttribute('data-resourceid'), 'index');
        }, function () {
            self.scheduleConfirmation();
        });
    };

    Dashboard.prototype.bulkInclude = function () {
        var self = this;
        var rows = this.rows().filter(function (row) {
            var toggle = row.querySelector('[data-region="include-toggle"]');
            return toggle && !toggle.disabled && !toggle.checked;
        });
        if (!rows.length) {
            return;
        }
        this.runQueue(rows, function (row) {
            var toggle = row.querySelector('[data-region="include-toggle"]');
            toggle.checked = true;
            return self.sendInclusion(row, toggle);
        });
    };

    Dashboard.prototype.bulkSync = function (silent) {
        var self = this;
        var ids = this.rows().map(function (row) {
            return row.getAttribute('data-resourceid');
        });
        if (!ids.length) {
            return;
        }
        this.setBulkBusy(true);
        this.call('sync', {resourceids: ids.slice(0, this.config.batchLimit || 10)}).then(function (data) {
            (data.rows || []).forEach(function (entry) {
                var row = self.root.querySelector('[data-region="resource"][data-resourceid="' + CSS.escape(entry.resourceid) + '"]');
                self.updateRow(row, entry);
            });
            self.setBulkBusy(false);
            if (!data.ok) {
                if (!silent) {
                    self.message(data.error || self.t('unexpected_error'), true);
                }
            } else if (!silent) {
                self.message(self.t('sync_done'));
            }
        }).catch(function (error) {
            self.setBulkBusy(false);
            if (!silent) {
                self.message(error.message || self.t('unexpected_error'), true);
            }
        });
    };

    Dashboard.prototype.init = function () {
        var self = this;
        this.root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            var bulk = target.closest('[data-bulk]');
            if (bulk && !bulk.disabled) {
                var kind = bulk.getAttribute('data-bulk');
                if (kind === 'index') {
                    self.bulkIndex();
                } else if (kind === 'include') {
                    self.bulkInclude();
                } else {
                    self.bulkSync(false);
                }
                return;
            }
            var button = target.closest('[data-action]');
            if (!button || button.disabled) {
                return;
            }
            var row = button.closest('[data-region="resource"]');
            if (!row) {
                return;
            }
            var resourceid = row.getAttribute('data-resourceid');
            var action = button.getAttribute('data-action');
            if (action === 'remove' && !window.confirm(self.t('confirmremove_body'))) {
                return;
            }
            self.sendAction(row, resourceid, action);
        });
        this.root.addEventListener('change', function (event) {
            var toggle = event.target instanceof Element ? event.target.closest('[data-region="include-toggle"]') : null;
            if (!toggle || toggle.disabled) {
                return;
            }
            var row = toggle.closest('[data-region="resource"]');
            if (row) {
                self.sendInclusion(row, toggle);
            }
        });
        // The listed statuses are read live on open, one call per page.
        this.bulkSync(true);
    };

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    var TarsusIlias = window.TarsusIlias = window.TarsusIlias || {};

    // --- help panel -----------------------------------------------------
    //
    // The help button opens a panel of support links and videos
    // (configured by the administrator). Videos are embedded only once the
    // panel opens, so a page never loads a player the learner did not ask for.

    function bootHelp(panelRoot) {
        var button = panelRoot.querySelector('[data-action="help-toggle"]');
        var panel = panelRoot.querySelector('[data-region="help-panel"]');
        if (!button || !panel || button.getAttribute('data-booted') === '1') {
            return;
        }
        button.setAttribute('data-booted', '1');
        var embedded = false;
        var setOpen = function (open) {
            show(panel, open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && !embedded) {
                embedded = true;
                panel.querySelectorAll('[data-embed]').forEach(function (slot) {
                    var iframe = el('iframe', {
                        'class': 'tarsus-help__video', src: slot.getAttribute('data-embed'), loading: 'lazy',
                        allow: 'accelerometer; encrypted-media; picture-in-picture; fullscreen', allowfullscreen: true,
                        referrerpolicy: 'strict-origin-when-cross-origin', title: slot.getAttribute('data-title') || ''
                    });
                    slot.appendChild(iframe);
                });
            }
        };
        button.addEventListener('click', function () {
            setOpen(panel.classList.contains('tarsus-hidden'));
        });
        panel.querySelectorAll('[data-action="help-close"]').forEach(function (close) {
            close.addEventListener('click', function () {
                setOpen(false);
                button.focus();
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.classList.contains('tarsus-hidden')) {
                var inside = panel.contains(document.activeElement);
                setOpen(false);
                if (inside) {
                    button.focus();
                }
            }
        });
    }

    TarsusIlias.boot = function () {
        document.querySelectorAll('.tarsus-help').forEach(bootHelp);
        document.querySelectorAll('.tarsus-app[data-config]').forEach(function (root) {
            if (root.getAttribute('data-booted') === '1') {
                return;
            }
            root.setAttribute('data-booted', '1');
            var config;
            try {
                config = JSON.parse(root.getAttribute('data-config'));
            } catch (e) {
                return;
            }
            try {
                if (config.kind === 'chat') {
                    new Chat(root, config);
                } else if (config.kind === 'materials') {
                    new Dashboard(root, config);
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
})();
