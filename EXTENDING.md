# Extending SMF RESTful API

> [← Back to README](README.md)

The API is designed so that third-party mods can add their own resources and
influence request handling through SMF's standard integration hooks — without
modifying this mod's files.

## Available hooks

| Hook | Parameters | When it fires | Purpose |
|------|------------|---------------|---------|
| `integrate_api_routes` | `Router $router` | When the router is initialized | Register your own endpoints |
| `integrate_api_pre_dispatch` | `Request $request` | After the request is parsed, before authentication | Global logic (throttling, CORS preflight); may throw `ApiException` |
| `integrate_api_authenticate` | `Request $request`, `?int &$idMember` | At the start of authentication | Your own schemes (OAuth/JWT): set `$idMember` |
| `integrate_api_pre_send` | `array &$payload`, `int &$status`, `array &$headers` | Before the response is sent | Adjust the body/status/headers (CORS, rate limiting, wrappers) |

## Adding a new resource

Below is a minimal mod that adds a `widgets` resource.

### 1. Mod entry point

```php
<?php

namespace Vendor\Widgets;

if (! defined('SMF'))
	die('No direct access...');

class Integration
{
	public function hooks(): void
	{
		// Your own autoloader, so SMF can find the mod's classes.
		add_integration_function('integrate_autoload', self::class . '::autoload#', false, __FILE__);
		add_integration_function('integrate_api_routes', self::class . '::routes#', false, __FILE__);
	}

	public function autoload(array &$classMap): void
	{
		$classMap['Vendor\\Widgets\\'] = 'Widgets/';
	}

	// PLACEHOLDER_ROUTES
}
```

### 2. Registering routes

The `integrate_api_routes` handler receives a `Router` object with the fluent
methods `get()`, `post()`, `put()`, `patch()`, `delete()`. The `{id}` segment (or
any `{name}`) is placed into the action's parameter array.

```php
	public function routes(\SMF\API\Router $router): void
	{
		$router->get('widgets', WidgetController::class, 'index');
		$router->get('widgets/{id}', WidgetController::class, 'show');
		$router->post('widgets', WidgetController::class, 'store');
	}
```

> Routes are registered **without** a version prefix. The leading version segment
> (`v1`) is stripped and validated by the `Router` itself, so the patterns stay
> version-agnostic. The client addresses the resource as
> `index.php?action=api&path=v1/widgets`.

> **Nested collections and reserved parameters.** Register "child" lists under the
> parent — `parent/{parent_id}/children`, not through a query filter. Examples from
> the core API: `GET boards/{board_id}/topics`, `GET categories/{cat_id}/boards`,
> `GET topics/{topic_id}/posts`. The reason is not just style: the names `board`,
> `topic`, `msg`, `start`, `action` are **SMF's reserved query parameters**. If you
> pass `?board=5`, the core will treat the request as a board view during
> `loadBoard()` and return an HTML page — it will never reach `action=api`. The
> parent identifier in the path (`{parent_id}`) avoids this collision; read it with
> `$this->id($params, 'parent_id')`.

### 3. Controller

The controller extends `SMF\API\Controllers\AbstractController` and gets a ready-made
"harness": access to the request, a response factory, the `Query` builder,
pagination, and id validation.

```php
<?php

namespace Vendor\Widgets;

use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Response;
use SMF\API\Controllers\AbstractController;

if (! defined('SMF'))
	die('No direct access...');

class WidgetController extends AbstractController
{
	public function index(array $params): Response
	{
		$query = (new Query())
			->from('{db_prefix}widgets AS w')
			->where('w.hidden = {int:hidden}')
			->columns('w.id_widget, w.name')
			->orderBy('w.name')
			->params(['hidden' => 0]);

		// Every row passes through transform(); a collection with pagination meta is returned.
		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}widgets')
				->where('id_widget = {int:id}')
				->columns('id_widget, name')
				->params(['id' => $this->id($params)]) // id($params) validates {id}
		);

		if ($row === null) {
			throw ApiException::notFound('Widget not found');
		}

		return $this->response()->data($this->transform($row));
	}

	protected function transform(array $row): array
	{
		return [
			'id'   => (int) $row['id_widget'],
			'name' => $row['name'],
		];
	}
}
```

### Query — building and running queries

`SMF\API\Query` is a small fluent builder. FROM/WHERE is written once and reused by
every fetch mode; `LIMIT`, `COUNT`, and pagination are added automatically.

- Building: `->from()` (with JOINs), `->where()`, `->columns()`, `->orderBy()`,
  `->params()` (the `{int:...}`, `{query_see_board}`, etc. placeholders);
- Running via the controller helpers:
  - `$this->paginate($query)` — a collection with pagination meta; every row passes through `transform()`;
  - `$this->first($query)` — a single row or `null` (for `show` and checks);
  - `$this->exists($query)` — a `bool` (for validation in `store`);
  - `$this->fetchAll($query)` — all rows without pagination (raw arrays).

### What's available in the controller

- `$this->request` — the `Request` object (method, segments, query, body, headers);
- `$this->response()` — a new `Response` (`->data()`, `->collection()`, `->status()`, `->header()`, `->error()`);
- `$this->paginate($query)` / `$this->first($query)` / `$this->exists($query)` / `$this->fetchAll($query)` — run a `Query`;
- `$this->transform($row)` — the output shape of a row; override it for your own resource (by default it returns the row as is);
- `$this->id($params)` — the integer `{id}`, validated;
- `$this->pagination()` — `[limit, offset]` with sensible bounds (called inside `paginate()`);
- `$this->cleanString($value)` — a safe string from the request body;
- `$this->writable($fields, $full)` — collect writable fields from the body: with `$full = true` (PUT) it requires every field in `$fields`, with `false` (PATCH) it keeps only the ones sent (an empty PATCH → 400);
- `$this->boolean($value)` — coerce a body value to `bool` (accepts `true/false`, `1/0`, `"yes"/"no"`, `"on"/"off"`);
- `$this->noContent()` — an empty `204` response for successful operations without a body (usually `DELETE`);
- identity and permissions come from the global `$user_info` (set up by the `Authenticator`),
  and checks use the native `allowedTo()` / `$user_info['is_admin']`.

> For mutating actions (`PUT`/`PATCH`/`DELETE`), perform the write through SMF's
> native functions (`modifyBoard`, `deleteMembers`, `modifyPost`, and so on) —
> `Query` is read-only. **Check permissions yourself** with `allowedTo()` before
> calling these functions and throw an `ApiException`: on denial, many core
> functions terminate the request with a fatal HTML error rather than JSON.

## Custom authentication schemes

```php
public function authenticate(\SMF\API\Request $request, ?int &$idMember): void
{
	$token = $request->header('x-my-oauth');

	if (! empty($token)) {
		$idMember = my_resolve_oauth_to_member((string) $token);
	}
}
```

If `$idMember` stays `null`, the native key check applies. An `$idMember` that has
been set still goes through the account activation check and receives the full SMF
permission context.

## Adjusting the response (for example, CORS)

```php
public function preSend(array &$payload, int &$status, array &$headers): void
{
	$headers['Access-Control-Allow-Origin'] = '*';
}
```

## Adjusting the request before routing

```php
public function preDispatch(\SMF\API\Request $request): void
{
	if (my_rate_limit_exceeded($request)) {
		throw new \SMF\API\ApiException(429, 'Too many requests', 'too_many_requests');
	}
}
```
