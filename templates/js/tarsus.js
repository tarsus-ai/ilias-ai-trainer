/**
 * Tarsus for ILIAS - browser side.
 *
 * Two screens share this file: the learner's chat (one interleaved feed
 * of exchange, question and learning-unit cards, three interaction modes)
 * and the teacher's material list (index actions, inclusion switches and
 * the page sync). Both boot from the JSON configuration the server puts
 * on their root element. No framework; MathJax is used when the page has
 * it.
 *
 * @author  lern.link GmbH
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

    /**
     * Render formulas MathJax finds in a freshly inserted node. Without
     * MathJax the TeX delimiters are dropped so the formula at least
     * reads as text.
     */
    function typeset(node, attempt) {
        if (!node) {
            return;
        }
        var mj = window.MathJax;
        attempt = attempt || 0;
        if (!mj) {
            // No MathJax on the page: drop the delimiters right away so the
            // formula at least reads as text. A MathJax script that is
            // still loading gets a few seconds.
            var loading = document.querySelector('script[src*="athjax"], script[src*="athJax"]');
            if (loading && attempt < 10) {
                window.setTimeout(function () {
                    typeset(node, attempt + 1);
                }, 500);
            } else {
                stripTex(node);
            }
            return;
        }
        if (typeof mj.typesetPromise === 'function') {
            mj.typesetPromise([node]).catch(noop);
            return;
        }
        if (mj.Hub && mj.Hub.Queue) {
            mj.Hub.Queue(['Typeset', mj.Hub, node]);
            return;
        }
        if (mj.startup && mj.startup.promise) {
            mj.startup.promise.then(function () {
                typeset(node, attempt + 1);
            }).catch(noop);
            return;
        }
        if (attempt < 20) {
            window.setTimeout(function () {
                typeset(node, attempt + 1);
            }, 300);
        }
    }

    function stripTex(node) {
        node.querySelectorAll('.tarsus-math').forEach(function (span) {
            span.textContent = span.textContent.replace(/\[\/?tex\]/g, '');
        });
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

    function processingSequence(mode, firstRun) {
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
            learnComplete: false,
            feedOffset: 0,
            feedHasMore: false,
            feedLoading: false,
            stacks: {},
            programmaticScroll: false,
            progress: null
        };
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

    Chat.prototype.noticeCard = function (variant, title, html, fallbackTitleKey) {
        var image = this.config.images[variant === 'complete' ? 'success' : (variant === 'motivation' ? 'motivation' : 'welcome')];
        var heading = title || (fallbackTitleKey ? this.t(fallbackTitleKey) : '');
        var card = el('div', {
            'class': 'tarsus-card tarsus-card--welcome' + (variant ? ' tarsus-card--' + variant : ''),
            'data-region': variant === 'complete' ? 'learn-complete' : (variant === 'motivation' ? 'learn-motivation' : 'tarsus-chat-card')
        }, el('div', {'class': 'tarsus-card__inner tarsus-center'}, [
            el('div', {'class': 'tarsus-welcome-art'}, el('img', {src: image, alt: ''})),
            heading ? el('h3', {'class': 'tarsus-card__title', text: heading}) : null,
            el('div', {'class': 'tarsus-card__welcome-text', html: html || ''})
        ]));
        return card;
    };

    Chat.prototype.exchangeCard = function (question, answerHtml, reply) {
        var self = this;
        return el('div', {'class': 'tarsus-card', 'data-region': 'tarsus-chat-card'}, el('div', {'class': 'tarsus-card__inner'}, [
            reply ? el('div', {'class': 'tarsus-card__reply', 'aria-label': this.t('chat_reply_about', {title: reply}), html: this.icon('reply')},
                el('span', {text: reply})) : null,
            el('div', {'class': 'tarsus-card__question'}, [
                this.avatar(this.config.userAvatar, ''),
                el('div', {'class': 'tarsus-card__question-text', text: question})
            ]),
            el('hr', {'class': 'tarsus-card__divider'}),
            el('div', {'class': 'tarsus-card__answer'}, [
                this.avatar(this.config.robotAvatar, this.config.robotName),
                el('div', {'class': 'tarsus-card__answer-body', 'data-region': 'card-answer', html: answerHtml || ''})
            ])
        ]));
    };

    Chat.prototype.avatar = function (src, alt) {
        if (!src) {
            return el('span', {'class': 'tarsus-card__avatar tarsus-card__avatar--empty', 'aria-hidden': 'true'});
        }
        return el('img', {'class': 'tarsus-card__avatar', src: src, alt: alt || ''});
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
        inner.appendChild(el('div', {'class': 'tarsus-practice-card__question', html: widget.question || ''}));

        var choices = widget.choices || [];
        var variables = widget.variables || [];
        var table = widget.table || [];

        if (choices.length) {
            var group = el('div', {'class': 'tarsus-practice-card__choices', 'data-region': 'choices', role: 'radiogroup'});
            choices.forEach(function (choice) {
                group.appendChild(el('label', {'class': 'tarsus-practice-choice', 'data-value': choice.value}, [
                    el('input', {type: 'radio', 'class': 'tarsus-practice-choice__input', name: 'practice-' + widget.id, value: choice.value}),
                    el('span', {'class': 'tarsus-practice-choice__label', text: choice.label}),
                    el('span', {'class': 'tarsus-practice-choice__clarification tarsus-hidden', 'data-region': 'clarification'})
                ]));
            });
            inner.appendChild(group);
        } else if (variables.length) {
            var vars = el('div', {'class': 'tarsus-practice-card__variables', 'data-region': 'variables'});
            variables.forEach(function (name) {
                vars.appendChild(el('label', {'class': 'tarsus-practice-variable'}, [
                    el('span', {'class': 'tarsus-practice-variable__name', text: name}),
                    el('input', {type: 'text', 'class': 'tarsus-practice-variable__input tarsus-input', 'data-variable': name,
                        placeholder: self.t('practice_variable_placeholder'), autocomplete: 'off'})
                ]));
            });
            inner.appendChild(vars);
        } else if (table.length) {
            var tbody = el('tbody');
            table.forEach(function (row) {
                tbody.appendChild(el('tr', {'class': 'tarsus-practice-table__row', 'data-position': row.position}, [
                    el('td', {'class': 'tarsus-practice-table__position', text: row.position}),
                    el('td', {'class': 'tarsus-practice-table__cell', 'data-cell': 'rate'}, row.askrate
                        ? el('input', {type: 'text', 'class': 'tarsus-practice-table__input tarsus-input', 'data-cell': 'rate', autocomplete: 'off'})
                        : el('span', {'class': 'tarsus-practice-table__value', 'data-cell': 'rate', text: row.rate})),
                    el('td', {'class': 'tarsus-practice-table__cell', 'data-cell': 'amount'}, row.askamount
                        ? el('input', {type: 'text', 'class': 'tarsus-practice-table__input tarsus-input', 'data-cell': 'amount', autocomplete: 'off'})
                        : el('span', {'class': 'tarsus-practice-table__value', 'data-cell': 'amount', text: row.amount}))
                ]));
            });
            inner.appendChild(el('div', {'class': 'tarsus-practice-card__table-wrap'}, el('table', {'class': 'tarsus-practice-table', 'data-region': 'table'}, [
                el('thead', {}, el('tr', {}, [
                    el('th', {text: this.t('practice_table_position')}),
                    el('th', {text: this.t('practice_table_rate')}),
                    el('th', {text: this.t('practice_table_amount')})
                ])),
                tbody
            ])));
        } else {
            inner.appendChild(el('textarea', {'class': 'tarsus-practice-card__input tarsus-input', rows: '3',
                placeholder: this.t('practice_answer_placeholder'), 'aria-label': this.t('practice_answer_placeholder')}));
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
        inner.appendChild(el('div', {'class': 'tarsus-practice-card__feedback tarsus-hidden', 'data-region': 'feedback'}));
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
        var band = el('div', {'class': 'tarsus-card__band tarsus-learn-card__meta'});
        band.innerHTML = '<svg class="tarsus-learn-ring" data-region="ring" viewBox="0 0 24 24" width="24" height="24" role="img" aria-live="polite"'
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

        var player = el('span', {'class': 'tarsus-learn-player', 'data-region': 'audio-player', 'data-state': 'idle'}, [
            el('button', {type: 'button', 'class': 'tarsus-learn-player__toggle', 'data-action': 'audio-toggle', title: this.t('learn_audio'), 'aria-label': this.t('learn_audio')}, [
                el('span', {'data-region': 'audio-speaker', html: this.icon('audio')}),
                el('span', {'data-region': 'audio-play', 'class': 'tarsus-hidden', html: this.icon('audio_play')}),
                el('span', {'data-region': 'audio-pause', 'class': 'tarsus-hidden', html: this.icon('audio_pause')})
            ]),
            el('span', {'class': 'tarsus-learn-player__wave tarsus-hidden', 'data-region': 'audio-wave', 'aria-hidden': 'true'}),
            el('audio', {'data-region': 'audio', preload: 'auto'})
        ]);
        band.appendChild(player);

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
                qcard.appendChild(self.practiceBody(question));
                node.appendChild(qcard);
            } else {
                if (slide.title) {
                    node.appendChild(el('h5', {'class': 'tarsus-learn-slide__title', text: slide.title}));
                }
                var body = el('div', {'class': 'tarsus-learn-slide__body tarsus-learn-slide__body--' + (slide.imageposition === 'left' ? 'left' : 'right')});
                if (slide.image) {
                    body.appendChild(el('img', {'class': 'tarsus-learn-slide__image', src: slide.image, alt: '', tabindex: '0', role: 'button',
                        'data-action': 'enlarge', title: self.t('learn_image_enlarge'), 'aria-label': self.t('learn_image_enlarge')}));
                }
                body.appendChild(el('div', {'class': 'tarsus-learn-slide__text', html: slide.text || ''}));
                node.appendChild(body);
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
            return [this.noticeCard('complete', item.title, item.text, 'learn_complete_title')];
        }
        if (item.kind === 'motivation') {
            return [this.noticeCard('motivation', item.title, item.text, 'learn_motivation_title')];
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
        return el('div', {'class': 'tarsus-card tarsus-processing', 'data-region': 'processing', role: 'status', 'aria-live': 'polite'}, [
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
            }
        });

        var userRows = {};
        (verdict.usertable || []).forEach(function (row) {
            userRows[row.position] = row;
        });
        var modelRows = {};
        (verdict.answertable || []).forEach(function (row) {
            modelRows[row.position] = row;
        });
        card.querySelectorAll('.tarsus-practice-table__row').forEach(function (row) {
            var position = row.getAttribute('data-position');
            row.querySelectorAll('.tarsus-practice-table__input').forEach(function (input) {
                var cell = input.getAttribute('data-cell');
                if (input.value === '' && userRows[position]) {
                    input.value = userRows[position][cell] || '';
                }
                input.disabled = true;
                var model = modelRows[position];
                if (!model) {
                    return;
                }
                var mark = cell === 'rate' ? model.rateverdict : model.amountverdict;
                if (mark < 0) {
                    input.parentNode.classList.add('tarsus-practice-table__cell--incorrect');
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
                cards = [self.exchangeCard(item.query, item.answer, '')];
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
            } else if (item.kind === 'objectives' || item.kind === 'stack' || item.kind === 'motivation') {
                self.state.learnStarted = true;
            } else if (item.kind === 'complete') {
                self.state.learnStarted = true;
                self.state.learnComplete = true;
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
        var badge = this.q('[data-region="xp-badge"]');
        if (!badge) {
            return;
        }
        var progress = this.state.progress;
        var build = this.state.mode === 'build';
        var earned = progress ? (build ? progress.earnedunits : progress.earnedxp) : 0;
        var total = progress ? (build ? progress.totalunits : progress.totalxp) : 0;
        if (this.state.mode === 'ask' || !(total > 0)) {
            show(badge, false);
            return;
        }
        show(badge, true);
        badge.querySelector('[data-region="xp-earned"]').textContent = Math.round(earned * 100) / 100;
        badge.querySelector('[data-region="xp-total"]').textContent = Math.round(total * 100) / 100;
        badge.querySelector('[data-region="xp-unit"]').textContent = this.t(build ? 'learning_units' : 'xp');
    };

    Chat.prototype.renderProgressBar = function () {
        var bar = this.q('[data-region="progress"]');
        var fill = this.q('[data-region="progress-fill"]');
        if (!bar || !fill) {
            return;
        }
        if (this.state.mode === 'ask' || !this.state.progress) {
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
    };

    Chat.prototype.openLightbox = function (src) {
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

    Chat.prototype.nextLocked = function () {
        return this.state.mode === 'build' && this.state.learnComplete;
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
                return;
            }
            self.updateXp(result.progress);
            if (result.complete) {
                self.state.learnComplete = true;
            }
            var first = null;
            result.items.forEach(function (item) {
                self.learnCards(item).forEach(function (card) {
                    self.attach(card, null, true);
                    first = first || card;
                });
            });
            self.scrollToCard(first);
            self.state.learnStarted = true;
            self.updateNextLabel();
        }).catch(function (error) {
            unlock();
            self.alert(error.message || self.t('unexpected_error'));
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
        }).catch(function (error) {
            unlock();
            self.alert(error.message || self.t('unexpected_error'));
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
                var cells = {position: row.getAttribute('data-position')};
                ['rate', 'amount'].forEach(function (cell) {
                    var input = row.querySelector('input[data-cell="' + cell + '"]');
                    var given = row.querySelector('span[data-cell="' + cell + '"]');
                    var value = input ? input.value.trim() : (given ? given.textContent : '');
                    if (input && value === '') {
                        complete = false;
                    }
                    cells[cell] = (value || '').trim();
                });
                table.push(cells);
            });
            if (!complete) {
                return;
            }
        } else if (variableInputs.length) {
            variableInputs.forEach(function (input) {
                if (input.value.trim() !== '') {
                    answers.push({variable: input.getAttribute('data-variable'), answer: input.value.trim()});
                }
            });
            if (answers.length !== variableInputs.length) {
                return;
            }
        } else if (chosen) {
            answer = chosen.value;
        } else if (textinput) {
            answer = textinput.value.trim();
        }
        if (answer === '' && !answers.length && !table.length) {
            return;
        }

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
        }).catch(function (error) {
            check.disabled = false;
            show(spinner, false);
            self.alert(error.message || self.t('unexpected_error'));
        });
    };

    // --- modes, reply chip, asking -------------------------------------

    Chat.prototype.setMode = function (mode) {
        this.state.mode = mode;
        this.qa('.tarsus-mode-switch__btn').forEach(function (btn) {
            var active = btn.getAttribute('data-mode') === mode;
            btn.classList.toggle('tarsus-mode-switch__btn--active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        var ask = mode === 'ask';
        var input = this.q('.tarsus-chat-input');
        var submit = this.q('.tarsus-chat-submit');
        var next = this.q('[data-action="next"]');
        if (input) {
            show(input, ask);
            input.disabled = !ask;
        }
        show(submit, ask);
        show(next, !ask);
        var chip = this.q('[data-region="reply-chip"]');
        show(chip, ask && Boolean(this.state.replyTo));
        this.renderBadge();
        this.renderProgressBar();
        this.updateNextLabel();
    };

    Chat.prototype.setReply = function (reply) {
        this.state.replyTo = reply && reply.id ? reply : null;
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

    Chat.prototype.askAbout = function (card) {
        var id = '';
        var label = '';
        if (card.matches('[data-region="practice-card"]')) {
            id = card.getAttribute('data-widget-id') || '';
            var question = card.querySelector('.tarsus-practice-card__question');
            label = shorten(question ? question.textContent : '', 90);
        } else {
            var slide = card.querySelector('[data-region="slide"]:not(.tarsus-hidden)');
            if (!slide) {
                return;
            }
            id = slide.getAttribute('data-slide-id') || '';
            label = slide.getAttribute('data-slide-title') || '';
            if (label === '') {
                label = this.t('learn_slide_of', {
                    current: parseInt(slide.getAttribute('data-index'), 10) + 1,
                    total: parseInt(card.getAttribute('data-total'), 10) || 1
                });
            }
        }
        if (id === '') {
            return;
        }
        this.setMode('ask');
        this.setReply({id: id, title: label});
        var input = this.q('.tarsus-chat-input');
        if (input) {
            input.focus();
        }
    };

    Chat.prototype.handleSubmit = function (event) {
        var self = this;
        event.preventDefault();
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
        input.disabled = true;
        submit.disabled = true;
        show(spinner, true);
        this.alert('');
        input.value = '';
        this.autosize(input);
        var reply = this.state.replyTo;

        var processing = this.startProcessing('ask', false);
        var unlock = function () {
            processing.stop();
            input.disabled = false;
            submit.disabled = false;
            show(spinner, false);
            input.focus();
        };
        this.call('ask', {query: query, replyto: reply ? reply.id : ''}).then(function (result) {
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
            self.attach(self.exchangeCard(query, result.answer, reply ? reply.title : ''), null, true);
            self.setReply(null);
            self.scrollToEnd();
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
        this.setMode(initial);

        this.root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
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
                self.openLightbox(picture.getAttribute('src'));
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
                var askcard = ask.closest('[data-region="practice-card"], [data-region="learn-card"]');
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
            }
        });
        this.root.addEventListener('keydown', function (event) {
            if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof Element && event.target.matches('[data-action="enlarge"]')) {
                event.preventDefault();
                self.openLightbox(event.target.getAttribute('src'));
            }
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

    TarsusIlias.boot = function () {
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
