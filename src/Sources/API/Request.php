<?php declare(strict_types=1);

/**
 * Request.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Immutable view over the incoming HTTP request tailored for the API action.
 *
 * The resource path is taken from the "path" query parameter (works with the
 * default SMF url, e.g. index.php?action=api;path=v1/members/5) and falls back
 * to PATH_INFO so a rewrite rule such as
 *     RewriteRule ^api/(.*)$ index.php?action=api;path=$1 [QSA,L]
 * keeps clean URLs (e.g. /api/v1/members/5) working too. The leading segment is
 * the API contract version, which the Router validates.
 */
class Request
{
	/** @var string Uppercase HTTP verb, honouring method override headers. */
	private readonly string $method;

	/** @var string[] The resource path split into non-empty segments. */
	private array $segments;

	/** @var array Decoded JSON body, or the form payload for non-JSON posts. */
	private array $body;

	/** @var array Request headers keyed by lower-case name. */
	private array $headers;

	public function __construct()
	{
		$this->headers  = $this->collectHeaders();
		$this->method   = $this->resolveMethod();
		$this->segments = $this->resolvePath();
		$this->body     = $this->resolveBody();
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	/**
	 * @return string[]
	 */
	public function getSegments(): array
	{
		return $this->segments;
	}

	/**
	 * Positional path segment (0-based) or a default when absent.
	 */
	public function segment(int $index, ?string $default = null): ?string
	{
		return $this->segments[$index] ?? $default;
	}

	public function header(string $name, ?string $default = null): ?string
	{
		return $this->headers[strtolower($name)] ?? $default;
	}

	/**
	 * A query-string value ($_GET), cast is left to the caller.
	 */
	public function query(string $name, $default = null)
	{
		return $_GET[$name] ?? $default;
	}

	/**
	 * A field from the decoded request body.
	 */
	public function input(string $name, $default = null)
	{
		return $this->body[$name] ?? $default;
	}

	public function all(): array
	{
		return $this->body;
	}

	private function resolveMethod(): string
	{
		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

		// Allow clients that cannot send verbs other than GET/POST to override.
		$override = $this->header('x-http-method-override', $_GET['_method'] ?? null);

		if ($method === 'POST' && ! empty($override)) {
			$method = strtoupper($override);
		}

		return $method;
	}

	/**
	 * @return string[]
	 */
	private function resolvePath(): array
	{
		$raw = (string) ($_GET['path'] ?? '');

		if ($raw === '' && ! empty($_SERVER['PATH_INFO'])) {
			$path = trim((string) $_SERVER['PATH_INFO'], '/');
			// Drop a leading "api" segment left over from a rewrite rule.
			$raw = preg_replace('~^api/?~', '', $path);
		}

		$raw = trim($raw, '/');

		if ($raw === '') {
			return [];
		}

		return array_values(array_filter(explode('/', $raw), static fn ($s) => $s !== ''));
	}

	private function resolveBody(): array
	{
		$type = strtolower($this->header('content-type', ''));

		if (str_contains($type, 'application/json')) {
			$decoded = json_decode((string) file_get_contents('php://input'), true);

			if (json_last_error() !== JSON_ERROR_NONE) {
				throw ApiException::badRequest('Malformed JSON payload');
			}

			return is_array($decoded) ? $decoded : [];
		}

		return $_POST;
	}

	/**
	 * Normalize request headers to a lower-cased map, independent of SAPI.
	 */
	private function collectHeaders(): array
	{
		$headers = [];

		if (function_exists('getallheaders')) {
			foreach ((array) getallheaders() as $name => $value) {
				$headers[strtolower((string) $name)] = (string) $value;
			}
		}

		// Supplement from $_SERVER for SAPIs where getallheaders() is missing
		// or drops entries. Apache with mod_rewrite exposes a stripped
		// Authorization header as REDIRECT_HTTP_AUTHORIZATION, so both prefixes
		// are scanned.
		foreach ($_SERVER as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) {
				$name = strtolower(strtr(substr($key, 5), '_', '-'));

				$headers[$name] ??= (string) $value;
			} elseif (str_starts_with($key, 'REDIRECT_HTTP_')) {
				$name = strtolower(strtr(substr($key, 14), '_', '-'));

				$headers[$name] ??= (string) $value;
			}
		}

		return $headers;
	}
}
