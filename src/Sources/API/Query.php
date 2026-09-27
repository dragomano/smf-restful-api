<?php declare(strict_types=1);

/**
 * Query.php
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
 * A small description of a read query. A controller fills in the parts through
 * the fluent setters; the AbstractController runners (paginate/first/exists/
 * fetchAll) turn it into the matching SQL and execute it. The FROM/WHERE is
 * written once and reused by every flavor, so callers never duplicate a query
 * nor hand-write the COUNT, LIMIT 1 or pagination clauses.
 */
class Query
{
	private string $columns = '*';

	private string $from = '';

	private string $where = '1=1';

	private string $orderBy = '';

	/** @var array<string, mixed> */
	private array $params = [];

	public function columns(string $columns): self
	{
		$this->columns = $columns;

		return $this;
	}

	/**
	 * The FROM clause without the keyword, joins included, e.g.
	 * "{db_prefix}topics AS t INNER JOIN {db_prefix}boards AS b ON (...)".
	 */
	public function from(string $from): self
	{
		$this->from = $from;

		return $this;
	}

	public function where(string $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function orderBy(string $orderBy): self
	{
		$this->orderBy = $orderBy;

		return $this;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function params(array $params): self
	{
		$this->params = $params;

		return $this;
	}

	public function countQuery(): string
	{
		return 'SELECT COUNT(*)
			FROM ' . $this->from . '
			WHERE ' . $this->where;
	}

	public function selectQuery(): string
	{
		return 'SELECT ' . $this->columns . '
			FROM ' . $this->from . '
			WHERE ' . $this->where . '
			ORDER BY ' . $this->orderBy . '
			LIMIT {int:offset}, {int:limit}';
	}

	public function listQuery(): string
	{
		return 'SELECT ' . $this->columns . '
			FROM ' . $this->from . '
			WHERE ' . $this->where
			. ($this->orderBy === '' ? '' : '
			ORDER BY ' . $this->orderBy);
	}

	public function singleQuery(): string
	{
		return 'SELECT ' . $this->columns . '
			FROM ' . $this->from . '
			WHERE ' . $this->where . '
			LIMIT 1';
	}

	public function existsQuery(): string
	{
		return 'SELECT 1
			FROM ' . $this->from . '
			WHERE ' . $this->where . '
			LIMIT 1';
	}

	/**
	 * Fetch a single row, or null when nothing matches.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array
	{
		global $smcFunc;

		$request = $smcFunc['db_query']('', $this->singleQuery(), $this->params);

		$row = $smcFunc['db_fetch_assoc']($request) ?: null;

		$smcFunc['db_free_result']($request);

		return $row;
	}

	/**
	 * Whether at least one row matches.
	 */
	public function exists(): bool
	{
		global $smcFunc;

		$request = $smcFunc['db_query']('', $this->existsQuery(), $this->params);

		$exists = $smcFunc['db_num_rows']($request) > 0;

		$smcFunc['db_free_result']($request);

		return $exists;
	}

	/**
	 * Fetch every matching row (no pagination) as raw DB rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAll(): array
	{
		global $smcFunc;

		$request = $smcFunc['db_query']('', $this->listQuery(), $this->params);

		$rows = [];
		while ($row = $smcFunc['db_fetch_assoc']($request)) {
			$rows[] = $row;
		}

		$smcFunc['db_free_result']($request);

		return $rows;
	}

	/**
	 * Run the COUNT and the paginated SELECT together, returning the page rows
	 * and the grand total. {int:offset}/{int:limit} are supplied here and win
	 * over any caller-set keys.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function fetchPage(int $limit, int $offset): array
	{
		global $smcFunc;

		$countRequest = $smcFunc['db_query']('', $this->countQuery(), $this->params);

		[$total] = $smcFunc['db_fetch_row']($countRequest);

		$smcFunc['db_free_result']($countRequest);

		$request = $smcFunc['db_query']('', $this->selectQuery(), array_merge(
			$this->params,
			['offset' => $offset, 'limit' => $limit]
		));

		$rows = [];
		while ($row = $smcFunc['db_fetch_assoc']($request)) {
			$rows[] = $row;
		}

		$smcFunc['db_free_result']($request);

		return ['rows' => $rows, 'total' => (int) $total];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function parameters(): array
	{
		return $this->params;
	}
}
