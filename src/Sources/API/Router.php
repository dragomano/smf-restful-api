<?php declare(strict_types=1);

/**
 * Router.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API;

use SMF\API\Controllers\BoardController;
use SMF\API\Controllers\CategoryController;
use SMF\API\Controllers\MemberController;
use SMF\API\Controllers\PostController;
use SMF\API\Controllers\TopicController;
use Throwable;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Matches a Request against the route table and dispatches it to a controller,
 * converting any thrown ApiException (or unexpected error) into a JSON Response.
 *
 * The table is seeded with the built-in resources and then handed to the
 * integrate_api_routes hook, so other mods can register their own resources
 * through the fluent get()/post()/... helpers without touching this file.
 */
class Router
{
	/** API contract versions this router accepts as the first path segment. */
	private const SUPPORTED_VERSIONS = ['v1'];

	/**
	 * @var array<int, array{method: string, pattern: string, class: class-string, action: string}>
	 */
	private array $routes = [];

	public function __construct()
	{
		$this->registerDefaultRoutes();

		call_integration_hook('integrate_api_routes', [$this]);
	}

    public function dispatch(): void
    {
        $response = new Response();

        try {
            $request = new Request();

            // Global pre-routing hook: throttling, CORS preflight, etc.
            // A handler may throw ApiException to short-circuit the request.
            call_integration_hook('integrate_api_pre_dispatch', [$request]);

            (new Authenticator())->authenticate($request);

            [$controllerClass, $action, $params] = $this->match($request);

            /** @var Controllers\AbstractController $controller */
            $controller = new $controllerClass($request);

            $result = $controller->$action($params);

            ($result instanceof Response ? $result : $response->data($result))->send();
        } catch (ApiException $e) {
            $response
                ->error($e->getStatusCode(), $e->getMessage(), $e->getErrorCode(), $e->getDetails())
                ->send();
        } catch (Throwable $e) {
            log_error('[API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            $response
                ->error(500, 'Internal server error', 'internal_error')
                ->send();
        }
    }

	/**
	 * Register a route. Third-party mods call this (or the verb helpers below)
	 * from an integrate_api_routes handler.
	 *
	 * @param class-string $class A controller extending AbstractController.
	 */
	public function add(string $method, string $pattern, string $class, string $action): self
	{
		$this->routes[] = [
			'method'  => strtoupper($method),
			'pattern' => trim($pattern, '/'),
			'class'   => $class,
			'action'  => $action,
		];

		return $this;
	}

	public function get(string $pattern, string $class, string $action): self
	{
		return $this->add('GET', $pattern, $class, $action);
	}

	public function post(string $pattern, string $class, string $action): self
	{
		return $this->add('POST', $pattern, $class, $action);
	}

	public function put(string $pattern, string $class, string $action): self
	{
		return $this->add('PUT', $pattern, $class, $action);
	}

	public function patch(string $pattern, string $class, string $action): self
	{
		return $this->add('PATCH', $pattern, $class, $action);
	}

	public function delete(string $pattern, string $class, string $action): self
	{
		return $this->add('DELETE', $pattern, $class, $action);
	}

	private function registerDefaultRoutes(): void
	{
		// Collections nest by ownership: a child list lives under its parent
		// (…/boards, …/topics, …/posts). The parent id travels in the path, not
		// the query string, so it never collides with SMF's reserved query keys
		// (board, topic, msg, …). Flat collections still list across all parents.
		$this->get('categories', CategoryController::class, 'index');
		$this->get('categories/{id}', CategoryController::class, 'show');
		$this->get('categories/{cat_id}/boards', BoardController::class, 'index');
		$this->post('categories', CategoryController::class, 'store');
		$this->put('categories/{id}', CategoryController::class, 'replace');
		$this->patch('categories/{id}', CategoryController::class, 'update');
		$this->delete('categories/{id}', CategoryController::class, 'destroy');

		$this->get('members', MemberController::class, 'index');
		$this->get('members/{id}', MemberController::class, 'show');
		$this->post('members', MemberController::class, 'store');
		$this->put('members/{id}', MemberController::class, 'replace');
		$this->patch('members/{id}', MemberController::class, 'update');
		$this->delete('members/{id}', MemberController::class, 'destroy');

		$this->get('boards', BoardController::class, 'index');
		$this->get('boards/{id}', BoardController::class, 'show');
		$this->get('boards/{board_id}/topics', TopicController::class, 'index');
		$this->post('boards', BoardController::class, 'store');
		$this->put('boards/{id}', BoardController::class, 'replace');
		$this->patch('boards/{id}', BoardController::class, 'update');
		$this->delete('boards/{id}', BoardController::class, 'destroy');

		$this->get('topics', TopicController::class, 'index');
		$this->get('topics/{id}', TopicController::class, 'show');
		$this->get('topics/{topic_id}/posts', PostController::class, 'index');
		$this->post('topics', TopicController::class, 'store');
		$this->put('topics/{id}', TopicController::class, 'replace');
		$this->patch('topics/{id}', TopicController::class, 'update');
		$this->delete('topics/{id}', TopicController::class, 'destroy');

		$this->get('posts/{id}', PostController::class, 'show');
		$this->post('posts', PostController::class, 'store');
		$this->put('posts/{id}', PostController::class, 'replace');
		$this->patch('posts/{id}', PostController::class, 'update');
		$this->delete('posts/{id}', PostController::class, 'destroy');
	}

	/**
	 * @return array{0:class-string,1:string,2:array<string,string>}
	 */
	private function match(Request $request): array
	{
		$segments = $request->getSegments();

		if ($segments === []) {
			throw ApiException::notFound('No API resource specified');
		}

		// The first path segment is the API contract version, e.g. "v1". It is
		// consumed here so route patterns stay version-agnostic.
		$version = array_shift($segments);

		if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
			throw ApiException::notFound('Unsupported API version: ' . $version);
		}

		if ($segments === []) {
			throw ApiException::notFound('No API resource specified');
		}

		$method = $request->getMethod();

		$pathMatchedOtherMethod = false;

		foreach ($this->routes as $route) {
			$params = $this->matchPattern($route['pattern'], $segments);

			if ($params === null) {
				continue;
			}

			if ($route['method'] !== $method) {
				$pathMatchedOtherMethod = true;
				continue;
			}

			return [$route['class'], $route['action'], $params];
		}

		if ($pathMatchedOtherMethod) {
			throw ApiException::methodNotAllowed();
		}

		throw ApiException::notFound('Unknown API endpoint: ' . implode('/', $segments));
	}

	/**
	 * Compare a slash pattern against the request segments, capturing "{name}"
	 * placeholders. Returns the captured params, or null when it does not match.
	 *
	 * @return array<string, string>|null
	 */
	private function matchPattern(string $pattern, array $segments): ?array
	{
		$parts = explode('/', $pattern);

		if (count($parts) !== count($segments)) {
			return null;
		}

		$params = [];

		foreach ($parts as $i => $part) {
			if (isset($part[0]) && $part[0] === '{' && str_ends_with($part, '}')) {
				$params[trim($part, '{}')] = $segments[$i];

				continue;
			}

			if ($part !== $segments[$i]) {
				return null;
			}
		}

		return $params;
	}
}
