<?php declare(strict_types=1);

/**
 * MemberController.php
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
 * Read access to forum members.
 */
class MemberController extends AbstractController
{
	public function index(array $params): Response
	{
		if (! allowedTo('view_mlist')) {
			throw ApiException::forbidden('You cannot view the member list');
		}

		$query = (new Query())
			->from('{db_prefix}members')
			->where('is_activated = {int:active}')
			->columns('id_member, member_name, real_name, posts, date_registered, id_group, id_post_group, last_login')
			->orderBy('id_member')
			->params(['active' => 1]);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, member_name, real_name, email_address, posts, date_registered,
					id_group, id_post_group, last_login, is_activated')
				->params(['id' => $this->id($params)])
		);

		if ($row === null || (int) $row['is_activated'] !== 1) {
			throw ApiException::notFound('Member not found');
		}

		return $this->response()->data($this->transform($row, true));
	}

	/**
	 * Register a new member. Restricted to staff who may add members.
	 */
	public function store(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('moderate_forum')) {
			throw ApiException::forbidden('You cannot register members');
		}

		$username = trim((string) $this->request->input('username', ''));
		$email    = trim((string) $this->request->input('email', ''));
		$password = (string) $this->request->input('password', '');

		if ($username === '' || $email === '') {
			throw ApiException::badRequest('Fields "username" and "email" are required');
		}

		require_once($sourcedir . '/Subs-Members.php');

		$regOptions = [
			'interface'           => 'admin',
			'username'            => $username,
			'email'               => $email,
			'password'            => $password,
			'password_check'      => $password,
			'check_reserved_name' => true,
			'require'             => 'nothing',
			'send_welcome_email'  => false,
			'memberGroup'         => 0,
		];

		// With return_errors the function hands back an array of language keys
		// instead of triggering a fatal error, so we can answer with JSON.
		$result = registerMember($regOptions, true);

		if (is_array($result)) {
			throw ApiException::badRequest(
                'The member could not be registered',
                ['errors' => array_values($result)]
            );
		}

		return $this->response()
			->status(201)
			->data([
				'id'       => $result,
				'username' => $username,
				'email'    => $email,
			]);
	}

	/**
	 * Fully replace a member's editable profile (PUT): "name" and "email" are
	 * both required.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a member's editable profile (PATCH).
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a member account. Deleting your own account needs
	 * "profile_remove_own"; deleting anyone else needs "profile_remove_any".
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, id_group, additional_groups')
				->params(['id' => $id])
		);

		if ($row === null) {
			throw ApiException::notFound('Member not found');
		}

		$isSelf = $id === (int) $user_info['id'];

		if ($isSelf ? ! allowedTo('profile_remove_own') : ! allowedTo('profile_remove_any')) {
			throw ApiException::forbidden('You cannot delete this member');
		}

		// Only administrators may delete another administrator.
		if ($this->isAdminMember($row) && empty($user_info['is_admin'])) {
			throw ApiException::forbidden('You cannot delete an administrator');
		}

		require_once($sourcedir . '/Subs-Members.php');

		// The second argument keeps deleteMembers() from touching admins when the
		// caller is not one — our check above already rejects that case.
		deleteMembers([$id], empty($user_info['is_admin']));

		return $this->noContent();
	}

	/**
	 * Shared PUT/PATCH body: validate access and the fields, then apply them
	 * through SMF's updateMemberData() and answer with the stored member.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, is_activated')
				->params(['id' => $id])
		);

		if ($row === null || (int) $row['is_activated'] !== 1) {
			throw ApiException::notFound('Member not found');
		}

		$isSelf = $id === (int) $user_info['id'];

		if ($isSelf ? ! allowedTo('profile_identity_own') : ! allowedTo('profile_identity_any')) {
			throw ApiException::forbidden('You cannot edit this member');
		}

		$fields = $this->writable(['name', 'email'], $full);
		$data   = [];

		if (array_key_exists('name', $fields)) {
			$name = $this->cleanString($fields['name']);

			if ($name === '') {
				throw ApiException::badRequest('Field "name" cannot be empty');
			}

			$data['real_name'] = $name;
		}

		if (array_key_exists('email', $fields)) {
			$email = trim((string) $fields['email']);

			if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw ApiException::badRequest('Field "email" is not a valid address');
			}

			// SMF keeps member emails unique; reject an address already in use.
			$taken = $this->exists(
				(new Query())
					->from('{db_prefix}members')
					->where('email_address = {string:email} AND id_member != {int:id}')
					->params(['email' => $email, 'id' => $id])
			);

			if ($taken) {
				throw ApiException::badRequest('This email address is already in use');
			}

			$data['email_address'] = $email;
		}

		require_once($sourcedir . '/Subs-Members.php');

		updateMemberData($id, $data);

		$member = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, member_name, real_name, email_address, posts, date_registered,
					id_group, id_post_group, last_login, is_activated')
				->params(['id' => $id])
		);

		return $this->response()->data($this->transform($member, true));
	}

	/**
	 * Whether a member row belongs to the administrator group (as primary or
	 * additional group).
	 */
	private function isAdminMember(array $row): bool
	{
		if ((int) $row['id_group'] === 1) {
			return true;
		}

		$additional = array_map(intval(...), array_filter(explode(',', (string) $row['additional_groups'])));

		return in_array(1, $additional, true);
	}

	/**
	 * Shape a member row for output, hiding privileged fields from non-admins.
	 */
	protected function transform(array $row, bool $detailed = false): array
	{
		global $user_info;

		$member = [
			'id'              => (int) $row['id_member'],
			'username'        => $row['member_name'],
			'name'            => $row['real_name'],
			'posts'           => (int) $row['posts'],
			'date_registered' => (int) $row['date_registered'],
			'last_login'      => (int) $row['last_login'],
			'primary_group'   => (int) $row['id_group'],
			'post_group'      => (int) $row['id_post_group'],
		];

		// The email address is only ever exposed to administrators.
		if ($detailed && ! empty($user_info['is_admin'])) {
			$member['email'] = $row['email_address'];
		}

		return $member;
	}
}
