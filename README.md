# SMF RESTful API

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![License](https://img.shields.io/github/license/dragomano/smf-restful-api)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-restful-api/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-restful-api?branch=main)

**English** | [Русский](README.ru.md)

**SMF RESTful API** is a modification for the [SMF 2.1](https://github.com/SimpleMachines/SMF2.1)
forum that layers a full-featured REST interface with JSON responses on top of the
engine. The mod registers a single action, `index.php?action=api`, and passes the
request to its own router. A resource is addressed as
`index.php?action=api;path=v1/members/5`, or, with a rewrite rule in place, as
`/api/v1/members/5`.

Out of the box you get the `categories`, `members`, `boards`, `topics`, and `posts`
resources with read (`GET`) and write (`POST` / `PUT` / `PATCH` / `DELETE`)
operations. Reads go through a lightweight `Query` builder that respects board
visibility (`{query_see_board}`) and paginates results, while writes go through
SMF's native functions (`createBoard`, `modifyBoard`, `deleteBoards`, and so on),
so the core's permissions and business logic are honored. The client authenticates
with an API key (the `Authorization: Bearer …` header, `X-API-Key`, or the `api_key`
parameter), after which the mod restores the full SMF user context (`$user_info`,
permissions, `allowedTo()`, `{query_see_board}`) — exactly as it would for an
authenticated browser session.

The mod does not edit core files (it works only through hooks), and keys are set on
the "Admin → Modification Settings → RESTful API" page. Third-party mods can extend the API —
register their own routes and authentication schemes, tweak responses — through
integration hooks. How to do this is described in a separate guide:
[**Extending SMF RESTful API** (EXTENDING.md)](EXTENDING.md).

## Clean URLs (rewrite rules)

Rewrite rules are optional: the API is always reachable at its "direct" address,
`index.php?action=api;path=v1/members/5`. The rules merely turn it into the short
`/api/v1/members/5`. The mod strips the leading `api` segment itself, so the request
ends up at `index.php?action=api` with the path in the `path` parameter; the
remaining query parameters (for example, `?limit=25&offset=50`) are preserved.

### Apache (`.htaccess` in the forum root)

```apache
RewriteEngine On
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
RewriteRule ^api/(.*)$ index.php?action=api;path=$1 [QSA,L]
```

The `QSA` flag appends the original query string, so filters and pagination keep
working. If the forum is installed in a subdirectory, add a `RewriteBase` before the
rule — for example, `RewriteBase /forum/`.

### Nginx

```nginx
# /api/v1/members/5  ->  index.php?action=api;path=v1/members/5
location ^~ /api/ {
    rewrite ^/api/(.*)$ /index.php?action=api;path=$1 last;
}
```

The `^~` modifier gives this block priority over the general `location ~ \.php$`
(the order in which they are declared does not matter), and `/index.php` is then
handled by the forum's usual PHP location. Nginx automatically appends the original
query parameters to the `rewrite` target.
