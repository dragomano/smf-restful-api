<?php declare(strict_types=1);

/**
 * Authenticator.php
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
 * Turns an API key into an authenticated SMF member and rebuilds the global
 * user context ($user_info) for that member using SMF's own routines, so that
 * isAllowedTo(), allowedTo() and {query_see_board} behave exactly as they would
 * for a logged-in browser session.
 *
 * Keys live in $modSettings['api_keys'] as a JSON object mapping the opaque
 * token to an id_member: {"7f3c...": 12, "a19b...": 1}.
 */
class Authenticator
{
	/** @var array The authenticated member row (id_member, member_name, ...). */
	private array $member = [];

	/**
	 * Authenticate the request or throw. On success the SMF globals are set up
	 * for the resolved member.
	 */
	public function authenticate(Request $request): void
	{
		$idMember = null;

		// Let mods provide a custom authentication scheme (OAuth, JWT, HMAC,
		// ...) by resolving a member id from the request themselves.
		call_integration_hook('integrate_api_authenticate', [$request, &$idMember]);

		if ($idMember === null) {
			$key = $this->extractKey($request);

			if ($key === '') {
				throw ApiException::unauthorized('Missing API key');
			}

			$idMember = $this->resolveKey($key);

			if ($idMember === null) {
				throw ApiException::unauthorized('Invalid API key');
			}
		}

		$this->member = $this->loadMember((int) $idMember);

		if ($this->member === []) {
			throw ApiException::unauthorized('The member tied to this key no longer exists');
		}

		$this->applyUserContext();
	}

	public function id(): int
	{
		return (int) ($this->member['id_member'] ?? 0);
	}

	public function member(): array
	{
		return $this->member;
	}

	public function isAdmin(): bool
	{
		global $user_info;

		return ! empty($user_info['is_admin']);
	}

	/**
	 * Guard a permission the SMF way; throws 403 when the member lacks it.
	 */
	public function require(string $permission, $boards = null): void
	{
		if (! allowedTo($permission, $boards)) {
			throw ApiException::forbidden('You lack the "' . $permission . '" permission');
		}
	}

	/**
	 * Pull the token from the Authorization header, a dedicated header, or the
	 * query string, in that order of preference.
	 */
	private function extractKey(Request $request): string
	{
		$auth = (string) $request->header('authorization', '');

		if (stripos($auth, 'bearer ') === 0) {
			return trim(substr($auth, 7));
		}

		$header = $request->header('x-api-key');

		if (! empty($header)) {
			return trim($header);
		}

		return trim((string) $request->query('api_key', ''));
	}

	/**
	 * Match the token against the configured key map without leaking timing.
	 */
	private function resolveKey(string $key): ?int
	{
		global $modSettings;

		$map = json_decode($modSettings['api_keys'] ?? '', true);

		if (! is_array($map)) {
			return null;
		}

		foreach ($map as $storedKey => $idMember) {
			if (hash_equals((string) $storedKey, $key)) {
				return (int) $idMember;
			}
		}

		return null;
	}

	private function loadMember(int $idMember): array
	{
		$row = (new Query())
			->from('{db_prefix}members')
			->where('id_member = {int:id}')
			->columns('id_member, member_name, real_name, email_address, id_group, id_post_group,
				additional_groups, is_activated')
			->params(['id' => $idMember])
			->first();

		// Only fully activated accounts may drive the API.
		if ($row === null || (int) $row['is_activated'] !== 1) {
			return [];
		}

		return $row;
	}

	/**
	 * Rebuild $user_info for the resolved member and load their permissions and
	 * board-visibility queries via the core helpers.
	 */
	private function applyUserContext(): void
	{
		global $user_info, $modSettings;

		$groups = array_merge(
			[(int) $this->member['id_group'], (int) $this->member['id_post_group']],
			array_map(intval(...), array_filter(explode(',', (string) $this->member['additional_groups'])))
		);
		$groups = array_values(array_unique(array_filter($groups, static fn ($g) => $g !== null)));

		$user_info['id']                = (int) $this->member['id_member'];
		$user_info['username']          = $this->member['member_name'];
		$user_info['name']              = $this->member['real_name'];
		$user_info['email']             = $this->member['email_address'];
		$user_info['is_guest']          = false;
		$user_info['groups']            = $groups;
		$user_info['is_admin']          = in_array(1, $groups, true);
		$user_info['possibly_robot']    = false;
		$user_info['ignoreboards']      = [];
		$user_info['permissions']       = [];
		$user_info['can_manage_boards'] = $user_info['is_admin']
			|| (! empty($modSettings['board_manager_groups'])
				&& count(array_intersect($groups, explode(',', (string) $modSettings['board_manager_groups']))) > 0);

		// Board-visibility clauses ({query_see_board} etc.) for this member.
		$user_info = array_merge($user_info, build_query_board($user_info['id']));

		// Populate $user_info['permissions'] from the member's groups.
		loadPermissions();
	}
}
