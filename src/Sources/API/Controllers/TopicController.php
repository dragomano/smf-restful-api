<?php declare(strict_types=1);

/**
 * TopicController.php
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
 * Read and create topics.
 */
class TopicController extends AbstractController
{
	public function index(array $params): Response
	{
		global $user_info;

		$where       = ['{query_see_board}'];
		$queryParams = [];

		// Nested route /boards/{board_id}/topics scopes the list to one board.
		// The id comes from the path, never a "board" query param (that key is
		// reserved by SMF and would make the bootstrap render a board page).
		if (isset($params['board_id'])) {
			$board = $this->id($params, 'board_id');

			$visible = $this->exists(
				(new Query())
					->from('{db_prefix}boards AS b')
					->where('{query_see_board} AND b.id_board = {int:board}')
					->params(['board' => $board])
			);

			if (! $visible) {
				throw ApiException::notFound('Board not found');
			}

			$where[] = 't.id_board = {int:board}';

			$queryParams['board'] = $board;
		}

		if (empty($user_info['is_admin'])) {
			$where[] = 't.approved = {int:approved}';

			$queryParams['approved'] = 1;
		}

		$query = (new Query())
			->from('{db_prefix}topics AS t
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
			->where(implode(' AND ', $where))
			->columns('t.id_topic, t.id_board, t.id_first_msg, t.id_last_msg, t.num_replies, t.num_views,
				t.locked, t.is_sticky, t.approved, ms.subject, ms.poster_time, ms.id_member AS starter_id,
				ms.poster_name AS starter_name')
			->orderBy('t.id_last_msg DESC')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		global $user_info;

		$row = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.id_board, t.id_first_msg, t.id_last_msg, t.num_replies, t.num_views,
					t.locked, t.is_sticky, t.approved, ms.subject, ms.body, ms.poster_time,
					ms.id_member AS starter_id, ms.poster_name AS starter_name')
				->params(['id' => $this->id($params)])
		);

		if ($row === null || (empty($user_info['is_admin']) && (int) $row['approved'] !== 1)) {
			throw ApiException::notFound('Topic not found');
		}

		$topic = $this->transform($row);

		$topic['body']     = parse_bbc($row['body'], true, 'api-' . $row['id_first_msg']);
		$topic['body_raw'] = $row['body'];

		return $this->response()->data($topic);
	}

	/**
	 * Create a new topic (its opening post) on behalf of the API member.
	 */
	public function store(array $params): Response
	{
		global $sourcedir, $user_info, $modSettings;

		$board   = (int) $this->request->input('board', 0);
		$subject = $this->cleanString($this->request->input('subject', ''));
		$body    = trim((string) $this->request->input('body', ''));

		if ($board <= 0 || $subject === '' || $body === '') {
			throw ApiException::badRequest('Fields "board", "subject" and "body" are required');
		}

		// The board must exist and be visible, and the member must be able to
		// start a topic there.
		$exists = $this->exists(
			(new Query())
				->from('{db_prefix}boards AS b')
				->where('{query_see_board} AND b.id_board = {int:board}')
				->params(['board' => $board])
		);

		if (! $exists) {
			throw ApiException::notFound('Board not found');
		}

		if (! allowedTo('post_new', $board) && ! allowedTo('post_unapproved_topics', $board)) {
			throw ApiException::forbidden('You cannot start topics on this board');
		}

		require_once($sourcedir . '/Subs-Post.php');

		// SMF stores messages pre-parsed; run the same pipeline the editor uses.
		$preparedBody = $body;

		preparsecode($preparedBody);

		// Mirror Post.php: with post moderation on, members who only hold the
		// "unapproved" permission have their topic queued for approval.
		$becomesApproved = true;
		if (
            ! empty($modSettings['postmod_active'])
            && ! allowedTo('post_new', $board)
            && allowedTo('post_unapproved_topics', $board)
        ) {
			$becomesApproved = false;
		}

		$msgOptions = [
			'id'              => 0,
			'subject'         => $subject,
			'body'            => $preparedBody,
			'icon'            => 'xx',
			'smileys_enabled' => true,
			'attachments'     => [],
			'approved'        => $becomesApproved,
		];
		$topicOptions = [
			'id'           => 0,
			'board'        => $board,
			'mark_as_read' => true,
			'is_approved'  => $becomesApproved,
		];
		$posterOptions = [
			'id'                => (int) $user_info['id'],
			'name'              => $user_info['name'],
			'email'             => $user_info['email'],
			'update_post_count' => true,
			'ip'                => $user_info['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
		];

		if (! createPost($msgOptions, $topicOptions, $posterOptions)) {
			throw ApiException::badRequest('The topic could not be created');
		}

		return $this->response()
			->status(201)
			->data([
				'id'           => $topicOptions['id'],
				'board'        => $board,
				'subject'      => $subject,
				'id_first_msg' => (int) $msgOptions['id'],
			]);
	}

	/**
	 * Fully replace a topic's editable fields (PUT): "subject", "locked" and
	 * "sticky" are all required.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a topic (PATCH): any of "subject", "locked", "sticky".
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a topic. Removing your own topic needs "remove_own"; removing
	 * anyone's needs "remove_any". Honors the recycle bin when enabled.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.id_board, t.id_member_started')
				->params(['id' => $id])
		);

		if ($topic === null) {
			throw ApiException::notFound('Topic not found');
		}

		$board     = (int) $topic['id_board'];
		$isStarter = (int) $topic['id_member_started'] === (int) $user_info['id'];
		$canRemove = allowedTo('remove_any', $board) || ($isStarter && allowedTo('remove_own', $board));

		if (! $canRemove) {
			throw ApiException::forbidden('You cannot delete this topic');
		}

		require_once($sourcedir . '/RemoveTopic.php');

		removeTopics([$id]);

		return $this->noContent();
	}

	/**
	 * Shared PUT/PATCH body. Topic properties live on the opening post, so the
	 * changes are applied through SMF's modifyPost(): the subject edits the
	 * first message, while locked/sticky toggle the topic flags.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.id_board, t.id_first_msg, t.id_member_started,
					ms.id_member AS author_id, ms.subject, ms.body')
				->params(['id' => $id])
		);

		if ($topic === null) {
			throw ApiException::notFound('Topic not found');
		}

		$board     = (int) $topic['id_board'];
		$isStarter = (int) $topic['id_member_started'] === (int) $user_info['id'];
		$isAuthor  = (int) $topic['author_id'] === (int) $user_info['id'];
		$fields    = $this->writable(['subject', 'locked', 'sticky'], $full);

		// modifyPost() reads the body unconditionally (mentions/quotes), so the
		// current text is passed through unchanged; only the subject is editable
		// here.
		$msgOptions = [
			'id'   => (int) $topic['id_first_msg'],
			'body' => $topic['body'],
		];
		$topicOptions = [
			'id'    => $id,
			'board' => $board,
		];
		$posterOptions = [
			'id' => (int) $topic['author_id'],
		];

		if (array_key_exists('subject', $fields)) {
			// Editing the subject edits the opening post.
			if (! allowedTo('modify_any', $board) && ! ($isAuthor && allowedTo('modify_own', $board))) {
				throw ApiException::forbidden('You cannot edit this topic\'s subject');
			}

			$subject = $this->cleanString($fields['subject']);

			if ($subject === '') {
				throw ApiException::badRequest('Field "subject" cannot be empty');
			}

			$msgOptions['subject']       = $subject;
			$msgOptions['modify_time']   = time();
			$msgOptions['modify_name']   = $user_info['name'];
			$msgOptions['modify_reason'] = '';
		}

		if (array_key_exists('locked', $fields)) {
			if (! allowedTo('lock_any', $board) && ! ($isStarter && allowedTo('lock_own', $board))) {
				throw ApiException::forbidden('You cannot lock this topic');
			}

			$topicOptions['lock_mode'] = $this->boolean($fields['locked']) ? 1 : 0;
		}

		if (array_key_exists('sticky', $fields)) {
			if (! allowedTo('make_sticky', $board)) {
				throw ApiException::forbidden('You cannot pin this topic');
			}

			$topicOptions['sticky_mode'] = $this->boolean($fields['sticky']) ? 1 : 0;
		}

		require_once($sourcedir . '/Subs-Post.php');

		modifyPost($msgOptions, $topicOptions, $posterOptions);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.id_board, t.id_first_msg, t.id_last_msg, t.num_replies, t.num_views,
					t.locked, t.is_sticky, t.approved, ms.subject, ms.poster_time, ms.id_member AS starter_id,
					ms.poster_name AS starter_name')
				->params(['id' => $id])
		);

		return $this->response()->data($this->transform($row));
	}

	protected function transform(array $row): array
	{
		return [
			'id'      => (int) $row['id_topic'],
			'board'   => (int) $row['id_board'],
			'subject' => $row['subject'],
			'starter' => [
				'id'   => (int) $row['starter_id'],
				'name' => $row['starter_name'],
			],
			'poster_time'  => (int) $row['poster_time'],
			'num_replies'  => (int) $row['num_replies'],
			'num_views'    => (int) $row['num_views'],
			'locked'       => (bool) $row['locked'],
			'is_sticky'    => (bool) $row['is_sticky'],
			'approved'     => (bool) $row['approved'],
			'id_first_msg' => (int) $row['id_first_msg'],
			'id_last_msg'  => (int) $row['id_last_msg'],
		];
	}
}
