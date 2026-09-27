<?php declare(strict_types=1);

/**
 * PostController.php
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
 * Read access to individual posts (messages).
 */
class PostController extends AbstractController
{
	/**
	 * List the posts (messages) of a topic. Nested route:
	 * GET /topics/{topic_id}/posts.
	 */
	public function index(array $params): Response
	{
		global $user_info;

		$topicId = $this->id($params, 'topic_id');

		// The topic must exist and be visible (approved, for non-admins).
		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.approved')
				->params(['id' => $topicId])
		);

		if ($topic === null || (empty($user_info['is_admin']) && (int) $topic['approved'] !== 1)) {
			throw ApiException::notFound('Topic not found');
		}

		$where       = '{query_see_board} AND m.id_topic = {int:topic}';
		$queryParams = ['topic' => $topicId];

		if (empty($user_info['is_admin'])) {
			$where .= ' AND m.approved = {int:approved}';

			$queryParams['approved'] = 1;
		}

		$query = (new Query())
			->from('{db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)')
			->where($where)
			->columns('m.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_name, m.poster_time,
				m.subject, m.body, m.approved, m.modified_time, m.modified_name')
			->orderBy('m.id_msg')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		global $user_info;

		$row = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_name, m.poster_time,
					m.subject, m.body, m.approved, m.modified_time, m.modified_name')
				->params(['id' => $this->id($params)])
		);

		if ($row === null || (empty($user_info['is_admin']) && (int) $row['approved'] !== 1)) {
			throw ApiException::notFound('Post not found');
		}

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Shape a message row for output (parsed and raw body).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function transform(array $row): array
	{
		return [
			'id'      => (int) $row['id_msg'],
			'topic'   => (int) $row['id_topic'],
			'board'   => (int) $row['id_board'],
			'subject' => $row['subject'],
			'author'  => [
				'id'   => (int) $row['id_member'],
				'name' => $row['poster_name'],
			],
			'poster_time'   => (int) $row['poster_time'],
			'modified_time' => (int) $row['modified_time'],
			'modified_name' => $row['modified_name'],
			'approved'      => (bool) $row['approved'],
			'body'          => parse_bbc($row['body'], true, 'api-' . $row['id_msg']),
			'body_raw'      => $row['body'],
		];
	}

	/**
	 * Post a reply to an existing topic on behalf of the API member.
	 */
	public function store(array $params): Response
	{
		global $sourcedir, $user_info, $modSettings;

		$topicId = (int) $this->request->input('topic', 0);
		$body    = trim((string) $this->request->input('body', ''));

		if ($topicId <= 0 || $body === '') {
			throw ApiException::badRequest('Fields "topic" and "body" are required');
		}

		// The topic must exist and be visible to the member.
		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_board, t.locked, t.id_member_started, ms.subject')
				->params(['id' => $topicId])
		);

		if ($topic === null) {
			throw ApiException::notFound('Topic not found');
		}

		$board     = (int) $topic['id_board'];
		$isStarter = (int) $topic['id_member_started'] === (int) $user_info['id'];

		// A locked topic only accepts replies from board moderators/admins.
		if (! empty($topic['locked']) && ! allowedTo('moderate_board', $board)) {
			throw ApiException::forbidden('This topic is locked');
		}

		$canReply = allowedTo('post_reply_any', $board)
			|| ($isStarter && allowedTo('post_reply_own', $board))
			|| allowedTo('post_unapproved_replies_any', $board)
			|| ($isStarter && allowedTo('post_unapproved_replies_own', $board));

		if (! $canReply) {
			throw ApiException::forbidden('You cannot reply on this board');
		}

		require_once($sourcedir . '/Subs-Post.php');

		$preparedBody = $body;

		preparsecode($preparedBody);

		// Queue for approval when the member only holds "unapproved" rights.
		$becomesApproved = true;
		if (
			! empty($modSettings['postmod_active'])
			&& ! allowedTo('post_reply_any', $board)
			&& ! ($isStarter && allowedTo('post_reply_own', $board))
		) {
			$becomesApproved = false;
		}

		$subject = $topic['subject'];
		if (stripos($subject, 'Re:') !== 0) {
			$subject = 'Re: ' . $subject;
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
			'id'           => $topicId,
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
			throw ApiException::badRequest('The reply could not be created');
		}

