# SMF RESTful API

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![License](https://img.shields.io/github/license/dragomano/smf-restful-api)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-restful-api/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-restful-api?branch=main)

[English](README.md) | **Русский**

**SMF RESTful API** — модификация для форума [SMF 2.1](https://github.com/SimpleMachines/SMF2.1),
добавляющая поверх движка полноценный REST-интерфейс с ответами в формате JSON.
Мод регистрирует единственное действие `index.php?action=api` и передаёт запрос
собственному маршрутизатору. Обращение к ресурсу выглядит как
`index.php?action=api;path=v1/members/5`, а при настроенном правиле перезаписи —
как `/api/v1/members/5`.

Из коробки доступны ресурсы `categories`, `members`, `boards`, `topics` и `posts`
с операциями чтения (`GET`) и изменения (`POST` / `PUT` / `PATCH` / `DELETE`).
Чтение идёт через лёгкий построитель запросов `Query` с учётом видимости разделов
(`{query_see_board}`) и постраничной выдачей, а запись — через штатные функции SMF
(`createBoard`, `modifyBoard`, `deleteBoards` и т. п.), поэтому права и бизнес-логика
ядра соблюдаются. Клиент аутентифицируется по API-ключу (заголовок
`Authorization: Bearer …`, `X-API-Key` или параметр `api_key`), после чего мод
восстанавливает полный контекст пользователя SMF (`$user_info`, права, `allowedTo()`,
`{query_see_board}`) — ровно так, как для авторизованной сессии в браузере.

Мод не редактирует файлы ядра (работает только через хуки), а ключи задаются на
странице «Админка → Настройки модов → RESTful API». Сторонние моды могут
расширять API — регистрировать свои маршруты и схемы аутентификации, править ответы —
через интеграционные хуки. Как это сделать, описано в отдельном руководстве:
[**Расширение SMF RESTful API** (EXTENDING.ru.md)](EXTENDING.ru.md).

## Чистые URL (правила перезаписи)

Правила перезаписи не обязательны: API всегда доступен по «прямому» адресу
`index.php?action=api;path=v1/members/5`. Правила лишь превращают его в короткий
`/api/v1/members/5`. Ведущий сегмент `api` мод снимает сам, поэтому запрос уходит
в `index.php?action=api` с путём в параметре `path`; остальные query-параметры
(например, `?limit=25&offset=50`) при этом сохраняются.

### Apache (`.htaccess` в корне форума)

```apache
RewriteEngine On
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
RewriteRule ^api/(.*)$ index.php?action=api;path=$1 [QSA,L]
```

Флаг `QSA` дописывает исходную строку запроса, поэтому фильтры и пагинация
продолжают работать. Если форум установлен в подкаталоге, добавьте перед
правилом `RewriteBase` — например, `RewriteBase /forum/`.

### Nginx

```nginx
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
location ^~ /api/ {
    rewrite ^/api/(.*)$ /index.php?action=api;path=$1 last;
}
```

Модификатор `^~` даёт этому блоку приоритет над общим `location ~ \.php$`
(порядок описания при этом не важен), а сам `/index.php` затем обрабатывается
штатным PHP-location форума. Nginx автоматически дописывает исходные
query-параметры к цели `rewrite`.
