<?php declare(strict_types=1);

/**
 * CategoryController.php
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
 * Read and create forum categories — the containers that hold boards.
 */
class CategoryController extends AbstractController
{
	public function index(array $params): Response
	{
		// Only categories that hold at least one board the member may see.
		$query = (new Query())
			->from('{db_prefix}categories AS c')
			->where('
				EXISTS (
					SELECT 1
					FROM {db_prefix}boards AS b
					WHERE b.id_cat = c.id_cat
						AND {query_see_board}
				)')
			->columns('c.id_cat, c.name, c.description, c.cat_order')
			->orderBy('c.cat_order');

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}categories')
				->where('id_cat = {int:id}')
				->columns('id_cat, name, description, cat_order')
				->params(['id' => $id])
		);

		if ($row === null) {
			throw ApiException::notFound('Category not found');
		}

		$category = $this->transform($row);

		$category['boards'] = $this->boardsIn($id);

		return $this->response()->data($category);
	}

	/**
	 * Create a new category. Restricted to members who can manage boards.
	 */
	public function store(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage categories');
		}

		$name        = $this->cleanString($this->request->input('name', ''));
		$description = $this->cleanString($this->request->input('description', ''));

		if ($name === '') {
			throw ApiException::badRequest('Field "name" is required');
		}

		require_once($sourcedir . '/Subs-Categories.php');

		$id = createCategory([
			'cat_name' => $name,
			'cat_desc' => $description,
		]);

		return $this->response()
			->status(201)
			->data([
				'id'          => (int) $id,
				'name'        => $name,
				'description' => $description,
			]);
	}

	/**
	 * Fully replace a category (PUT): every writable field is required.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a category (PATCH): only the supplied fields change.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a category. Its boards move to the category given by the
	 * "move_boards_to" query parameter, or are removed with the category when
	 * none is given. Restricted to members who can manage boards.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage categories');
		}

		$id = $this->id($params);

		if (! $this->categoryExists($id)) {
			throw ApiException::notFound('Category not found');
		}

		// An optional target keeps the child boards; without it, they go too.
		$moveBoardsTo = $this->request->query('move_boards_to');
		$moveBoardsTo = $moveBoardsTo === null ? null : (int) $moveBoardsTo;

		if ($moveBoardsTo !== null && ($moveBoardsTo === $id || ! $this->categoryExists($moveBoardsTo))) {
			throw ApiException::badRequest('Invalid "move_boards_to" category');
		}

		require_once($sourcedir . '/Subs-Categories.php');

		deleteCategories([$id], $moveBoardsTo);

		return $this->noContent();
	}

	/**
	 * Shared PUT/PATCH body: validate access, apply the writable fields through
	 * SMF's modifyCategory(), then answer with the stored category.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage categories');
		}

		$id = $this->id($params);

		if (! $this->categoryExists($id)) {
			throw ApiException::notFound('Category not found');
		}

		$fields     = $this->writable(['name', 'description'], $full);
		$catOptions = [];

		if (array_key_exists('name', $fields)) {
			$name = $this->cleanString($fields['name']);

			if ($name === '') {
				throw ApiException::badRequest('Field "name" cannot be empty');
			}

			$catOptions['cat_name'] = $name;
		}

		if (array_key_exists('description', $fields)) {
			$catOptions['cat_desc'] = $this->cleanString($fields['description']);
		}

		require_once($sourcedir . '/Subs-Categories.php');

		modifyCategory($id, $catOptions);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}categories')
				->where('id_cat = {int:id}')
				->columns('id_cat, name, description, cat_order')
				->params(['id' => $id])
		);

		return $this->response()->data($this->transform($row));
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

	/**
	 * The boards inside a category that the member is allowed to see.
	 */
	private function boardsIn(int $idCat): array
	{
		$rows = $this->fetchAll(
			(new Query())
				->from('{db_prefix}boards AS b')
				->where('{query_see_board} AND b.id_cat = {int:id_cat}')
				->columns('b.id_board, b.name, b.description, b.num_topics, b.num_posts, b.board_order')
				->orderBy('b.board_order')
				->params(['id_cat' => $idCat])
		);

		return array_map(static fn (array $row): array => [
			'id'          => (int) $row['id_board'],
			'name'        => $row['name'],
			'description' => $row['description'],
			'num_topics'  => (int) $row['num_topics'],
			'num_posts'   => (int) $row['num_posts'],
			'order'       => (int) $row['board_order'],
		], $rows);
	}

	protected function transform(array $row): array
	{
		return [
			'id'          => (int) $row['id_cat'],
			'name'        => $row['name'],
			'description' => $row['description'],
			'order'       => (int) $row['cat_order'],
		];
	}
}
