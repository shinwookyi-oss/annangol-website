"""Build the multilingual AnnanGol site from src/template.html + i18n/<lang>.json.

Root index.html is English; every language (including en) also gets /<code>/index.html.
Run:  python3 build.py
"""
import json, os, re

SITE = 'https://annangol.com'
# Google Analytics 4 measurement ID (e.g. 'G-ABC123XYZ'). Leave '' to skip GA4;
# the first-party counter (/api/t.php, dashboard /stats/) runs either way.
GA_ID = ''
GA_SNIPPET = """<script async src="https://www.googletagmanager.com/gtag/js?id={id}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments)}}gtag('js',new Date());gtag('config','{id}');</script>
</head>"""
LANGS = [  # code, native name, rtl, extra Google font family (or None)
    ('en', 'English', False, None),
    ('ko', '한국어', False, None),
    ('es', 'Español', False, None),
    ('vi', 'Tiếng Việt', False, None),
    ('zh', '中文', False, 'Noto+Sans+SC:wght@400;500;700;900'),
    ('mn', 'Монгол', False, None),
    ('tl', 'Filipino', False, None),
    ('ar', 'العربية', True, 'Noto+Sans+Arabic:wght@400;500;700;800'),
    ('fr', 'Français', False, None),
    ('am', 'አማርኛ', False, 'Noto+Sans+Ethiopic:wght@400;500;700;800'),
    ('fa', 'فارسی', True, 'Vazirmatn:wght@400;500;700;800'),
    ('bn', 'বাংলা', False, 'Noto+Sans+Bengali:wght@400;500;700;800'),
    ('ht', 'Kreyòl', False, None),
    ('pt', 'Português', False, None),
    ('ru', 'Русский', False, None),
]
FONT_NAME = {'zh': "'Noto Sans SC'", 'ar': "'Noto Sans Arabic'", 'am': "'Noto Sans Ethiopic'", 'fa': "'Vazirmatn'", 'bn': "'Noto Sans Bengali'"}

PICKER_CSS = """
.lang{position:relative}
.lang summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:6px;font-weight:600;font-size:14px;opacity:.9;padding:6px 10px;border:1px solid rgba(255,255,255,.25);border-radius:999px}
.lang summary::-webkit-details-marker{display:none}
.lang summary{white-space:nowrap}
.lang .lcode{display:none}
@media (max-width:600px){.lang .lname{display:none}.lang .lcode{display:inline}.lang ul{grid-template-columns:repeat(2,minmax(110px,1fr))}}
.lang ul{position:absolute;inset-inline-end:0;top:calc(100% + 8px);margin:0;padding:8px;list-style:none;background:var(--card);color:var(--ink);border:1px solid var(--rule);border-radius:12px;box-shadow:0 18px 40px -12px rgba(0,0,0,.45);display:grid;grid-template-columns:repeat(2,minmax(120px,1fr));gap:2px;z-index:20;max-height:70vh;overflow:auto}
.lang li a{display:block;padding:7px 10px;border-radius:8px;text-decoration:none;font-size:14px}
.lang li a:hover,.lang li a[aria-current]{background:var(--steel)}
.lang li a[aria-current]{font-weight:700}
html:lang(ko) body,.kr,[lang=ko]{word-break:keep-all}
[dir=rtl] .hero-photo .tag{left:auto;right:-14px}
@media (max-width:860px){[dir=rtl] .hero-photo .tag{right:12px}}
[dir=rtl] .board .n .kr{margin-left:0;margin-right:6px}
[dir=rtl] .copy{margin-left:0;margin-right:10px}
"""


def build():
    tpl = open('src/template.html', encoding='utf-8').read()
    en = json.load(open('i18n/en.json', encoding='utf-8'))
    avail = [l for l in LANGS if os.path.exists(f'i18n/{l[0]}.json')]
    for code, name, rtl, font in avail:
        d = dict(en)
        d.update(json.load(open(f'i18n/{code}.json', encoding='utf-8')))
        for root in ([True, False] if code == 'en' else [False]):
            pre = '' if root else '../'
            html = tpl
            d['hide_if_ko'] = 'hidden' if code == 'ko' else ''
            d['js_strings'] = json.dumps({k: d[k] for k in ('copy', 'copied', 'selected')}, ensure_ascii=False)
            html = re.sub(r'\{\{(\w+)\}\}', lambda m: d[m.group(1)], html)
            html = html.replace('<html lang="en">', f'<html lang="{code}"' + (' dir="rtl"' if rtl else '') + '>')
            # asset paths
            html = re.sub(r'(src|href)="img/', lambda m: f'{m.group(1)}="{pre}img/', html)
            # fonts for non-Latin scripts
            if font:
                html = html.replace('&display=swap">', f'&family={font}&display=swap">', 1)
                html = html.replace("--f-sans:'Montserrat',", f"--f-sans:'Montserrat',{FONT_NAME[code]},", 1)
                html = html.replace("--f-kr:'Noto Sans KR',", f"--f-kr:'Noto Sans KR',{FONT_NAME[code]},", 1)
            # hreflang + canonical
            alts = '\n'.join(f'<link rel="alternate" hreflang="{c}" href="{SITE}/{c}/">' for c, *_ in avail)
            alts += f'\n<link rel="alternate" hreflang="x-default" href="{SITE}/">'
            canon = SITE + '/' if root else f'{SITE}/{code}/'
            html = html.replace('<link rel="icon"', f'<link rel="canonical" href="{canon}">\n{alts}\n<link rel="icon"', 1)
            html = html.replace('</style>', PICKER_CSS + '</style>', 1)
            if GA_ID:
                html = html.replace('</head>', GA_SNIPPET.format(id=GA_ID), 1)
            # language picker, placed before the Order button in the header
            items = ''.join(
                f'<li><a href="{pre}{c}/" hreflang="{c}" lang="{c}"' + (' aria-current="true"' if c == code else '') + f'>{n}</a></li>'
                for c, n, *_ in avail)
            picker = (f'<details class="lang"><summary aria-label="{d["lang_label"]}: {name}">🌐 <span class="lname">{name}</span><span class="lcode">{code.upper()}</span></summary>'
                      f'<ul>{items}</ul></details>\n    ')
            html = html.replace('<a class="btn" href="https://annangol.menu11.com', picker + '<a class="btn" href="https://annangol.menu11.com', 1)
            out = 'index.html' if root else f'{code}/index.html'
            os.makedirs(os.path.dirname(out) or '.', exist_ok=True)
            open(out, 'w', encoding='utf-8').write(html)
    left = re.findall(r'\{\{\w+\}\}', open('index.html', encoding='utf-8').read())
    print('built', [l[0] for l in avail], 'unfilled:', left)


if __name__ == '__main__':
    build()
