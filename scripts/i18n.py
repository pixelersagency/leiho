#!/usr/bin/env python3
"""
Builds Leiho's translation files.

    python3 scripts/i18n.py            # regenerate everything
    python3 scripts/i18n.py --check    # exit 1 if a string has no French translation

Outputs (plugin/languages/):
  leiho.pot                      template, extracted from PHP and JS with xgettext
  leiho-fr_FR.po / .mo           French, for PHP (__(), esc_html_e()…)
  leiho-fr_FR-<md5>.json         French, for each script using wp.i18n
                                (<md5> = md5 of the script path relative to the plugin)

French strings come from the existing leiho-fr_FR.po, so editing the .po by hand
(or with Poedit) and re-running the script is the normal workflow. Seed files
(--seed file.jsonl, one {"en": …, "fr": …} object per line) can add entries.
Requires GNU gettext (xgettext, msgfmt).
"""

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'plugin')
LANG_DIR = os.path.join(PLUGIN, 'languages')
DOMAIN = 'leiho'
LOCALE = 'fr_FR'
PLURAL_FORMS = 'nplurals=2; plural=(n > 1);'

JS_FILES = [
    'assets/admin/editor.js', 'assets/admin/admin.js', 'assets/admin/app.js',
    'assets/admin/app-content.js', 'assets/admin/app-collections.js', 'assets/admin/app-media.js',
    'assets/admin/ui.js', 'assets/admin/ai.js',
]

PHP_KEYWORDS = [
    '__:1', '_e:1', 'esc_html__:1', 'esc_html_e:1', 'esc_attr__:1', 'esc_attr_e:1',
    '_x:1,2c', '_ex:1,2c', 'esc_html_x:1,2c', 'esc_attr_x:1,2c',
    '_n:1,2', '_nx:1,2,4c', '_n_noop:1,2', '_nx_noop:1,2,3c',
]
JS_KEYWORDS = ['__:1', '_x:1,2c', '_n:1,2', '_nx:1,2,4c']


def run(cmd):
    subprocess.run(cmd, check=True, cwd=PLUGIN)


def php_files():
    out = []
    for base, dirs, files in os.walk(PLUGIN):
        dirs[:] = [d for d in dirs if d not in ('tests', 'languages', 'node_modules')]
        for f in files:
            if f.endswith('.php'):
                out.append(os.path.relpath(os.path.join(base, f), PLUGIN))
    return sorted(out)


def extract(files, language, keywords, output, join=False):
    cmd = ['xgettext'] + (['--join-existing'] if join else []) + [ '--from-code=UTF-8', '--language=' + language, '--add-comments=translators:',
           '--no-wrap', '--sort-by-file', '--package-name=Leiho', '-o', output]
    cmd += ['--keyword=' + k for k in keywords]
    run(cmd + files)


# ── Minimal PO reader / writer ────────────────────────────────────────────

def po_unescape(s):
    return (s.replace('\\\\', '\x00').replace('\\n', '\n').replace('\\t', '\t')
             .replace('\\"', '"').replace('\x00', '\\'))


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def read_po(path):
    """Returns a list of entries: dict(ctx, id, plural, strs, refs, comments)."""
    entries = []
    if not os.path.exists(path):
        return entries
    cur = None
    field = None

    def flush():
        # L'entrée d'en-tête (msgid "") est réécrite à part par write_po().
        if cur is not None and cur.get('id'):
            entries.append(cur)

    with open(path, encoding='utf-8') as fh:
        for raw in fh:
            line = raw.rstrip('\n')
            if not line.strip():
                flush()
                cur, field = None, None
                continue
            if cur is None:
                cur = {'ctx': None, 'id': None, 'plural': None, 'strs': {}, 'refs': [], 'comments': []}
            if line.startswith('#:'):
                cur['refs'].append(line[2:].strip())
            elif line.startswith('#.'):
                cur['comments'].append(line[2:].strip())
            elif line.startswith('#'):
                continue
            elif line.startswith('msgctxt '):
                field = 'ctx'
                cur['ctx'] = po_unescape(line[8:].strip()[1:-1])
            elif line.startswith('msgid_plural '):
                field = 'plural'
                cur['plural'] = po_unescape(line[13:].strip()[1:-1])
            elif line.startswith('msgid '):
                field = 'id'
                cur['id'] = po_unescape(line[6:].strip()[1:-1])
            elif line.startswith('msgstr['):
                idx = int(line[7:line.index(']')])
                field = ('str', idx)
                cur['strs'][idx] = po_unescape(line[line.index(']') + 1:].strip()[1:-1])
            elif line.startswith('msgstr '):
                field = ('str', 0)
                cur['strs'][0] = po_unescape(line[7:].strip()[1:-1])
            elif line.startswith('"'):
                val = po_unescape(line.strip()[1:-1])
                if field in ('ctx', 'id', 'plural'):
                    cur[field] += val
                elif isinstance(field, tuple):
                    cur['strs'][field[1]] += val
    flush()
    return entries


def key(ctx, msgid):
    return (ctx or '', msgid)


