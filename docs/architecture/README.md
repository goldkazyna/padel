# Описание архитектуры для партнёров

`padel-architecture.html` — исходник, `padel-architecture.pdf` — то, что отдаём.

Пересобрать PDF после правки исходника:

```
"C:/Program Files/Google/Chrome/Application/chrome.exe" --headless=new --disable-gpu \
  --no-pdf-header-footer --print-to-pdf=docs/architecture/padel-architecture.pdf \
  --virtual-time-budget=4000 "file:///C:/projects/padel/docs/architecture/padel-architecture.html"
```

Цифры в документе (таблицы, строки, количество клубов) сняты с прода 2 сентября 2026 —
при следующем обновлении их надо пересчитать, а не переносить как есть.
