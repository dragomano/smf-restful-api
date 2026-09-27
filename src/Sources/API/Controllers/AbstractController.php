<?php declare(strict_types=1);

/**
 * AbstractController.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API\Controllers;

use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Request;
use SMF\API\Response;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Shared plumbing for resource controllers: request access, pagination and a
 * response factory.
 */
abstract class AbstractController
{
	/** Hard ceiling on how many rows one collection request may return. */
	protected const MAX_LIMIT = 100;

	/** Default page size when the client does not ask for one. */
	protected const DEFAULT_LIMIT = 25;

	public function __construct(protected Request $request)
	{
	}

	protected function response(): Response
	{
		return new Response();
	}

	/**
	 * Read a required positive integer route parameter.
	 */
	protected function id(array $params, string $name = 'id'): int
	{
		$value = $params[$name] ?? '';

		if (! ctype_digit((string) $value) || (int) $value <= 0) {
			throw ApiException::badRequest('Invalid "' . $name . '" identifier');
		}

		return (int) $value;
	}

	/**
	 * Resolve limit/offset from the query string, clamped to sane bounds.
	 *
	 * @return array{0:int,1:int} [limit, offset]
	 */
	protected function pagination(): array
	{
		$limit  = (int) $this->request->query('limit', self::DEFAULT_LIMIT);
		$limit  = max(1, min($limit, self::MAX_LIMIT));
		$offset = max(0, (int) $this->request->query('offset', 0));

		return [$limit, $offset];
	}

	/**
	 * Run a paginated Query and shape it into a collection Response. Each row is
	 * passed through transform(), which collection controllers override.
	 */
	protected function paginate(Query $query): Response
	{
		[$limit, $offset] = $this->pagination();

		['rows' => $rows, 'total' => $total] = $query->fetchPage($limit, $offset);

		$items = array_map($this->transform(...), $rows);

		return $this->response()->collection($items, $total, $limit, $offset);
	}

	/**
	 * Fetch a single row for a Query, or null when nothing matches.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function first(Query $query): ?array
	{
		return $query->first();
	}

	/**
	 * Whether at least one row matches the Query.
	 */
	protected function exists(Query $query): bool
	{
		return $query->exists();
	}

	/**
	 * Fetch every matching row of a Query (no pagination), as raw DB rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchAll(Query $query): array
	{
		return $query->fetchAll();
	}

	/**
	 * Shape a raw DB row for output. Collection controllers override this; the
	 * default returns the row unchanged.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function transform(array $row): array
	{
		return $row;
	}

	/**
	 * Sanitize a string coming from the request body for safe storage.
	 */
	protected function cleanString($value): string
	{
		global $smcFunc;

		return $smcFunc['htmlspecialchars'](trim((string) $value), ENT_QUOTES);
	}

	/**
	 * Collect the writable fields a client sent in the request body.
	 *
	 * The verb decides how strict we are: a full replace (PUT) demands every
	 * field in $fields and rejects the request otherwise, while a partial
	 * update (PATCH) keeps just the fields that were actually supplied. A PATCH
	 * that changes nothing is itself a bad request.
	 *
	 * @param array<int, string> $fields Field names accepted for this resource.
	 * @return array<string, mixed> The provided fields, keyed by name.
	 */
	protected function writable(array $fields, bool $full): array
	{
		$body = $this->request->all();
		$data = [];

		foreach ($fields as $field) {
			if (array_key_exists($field, $body)) {
				$data[$field] = $body[$field];
			} elseif ($full) {
				throw ApiException::badRequest('Field "' . $field . '" is required for a full update');
			}
		}

		if (! $full && $data === []) {
			throw ApiException::badRequest('No writable fields provided');
		}

		return $data;
	}

	/**
	 * Interpret a request value as a boolean, accepting the usual JSON and
	 * form-encoded spellings (true/false, 1/0, "yes"/"no", "on"/"off").
	 */
	protected function boolean($value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * An empty 204 response for successful writes that return no body field.
	 */
	protected function noContent(): Response
	{
		return $this->response()->status(204);
	}
}
