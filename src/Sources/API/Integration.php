<?php declare(strict_types=1);

/**
 * Integration.php
 *
 * Entry point and admin wiring for the SMF RESTful API modification.
 *
 * The mod exposes a single SMF action (index.php?action=api) that is handed
 * over to a small router. Resource controllers live in the Controllers/
 * sub-namespace and are resolved through the integrate_autoload hook.
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

class Integration
{
	public function hooks(): void
	{
		add_integration_function('integrate_autoload', self::class . '::autoload#', false);
		add_integration_function('integrate_actions', self::class . '::actions#', false);
		add_integration_function('integrate_admin_areas', self::class . '::adminAreas#', false);
		add_integration_function('integrate_admin_search', self::class . '::adminSearch#', false);
		add_integration_function('integrate_modify_modifications', self::class . '::modifyModifications#', false);

		// When the Merge Double Posts mod is installed, keep its browser-only
		// redirect out of the API flow (see suppressPostMerge()).
		add_integration_function('integrate_mdp_create_post', self::class . '::suppressPostMerge#', false);
	}

	/**
	 * @hook integrate_autoload
	 */
	public function autoload(array &$classMap): void
	{
		$classMap['SMF\\API\\'] = 'API/';
	}

	/**
	 * @hook integrate_actions
	 */
	public function actions(array &$actionArray): void
	{
		$actionArray['api'] = [false, [$this, 'dispatch']];
	}

	/**
	 * Front controller for index.php?action=api. Never returns: the Response
	 * object ends the request once the JSON has been written.
	 */
	public function dispatch(): void
	{
		global $modSettings;

		if (empty($modSettings['api_enabled'])) {
			(new Response())
				->error(503, 'The API is currently disabled', 'service_unavailable')
				->send();
		}

		// Mark the request as an API call so hooks fired deep inside SMF — such
		// as the Merge Double Posts createPost handler — can adapt instead of
		// assuming a browser is waiting for a redirect.
		if (! defined('SMF_API_REQUEST')) {
			define('SMF_API_REQUEST', true);
		}

		(new Router())->dispatch();
	}

	/**
	 * @hook integrate_mdp_create_post
	 *
	 * The Merge Double Posts mod ends its integrate_create_post handler with a
	 * redirectexit() to the merged message. Inside createPost() that would abort
	 * the request before the API can emit its JSON response, so the client would
	 * receive an HTML redirect instead. When the current request is an API call
	 * we blank out the "last message" the mod inspects: it then bails out before
	 * merging or redirecting, and every POST creates a distinct message with a
	 * predictable id — the REST semantics our 201 response promises.
	 *
	 * The hook is MDP-specific; when that mod is absent this handler never fires,
	 * so registering it is harmless.
	 */
	public function suppressPostMerge(&$idLastMsg): void
	{
		if (defined('SMF_API_REQUEST')) {
			$idLastMsg = 0;
		}
	}

	/**
	 * @hook integrate_admin_areas
	 */
	public function adminAreas(array &$admin_areas): void
	{
		global $txt;

		loadLanguage('API');

		$admin_areas['config']['areas']['modsettings']['subsections']['api'] = [$txt['api_title']];
	}

	/**
	 * @hook integrate_admin_search
	 */
	public function adminSearch(array &$language_files, array $include_files, array &$settings_search): void
	{
		$language_files[] = 'API';

		$settings_search[] = [[$this, 'settings'], 'area=modsettings;sa=api'];
	}

	/**
	 * @hook integrate_modify_modifications
	 */
	public function modifyModifications(array &$subActions): void
	{
		$subActions['api'] = [$this, 'settings'];
	}

	/**
	 * The admin settings page (Admin -> Configuration -> Modifications -> API).
	 * Renders a custom sub-template with a table-based key editor instead of a
	 * raw JSON field.
	 *
	 * @return array|void The config vars when $return_config is true.
	 */
	public function settings(bool $return_config = false)
	{
		global $context, $txt, $scripturl;

		loadLanguage('API');

		// Advertise the single saved setting for the admin search index.
		$config_vars = [
			['check', 'api_enabled', 'subtext' => $txt['api_enabled_subtext']],
		];

		if ($return_config) {
			return $config_vars;
		}

		loadTemplate('API');
		loadCSSFile('api.css', ['default_theme' => true, 'minimize' => true], 'smf_api');

		$context['page_title']   = $context['settings_title'] = $txt['api_title'];
		$context['post_url']     = $scripturl . '?action=admin;area=modsettings;save;sa=api';
		$context['sub_template'] = 'api_settings';
		$context['api_keys']     = $this->decodeKeys();

		if (isset($_GET['save'])) {
			checkSession();

			// Only redirect on a clean save; malformed rows keep us on the page.
			if ($this->saveSettings()) {
				redirectexit('action=admin;area=modsettings;sa=api');
			}
		}
	}

	/**
	 * Decode the stored token => member map into rows for the editor.
	 *
	 * @return array<int, array{token: string, member: int}>
	 */
	private function decodeKeys(): array
	{
		global $modSettings;

		$map = json_decode($modSettings['api_keys'] ?? '', true);

		if (! is_array($map)) {
			return [];
		}

		$rows = [];
		foreach ($map as $token => $idMember) {
			$rows[] = ['token' => (string) $token, 'member' => (int) $idMember];
		}

		return $rows;
	}

	/**
	 * Persist the enable switch and rebuild the token => member map from the
	 * table rows. Valid rows are always saved; malformed ones are reported and
	 * the submitted input is kept so a typo can never lock everyone out silently.
	 *
	 * @return bool True on a clean save, false when some rows were rejected.
	 */
	private function saveSettings(): bool
	{
		global $context, $txt;

		$tokens  = (array) ($_POST['api_key_token'] ?? []);
		$members = (array) ($_POST['api_key_member'] ?? []);

		$map     = [];
		$rows    = [];
		$invalid = false;

		foreach ($tokens as $i => $token) {
			$token    = trim((string) $token);
			$idMember = (int) ($members[$i] ?? 0);

			// A blank row (e.g. an "add" the admin left empty) is dropped.
			if ($token === '' && $idMember === 0) {
				continue;
			}

			// Preserve the submitted row so a reload keeps what the admin typed.
			$rows[] = ['token' => $token, 'member' => $idMember];

			if ($token === '' || $idMember <= 0) {
				$invalid = true;
				continue;
			}

			$map[$token] = $idMember;
		}

		updateSettings([
			'api_enabled' => empty($_POST['api_enabled']) ? 0 : 1,
			'api_keys'    => json_encode($map),
		]);

		// Some rows were malformed: stay on the page, flag them and keep the input.
		if ($invalid) {
			$context['error_message'] = $txt['api_keys_invalid'];
			$context['api_keys']      = $rows;

			return false;
		}

		return true;
	}
}