		return $this->response()
			->status(201)
			->data([
				'id'       => (int) $msgOptions['id'],
				'topic'    => $topicId,
				'board'    => $board,
				'approved' => $becomesApproved,
			]);
	}

	/**
	 * Fully replace a post (PUT): "body" is required. An optional "subject"
	 * edits the message subject too.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Update a post (PATCH). A post's editable content is its body, so "body"
	 * is required here as well; "subject" stays optional.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a post (message). When it is the only message in its topic the
	 * whole topic goes with it. Honors the recycle bin when enabled.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_member, m.poster_time, m.approved, t.id_board,
					t.id_first_msg, t.id_member_started, t.num_replies')
				->params(['id' => $id])
		);

		if (
            $row === null
            || (
                empty($user_info['is_admin'])
                && (int) $row['approved'] !== 1
                && (int) $row['id_member'] !== (int) $user_info['id']
            )
        ) {
			throw ApiException::notFound('Post not found');
		}

		$this->assertCanDelete($row);

		// removeMessage() aborts with an HTML fatal if asked to delete the opening
		// post of a topic that still has replies. Reject that as JSON instead.
		if ((int) $row['id_first_msg'] === (int) $row['id_msg'] && (int) $row['num_replies'] > 0) {
			throw ApiException::badRequest(
                'Cannot delete the first post while the topic has replies; delete the topic instead'
            );
		}

		require_once($sourcedir . '/RemoveTopic.php');

		// removeMessage() returns true only when it deletes a whole single-post
		// topic; for an ordinary reply it returns null after a successful delete.
		// Permission and existence were verified above, so the return value is not
		// a reliable success/failure signal — do not gate on it.
		removeMessage($id);

		return $this->noContent();
	}

	/**
	 * Shared PUT/PATCH body: check the edit permission, run the new body through
	 * the same pipeline the editor uses, and apply it with SMF's modifyPost().
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir, $user_info, $modSettings;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_time, m.approved')
				->params(['id' => $id])
		);

		if (
            $row === null
            || (
                empty($user_info['is_admin'])
                && (int) $row['approved'] !== 1
                && (int) $row['id_member'] !== (int) $user_info['id']
            )
        ) {
			throw ApiException::notFound('Post not found');
		}

		$board    = (int) $row['id_board'];
		$isAuthor = (int) $row['id_member'] === (int) $user_info['id'];

		// modify_any lets staff edit anything; otherwise only the author may
		// edit, and only within the configured time window.
		if (! allowedTo('modify_any', $board)) {
			if (! ($isAuthor && allowedTo('modify_own', $board))) {
				throw ApiException::forbidden('You cannot edit this post');
			}

			if (
                ! empty($modSettings['edit_disable_time'])
                && (int) $row['poster_time'] + $modSettings['edit_disable_time'] * 60 < time()
            ) {
				throw ApiException::forbidden('The time limit for editing this post has passed');
			}
		}

		$fields = $this->writable(['body'], $full);
		$body   = trim((string) $fields['body']);

		if ($body === '') {
			throw ApiException::badRequest('Field "body" cannot be empty');
		}

		require_once($sourcedir . '/Subs-Post.php');

		// SMF stores messages pre-parsed; run the same pipeline the editor uses.
		$preparedBody = $body;

		preparsecode($preparedBody);

		$msgOptions = [
			'id'            => $id,
			'body'          => $preparedBody,
			'modify_time'   => time(),
			'modify_name'   => $user_info['name'],
			'modify_reason' => $this->cleanString($this->request->input('reason', '')),
		];

		$subject = $this->request->input('subject');
		if ($subject !== null) {
			$subject = $this->cleanString($subject);

			if ($subject === '') {
				throw ApiException::badRequest('Field "subject" cannot be empty');
			}

			$msgOptions['subject'] = $subject;
		}

		$topicOptions = [
			'id'    => (int) $row['id_topic'],
			'board' => $board,
		];
		// Keep the original author for post-count and mention attribution.
		$posterOptions = [
			'id' => (int) $row['id_member'],
		];

		modifyPost($msgOptions, $topicOptions, $posterOptions);

		$updated = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_name, m.poster_time,
					m.subject, m.body, m.approved, m.modified_time, m.modified_name')
				->params(['id' => $id])
		);

		return $this->response()->data([
			'id'      => (int) $updated['id_msg'],
			'topic'   => (int) $updated['id_topic'],
			'board'   => (int) $updated['id_board'],
			'subject' => $updated['subject'],
			'author'  => [
				'id'   => (int) $updated['id_member'],
				'name' => $updated['poster_name'],
			],
			'poster_time'   => (int) $updated['poster_time'],
			'modified_time' => (int) $updated['modified_time'],
			'modified_name' => $updated['modified_name'],
			'approved'      => (bool) $updated['approved'],
			'body'          => parse_bbc($updated['body'], true, 'api-' . $updated['id_msg']),
			'body_raw'      => $updated['body'],
		]);
	}

	/**
	 * Reject to delete with a JSON 403 unless the member holds the permission
	 * SMF's removeMessage() would demand — checked here so we can answer with
	 * JSON instead of triggering a fatal HTML error inside the core function.
	 *
	 * @param array<string, mixed> $row
	 */
	private function assertCanDelete(array $row): void
	{
		global $user_info, $modSettings;

		$board     = (int) $row['id_board'];
		$isAuthor  = (int) $row['id_member'] === (int) $user_info['id'];
		$isStarter = (int) $row['id_member_started'] === (int) $user_info['id'];

		$deleteAny = allowedTo('delete_any', $board);

		if (! $deleteAny) {
			if ($isAuthor) {
				$canOwn = allowedTo('delete_own', $board)
					&& (
                        empty($modSettings['edit_disable_time'])
                        || (int) $row['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time()
                    );

				if (! $canOwn && ! ($isStarter && allowedTo('delete_replies', $board))) {
					throw ApiException::forbidden('You cannot delete this post');
				}
			} elseif (! ($isStarter && allowedTo('delete_replies', $board))) {
				throw ApiException::forbidden('You cannot delete this post');
			}
		}

		// Removing the opening message removes the whole topic, which needs the
		// topic-removal permission on top of the message one.
		if ((int) $row['id_first_msg'] === (int) $row['id_msg']) {
			if (
                ! allowedTo('remove_any', $board)
                && ! ($isAuthor && allowedTo('remove_own', $board))
            ) {
				throw ApiException::forbidden('You cannot delete the first post of this topic');
			}
		}

		// A pending post you cannot approve stays invisible, so it stays undeletable.
		if (
            ! empty($modSettings['postmod_active'])
            && (int) $row['approved'] !== 1
            && ! $isAuthor
            && ! $deleteAny
            && ! allowedTo('approve_posts', $board)
        ) {
			throw ApiException::forbidden('You cannot delete this post');
		}
	}
}
