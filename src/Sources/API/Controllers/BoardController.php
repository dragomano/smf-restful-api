<?php declare(strict_types=1);

/**
 * BoardController.php
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
use SMF\API\Response;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Read access to boards, filtered by what the authenticated member may see.
 */
class BoardController extends AbstractController
{
	public function index(array $params): Response
	{
		$where       = '{query_see_board}';
		$queryParams = [];

		// Nested route /categories/{cat_id}/boards scopes the list to one category.
		if (isset($params['cat_id'])) {
			$catId = $this->id($params, 'cat_id');

			if (! $this->categoryExists($catId)) {
				throw ApiException::notFound('Category not found');
			}

			$where .= ' AND b.id_cat = {int:cat}';

			$queryParams['cat'] = $catId;
		}

		$query = (new Query())
			->from('{db_prefix}boards AS b')
			->where($where)
			->columns('b.id_board, b.id_cat, b.id_parent, b.name, b.description, b.num_topics, b.num_posts, b.board_order')
			->orderBy('b.board_order')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}boards AS b')
				->where('{query_see_board} AND b.id_board = {int:id}')
				->columns('b.id_board, b.id_cat, b.id_parent, b.name, b.description, b.num_topics, b.num_posts, b.board_order')
				->params(['id' => $this->id($params)])
		);

		// A hidden board is indistinguishable from a missing one on purpose.
		if ($row === null) {
			throw ApiException::notFound('Board not found');
		}

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Create a new board inside a category. Restricted to board managers.
	 */
	public function store(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$category    = (int) $this->request->input('category', 0);
		$name        = $this->cleanString($this->request->input('name', ''));
		$description = $this->cleanString($this->request->input('description', ''));

		if ($category <= 0 || $name === '') {
			throw ApiException::badRequest('Fields "category" and "name" are required');
		}

		// The target category must exist.
		$exists = $this->exists(
			(new Query())
				->from('{db_prefix}categories')
				->where('id_cat = {int:id}')
				->params(['id' => $category])
		);

		if (! $exists) {
			throw ApiException::notFound('Category not found');
		}

		require_once($sourcedir . '/Subs-Boards.php');

		$id = createBoard([
			'board_name'        => $name,
			'board_description' => $description,
			'move_to'           => 'bottom',
			'target_category'   => $category,
		]);

		if (empty($id)) {
			throw ApiException::badRequest('The board could not be created');
		}

		return $this->response()
			->status(201)
			->data([
				'id'          => $id,
				'category'    => $category,
				'name'        => $name,
				'description' => $description,
			]);
	}

	/**
	 * Fully replace a board (PUT): "name" and "description" are required. An
	 * optional "category" moves the board to another category.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a board (PATCH): only the supplied fields change.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a board. Child boards move to the board given by the
	 * "move_children_to" query parameter, or are removed together with the
	 * parent when none is given. Restricted to board managers.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$id = $this->id($params);

		if (! $this->boardExists($id)) {
			throw ApiException::notFound('Board not found');
		}

		$moveChildrenTo = $this->request->query('move_children_to');
		$moveChildrenTo = $moveChildrenTo === null ? null : (int) $moveChildrenTo;

		if ($moveChildrenTo !== null && ($moveChildrenTo === $id || ! $this->boardExists($moveChildrenTo))) {
			throw ApiException::badRequest('Invalid "move_children_to" board');
		}

		require_once($sourcedir . '/Subs-Boards.php');

		deleteBoards([$id], $moveChildrenTo);

		return $this->noContent();
	}

	/**
	 * Shared PUT/PATCH body: validate access, apply the writable fields through
	 * SMF's modifyBoard(), then answer with the stored board.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$id = $this->id($params);

		$current = $this->first(
			(new Query())
				->from('{db_prefix}boards')
				->where('id_board = {int:id}')
				->columns('id_board, id_cat')
				->params(['id' => $id])
		);

		if ($current === null) {
			throw ApiException::notFound('Board not found');
		}

		// "category" is an optional move target for both verbs, so it never
		// counts toward the required set of a full replace.
		$fields = $this->writable(['name', 'description'], $full);

		// modifyBoard() refreshes the parsed-description cache for the board's
		// category and, when the board is not being moved, reads it from
		// old_id_cat. SMF's own board editor passes this; without it PHP 8 logs
		// an "Undefined array key: old_id_cat" notice.
		$boardOptions = ['old_id_cat' => (int) $current['id_cat']];

		if (array_key_exists('name', $fields)) {
			$name = $this->cleanString($fields['name']);

			if ($name === '') {
				throw ApiException::badRequest('Field "name" cannot be empty');
			}

			$boardOptions['board_name'] = $name;
		}

		if (array_key_exists('description', $fields)) {
			$boardOptions['board_description'] = $this->cleanString($fields['description']);
		}

		$category = $this->request->input('category');
		if ($category !== null && (int) $category !== (int) $current['id_cat']) {
			$category = (int) $category;

			$categoryExists = $this->exists(
				(new Query())
					->from('{db_prefix}categories')
					->where('id_cat = {int:id}')
					->params(['id' => $category])
			);

			if (! $categoryExists) {
				throw ApiException::notFound('Category not found');
			}

			// modifyBoard() moves a board by dropping it at the bottom of the
			// target category.
			$boardOptions['move_to']         = 'bottom';
			$boardOptions['target_category'] = $category;
		}

		require_once($sourcedir . '/Subs-Boards.php');

		modifyBoard($id, $boardOptions);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}boards AS b')
				->where('b.id_board = {int:id}')
				->columns('b.id_board, b.id_cat, b.id_parent, b.name, b.description, b.num_topics, b.num_posts, b.board_order')
				->params(['id' => $id])
		);

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Whether a board with this id exists.
	 */
	private function boardExists(int $id): bool
	{
		return $this->exists(
			(new Query())
				->from('{db_prefix}boards')
				->where('id_board = {int:id}')
				->params(['id' => $id])
		);
	}

	/**
	 * Whether a category with this id exists.
	 */
	private function categoryExists(int $id): bool
	{
		return $this->exists(
			(new Query())
				->from('{db_prefix}categories')
				->where('id_cat = {int:id}')
				->params(['id' => $id])
		);
	}

	protected function transform(array $row): array
	{
		return [
			'id'          => (int) $row['id_board'],
			'category'    => (int) $row['id_cat'],
			'parent'      => (int) $row['id_parent'],
			'name'        => $row['name'],
			'description' => $row['description'],
			'num_topics'  => (int) $row['num_topics'],
			'num_posts'   => (int) $row['num_posts'],
			'order'       => (int) $row['board_order'],
		];
	}
}
