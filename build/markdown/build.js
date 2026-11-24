/**
 * Builds the plugin's Markdown renderer from entry.js:
 * - the script (one minified file, no loader needed),
 * - the licences of every library inside it,
 * - KaTeX's stylesheet and fonts (woff2: every browser the plugin supports).
 *
 * Run: npm ci && npm run build
 *
 * @license GPL-3.0-or-later
 */
import {build} from 'esbuild';
import {copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

/** Where the plugin keeps the files (relative to its folder). */
const TARGET = {
  script: join(root, 'templates', 'js', 'vendor'),
  katex: join(root, 'templates', 'vendor', 'katex'),
};

const LICENSE_FILES = ['LICENSE', 'license', 'LICENSE.md', 'license.md', 'LICENSE.txt', 'license.txt', 'LICENCE'];

/** The package a bundled file comes from (node_modules/<name>/... or node_modules/@scope/<name>/...). */
function packageOf(input) {
  const marker = 'node_modules/';
  const at = input.lastIndexOf(marker);
  if (at === -1) {
    return null;
  }
  const parts = input.slice(at + marker.length).split('/');
  return parts[0].charAt(0) === '@' ? parts[0] + '/' + parts[1] : parts[0];
}

/** The MIT licence, for packages that state it in package.json but ship no licence file. */
const MIT = [
  'Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated',
  'documentation files (the "Software"), to deal in the Software without restriction, including without limitation the',
  'rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit',
  'persons to whom the Software is furnished to do so, subject to the following conditions:',
  '',
  'The above copyright notice and this permission notice shall be included in all copies or substantial portions of the',
  'Software.',
  '',
  'THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE',
  'WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR',
  'COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR',
  'OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.',
].join('\n');

function licenseText(folder, meta) {
  for (const name of LICENSE_FILES) {
    const file = join(folder, name);
    if (existsSync(file)) {
      return readFileSync(file, 'utf8').trim();
    }
  }
  if (meta.license === 'MIT') {
    const author = typeof meta.author === 'string' ? meta.author : (meta.author && meta.author.name) || 'the authors';
    return '(The MIT License)\n\nCopyright (c) ' + author + '\n\n' + MIT;
  }
  return 'Licence: ' + (meta.license || 'unknown');
}

mkdirSync(TARGET.script, {recursive: true});
const result = await build({
  entryPoints: [join(here, 'entry.js')],
  bundle: true,
  minify: true,
  format: 'iife',
  target: 'es2017',
  legalComments: 'eof',
  metafile: true,
  banner: {js: '/* Tarsus Markdown (build/markdown): unified, remark, rehype and KaTeX, MIT licensed - see tarsus-markdown.licenses.txt */'},
  outfile: join(TARGET.script, 'tarsus-markdown.min.js'),
});

// The licences of the bundled libraries.
const packages = new Set();
for (const input of Object.keys(result.metafile.inputs)) {
  const name = packageOf(input);
  if (name !== null) {
    packages.add(name);
  }
}
const notices = ['Libraries bundled in tarsus-markdown.min.js', ''];
for (const name of [...packages].sort()) {
  const folder = join(here, 'node_modules', name);
  const meta = JSON.parse(readFileSync(join(folder, 'package.json'), 'utf8'));
  notices.push('='.repeat(72), name + ' ' + meta.version + ' (' + (meta.license || 'see below') + ')', '='.repeat(72), '', licenseText(folder, meta), '');
}
writeFileSync(join(TARGET.script, 'tarsus-markdown.licenses.txt'), notices.join('\n'));

// KaTeX's stylesheet, fonts and licence.
const katex = join(here, 'node_modules', 'katex');
const fonts = join(TARGET.katex, 'fonts');
rmSync(TARGET.katex, {recursive: true, force: true});
mkdirSync(fonts, {recursive: true});
copyFileSync(join(katex, 'dist', 'katex.min.css'), join(TARGET.katex, 'katex.min.css'));
copyFileSync(join(katex, 'LICENSE'), join(TARGET.katex, 'LICENSE'));
for (const file of readdirSync(join(katex, 'dist', 'fonts'))) {
  if (file.slice(-6) === '.woff2') {
    copyFileSync(join(katex, 'dist', 'fonts', file), join(fonts, file));
  }
}
console.log('Built', packages.size, 'libraries into tarsus-markdown.min.js; KaTeX', JSON.parse(readFileSync(join(katex, 'package.json'), 'utf8')).version);
