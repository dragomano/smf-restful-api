# Расширение SMF RESTful API

> [← Назад к README](README.ru.md)

API спроектирован так, чтобы сторонние моды могли добавлять свои ресурсы и
влиять на обработку запросов через стандартные интеграционные хуки SMF — не
изменяя файлы этого мода.

## Доступные хуки

| Хук | Параметры | Когда вызывается | Назначение |
|-----|-----------|------------------|------------|
| `integrate_api_routes` | `Router $router` | При инициализации маршрутизатора | Регистрация своих эндпоинтов |
| `integrate_api_pre_dispatch` | `Request $request` | После разбора запроса, до аутентификации | Глобальная логика (throttle, CORS-preflight); может бросить `ApiException` |
| `integrate_api_authenticate` | `Request $request`, `?int &$idMember` | В начале аутентификации | Свои схемы (OAuth/JWT): выставьте `$idMember` |
| `integrate_api_pre_send` | `array &$payload`, `int &$status`, `array &$headers` | Перед выводом ответа | Правка тела/статуса/заголовков (CORS, rate-limit, обёртки) |

## Добавление нового ресурса

Ниже — минимальный мод, добавляющий ресурс `widgets`.

### 1. Точка входа мода

```php
<?php

namespace Vendor\Widgets;

if (! defined('SMF'))
	die('No direct access...');

class Integration
{
	public function hooks(): void
	{
		// Своя автозагрузка, чтобы SMF нашёл классы мода.
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

### 2. Регистрация маршрутов

Обработчик `integrate_api_routes` получает объект `Router` с fluent-методами
`get()`, `post()`, `put()`, `patch()`, `delete()`. Сегмент `{id}` (или любой
`{name}`) попадает в массив параметров действия.

```php
	public function routes(\SMF\API\Router $router): void
	{
		$router->get('widgets', WidgetController::class, 'index');
		$router->get('widgets/{id}', WidgetController::class, 'show');
		$router->post('widgets', WidgetController::class, 'store');
	}
```

> Маршруты регистрируются **без** префикса версии. Ведущий сегмент версии
> (`v1`) снимает и проверяет сам `Router`, поэтому паттерны остаются
> версия-агностичными. Клиент обращается к ресурсу как
> `index.php?action=api&path=v1/widgets`.

> **Вложенные коллекции и зарезервированные параметры.** Списки «детей»
> регистрируйте под родителем — `parent/{parent_id}/children`, а не через
> query-фильтр. Пример из ядра API: `GET boards/{board_id}/topics`,
> `GET categories/{cat_id}/boards`, `GET topics/{topic_id}/posts`. Причина не
> только в стиле: имена `board`, `topic`, `msg`, `start`, `action` — **служебные
> query-параметры SMF**. Если передать `?board=5`, ядро на этапе `loadBoard()`
> сочтёт запрос просмотром раздела и отдаст HTML-страницу — до `action=api`
> дело не дойдёт. Идентификатор родителя в пути (`{parent_id}`) этой коллизии
> избегает; для чтения используйте `$this->id($params, 'parent_id')`.

### 3. Контроллер

Контроллер расширяет `SMF\API\Controllers\AbstractController` и получает
готовую «обвязку»: доступ к запросу, фабрику ответов, построитель запросов
`Query`, пагинацию и валидацию id.

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

		// Каждая строка проходит через transform(); отдаётся коллекция с мета-пагинацией.
		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}widgets')
				->where('id_widget = {int:id}')
				->columns('id_widget, name')
				->params(['id' => $this->id($params)]) // id($params) валидирует {id}
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

### Query — построение и выполнение запросов

`SMF\API\Query` — небольшой fluent-построитель. FROM/WHERE пишется один раз и
переиспользуется всеми режимами выборки; `LIMIT`, `COUNT` и пагинация достраиваются автоматически.

- Сборка: `->from()` (с JOIN'ами), `->where()`, `->columns()`, `->orderBy()`,
  `->params()` (плейсхолдеры `{int:...}`, `{query_see_board}` и т.п.);
- Запуск через хелперы контроллера:
  - `$this->paginate($query)` — коллекция с мета-пагинацией; каждая строка идёт через `transform()`;
  - `$this->first($query)` — одна строка или `null` (для `show` и проверок);
  - `$this->exists($query)` — `bool` (для валидации в `store`);
  - `$this->fetchAll($query)` — все строки без пагинации (сырые массивы).

### Что доступно в контроллере

- `$this->request` — объект `Request` (метод, сегменты, query, тело, заголовки);
- `$this->response()` — новый `Response` (`->data()`, `->collection()`, `->status()`, `->header()`, `->error()`);
- `$this->paginate($query)` / `$this->first($query)` / `$this->exists($query)` / `$this->fetchAll($query)` — запуск `Query`;
- `$this->transform($row)` — форма строки для вывода; переопределяйте под свой ресурс (по умолчанию возвращает строку как есть);
- `$this->id($params)` — целочисленный `{id}` с проверкой;
- `$this->pagination()` — `[limit, offset]` с разумными границами (вызывается внутри `paginate()`);
- `$this->cleanString($value)` — безопасная строка из тела запроса;
- `$this->writable($fields, $full)` — сбор изменяемых полей из тела: при `$full = true` (PUT) требует все поля из `$fields`, при `false` (PATCH) оставляет только присланные (пустой PATCH → 400);
- `$this->boolean($value)` — приведение значения тела к `bool` (принимает `true/false`, `1/0`, `"yes"/"no"`, `"on"/"off"`);
- `$this->noContent()` — пустой ответ `204` для успешных операций без тела (обычно `DELETE`);
- личность и права — из глобального `$user_info` (его настраивает `Authenticator`),
  проверки — штатным `allowedTo()` / `$user_info['is_admin']`.

> Для изменяющих действий (`PUT`/`PATCH`/`DELETE`) выполняйте запись через
> штатные функции SMF (`modifyBoard`, `deleteMembers`, `modifyPost` и т.п.) —
> `Query` предназначен только для чтения. Права **проверяйте сами** через
> `allowedTo()` до вызова этих функций и бросайте `ApiException`: многие функции
> ядра при отказе завершают запрос фатальной HTML-ошибкой, а не JSON.

## Свои схемы аутентификации

```php
public function authenticate(\SMF\API\Request $request, ?int &$idMember): void
{
	$token = $request->header('x-my-oauth');

	if (! empty($token)) {
		$idMember = my_resolve_oauth_to_member((string) $token);
	}
}
```

Если `$idMember` остался `null`, применяется штатная проверка ключей. Заданный
`$idMember` всё равно проходит проверку активации аккаунта и получает полный
контекст прав SMF.

## Правка ответа (например, CORS)

```php
public function preSend(array &$payload, int &$status, array &$headers): void
{
	$headers['Access-Control-Allow-Origin'] = '*';
}
```

## Правка запроса до маршрутизации

```php
public function preDispatch(\SMF\API\Request $request): void
{
	if (my_rate_limit_exceeded($request)) {
		throw new \SMF\API\ApiException(429, 'Too many requests', 'too_many_requests');
	}
}
```
