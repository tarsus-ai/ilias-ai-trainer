# Tarsus Markdown

The coach shows the texts Tarsus writes (answers, learning units, questions,
answer options, feedback, sources) exactly the way the Tarsus app does: with
React-Markdown's pipeline - CommonMark, GitHub tables, strikethrough, task
lists and footnotes (remark-gfm) - and formulas with KaTeX (remark-math,
rehype-katex). The server sends the Markdown untouched; the browser renders
it with `templates/js/vendor/tarsus-markdown.min.js`.

Safety follows React-Markdown: HTML written in a text is shown as text, and
link or image addresses other than http(s), mailto, irc(s), xmpp or relative
ones are emptied.

Rebuild (Node 18 or newer):

```bash
cd build/markdown
npm ci
npm run build
```

This writes the script, the licences of the bundled libraries
(`tarsus-markdown.licenses.txt`) and KaTeX's stylesheet and fonts
(`templates/vendor/katex`).
