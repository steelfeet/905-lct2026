# -*- coding: utf-8 -*-
# Временный скрипт: извлечение стилей примера rtkb.zion-lms.ru (удаляется после проверки)
import re
import requests

H = {'User-Agent': 'Mozilla/5.0'}

r = requests.get('https://rtkb.zion-lms.ru/', timeout=20, headers=H)
print('page', r.status_code, len(r.text))

links = re.findall(r'href="([^"]+\.css[^"]*)"', r.text)
print('css:', links)

css_all = ''
for href in links[:3]:
    url = href if href.startswith('http') else 'https://rtkb.zion-lms.ru' + href
    c = requests.get(url, timeout=30, headers=H)
    css_all += c.text
    print('fetched', url, len(c.text))

with open('_rtkb.css', 'w', encoding='utf-8') as f:
    f.write(css_all)

# Топ цветов
colors = re.findall(r'#[0-9a-fA-F]{3,8}\b', css_all)
from collections import Counter
print('top colors:')
for c, n in Counter(x.lower() for x in colors).most_common(40):
    print(' ', c, n)

# Шрифты
fonts = re.findall(r'font-family:([^;}]+)', css_all)
print('fonts sample:')
for f_ in list(dict.fromkeys(fonts))[:10]:
    print(' ', f_.strip()[:120])