def write_po(path, entries, header):
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write('msgid ""\nmsgstr ""\n')
        for line in header:
            fh.write('"%s\\n"\n' % po_escape(line))
        for e in entries:
            fh.write('\n')
            for c in e['comments']:
                fh.write('#. %s\n' % c)
            for r in e['refs']:
                fh.write('#: %s\n' % r)
            if e['ctx'] is not None:
                fh.write('msgctxt "%s"\n' % po_escape(e['ctx']))
            fh.write('msgid "%s"\n' % po_escape(e['id']))
            if e['plural'] is not None:
                fh.write('msgid_plural "%s"\n' % po_escape(e['plural']))
                for i in range(2):
                    fh.write('msgstr[%d] "%s"\n' % (i, po_escape(e['strs'].get(i, ''))))
            else:
                fh.write('msgstr "%s"\n' % po_escape(e['strs'].get(0, '')))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--seed', action='append', default=[], help='JSONL translation map to merge')
    parser.add_argument('--check', action='store_true', help='fail when a string is untranslated')
    args = parser.parse_args()

    os.makedirs(LANG_DIR, exist_ok=True)
    pot = os.path.join(LANG_DIR, DOMAIN + '.pot')
    po_path = os.path.join(LANG_DIR, '%s-%s.po' % (DOMAIN, LOCALE))

    # 1. Extraction : PHP puis JS, fusionnés dans le .pot.
    # (xgettext -j plutôt que msgcat : msgcat perd les références d'un message
    # présent à la fois dans le PHP et dans le JS, et le JSON du script le rate.)
    extract(php_files(), 'PHP', PHP_KEYWORDS, pot)
    extract(JS_FILES, 'JavaScript', JS_KEYWORDS, pot, join=True)

    # En-tête du plugin : WordPress traduit la description de la liste des
    # extensions avec le même domaine, mais xgettext ne la voit pas.
    with open(os.path.join(PLUGIN, 'leiho.php'), encoding='utf-8') as fh:
        m = re.search(r'^ \* Description:\s*(.+)$', fh.read(), re.M)
    # Déjà présente si le code PHP réutilise la même chaîne (mise à jour) : pas de doublon.
    with open(pot, encoding='utf-8') as fh:
        known = fh.read()
    if m and ('msgid "%s"' % po_escape(m.group(1).strip())) not in known:
        with open(pot, 'a', encoding='utf-8') as fh:
            fh.write('\n#. Description of the plugin\n#: leiho.php\nmsgid "%s"\nmsgstr ""\n' % po_escape(m.group(1).strip()))

    # Horodatage stable : sans ça, chaque exécution modifierait le .pot.
    with open(pot, encoding='utf-8') as fh:
        content = fh.read()
    content = re.sub(r'"POT-Creation-Date: [^"]*"', '"POT-Creation-Date: 2026-01-01 00:00+0000\\\\n"', content)
    content = content.replace('"Content-Type: text/plain; charset=CHARSET\\n"', '"Content-Type: text/plain; charset=UTF-8\\n"')
    with open(pot, 'w', encoding='utf-8') as fh:
        fh.write(content)

    template = read_po(pot)

    # 2. Traductions connues : .po existant, puis fichiers d'amorçage.
    known = {}
    for e in read_po(po_path):
        if any(e['strs'].values()):
            known[key(e['ctx'], e['id'])] = e['strs']
    for seed in args.seed:
        with open(seed, encoding='utf-8') as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                item = json.loads(line)
                fr = item['fr']
                strs = {i: v for i, v in enumerate(fr)} if isinstance(fr, list) else {0: fr}
                known.setdefault(key(item.get('ctx'), item['en']), strs)

    # 3. .po français aligné sur le .pot.
    missing = []
    entries = []
    for e in template:
        strs = known.get(key(e['ctx'], e['id']))
        if strs is None:
            missing.append(e['id'])
            strs = {}
        entries.append(dict(e, strs=strs))
    header = [
        'Project-Id-Version: Leiho',
        'Language: fr_FR',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Plural-Forms: ' + PLURAL_FORMS,
        'X-Domain: leiho',
    ]
    write_po(po_path, entries, header)
    run(['msgfmt', '--check-format', '-o', os.path.join(LANG_DIR, '%s-%s.mo' % (DOMAIN, LOCALE)), po_path])

    # 4. Un JSON par script (format attendu par wp_set_script_translations()).
    for js in JS_FILES:
        messages = {'': {'domain': 'messages', 'lang': LOCALE, 'plural-forms': PLURAL_FORMS}}
        for e in entries:
            if not any(ref.split(':')[0] == js for r in e['refs'] for ref in r.split()):
                continue
            if not any(e['strs'].values()):
                continue
            k = e['id'] if e['ctx'] is None else e['ctx'] + '\u0004' + e['id']
            if e['plural'] is not None:
                messages[k] = [e['strs'].get(0, ''), e['strs'].get(1, '')]
            else:
                messages[k] = [e['strs'][0]]
        data = {'domain': 'messages', 'locale_data': {'messages': messages}}
        name = '%s-%s-%s.json' % (DOMAIN, LOCALE, hashlib.md5(js.encode()).hexdigest())
        with open(os.path.join(LANG_DIR, name), 'w', encoding='utf-8') as fh:
            json.dump(data, fh, ensure_ascii=False, indent=None, separators=(',', ':'), sort_keys=True)
            fh.write('\n')
        print('%-28s %4d strings -> %s' % (js, len(messages) - 1, name))

    print('%d strings, %d without French translation' % (len(entries), len(missing)))
    for m in missing:
        print('  missing: ' + m)
    if args.check and missing:
        sys.exit(1)


if __name__ == '__main__':
    main()
