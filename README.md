# Доходные дома Ефремовых — сайт

Сайт трёх домов с сауной и бассейном в Перми (ефремовскиебани.рф). Статичная вёрстка, дальше переносится в шаблоны MODX 3.

## Структура

```
images/           исходные фото от владельца (не трогаем)
src/              исходники — правим только здесь
  houses.py       данные и SEO-тексты домов: цены, гости, удобства, галерея, FAQ
  styles.css      стили; цвета, градиенты и шрифты — переменные в :root
  app.js          расчёт стоимости, маска телефона, отправка формы, просмотр фото
  fox.svg         логотип-лиса
site/             готовые страницы (собираются, руками не править)
  index.html, angliyskij-dom.html, russkij-dom.html, dom-na-sviyazeva.html
  img/en|ru|sv|mood/  обработанные фото
  robots.txt, sitemap.xml, llms.txt
docs/             семантика и SEO, контент старого сайта, ссылки
build.py          сборка: src → site
tools/prep_img.py обработка фото из images/ в site/img/
```

## Работа в VS Code

1. Переименуйте `_vscode` в `.vscode` (настройки, задача сборки, расширения).
2. File → Open Folder → `C:\cloud\bania`.
3. Сборка: Ctrl+Shift+B или `py build.py`.
4. Просмотр: правой кнопкой по `site/index.html` → Show Preview.
5. GitHub: Source Control → Publish to GitHub → private.

Нужен Python 3.9+. Для обработки фото ещё `py -m pip install pillow`.

## Фото

Новые фото кладите в `images/<дом>/`, затем добавьте строку в `tools/prep_img.py` и запустите `py tools/prep_img.py site/img`.
Фото из папки `site/img/mood/` — стоковые, для настроения в блоке «Для праздника». Их лучше заменить на свои фото с гостями (с их согласия).

## Перенос в MODX 3

- Шаблоны «Главная» и «Дом», чанки: шапка, подвал, форма, карточка дома.
- Поля из `src/houses.py` → TV ресурса «Дом» (галерея и удобства — MIGX, SEO-тексты — richtext).
- `window.HOUSES` выводить в шаблоне из TV.
- Форма: AjaxForm + FormIt, письмо и Telegram. Поле `website` — ловушка для ботов.
- JSON-LD и meta уже в шаблоне страницы — заменить значения на плейсхолдеры TV.
