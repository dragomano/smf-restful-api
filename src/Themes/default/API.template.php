<?php declare(strict_types=1);

/**
 * API.template.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

if (! defined('SMF'))
	die('No direct access...');

/**
 * The API settings page: the "enabled" switch plus a table-based editor for
 * the token => member map, with client-side add / remove / generate helpers.
 */
function template_api_settings(): void
{
	global $context, $txt, $modSettings;

	echo '
	<form action="', $context['post_url'], '" method="post" accept-charset="', $context['character_set'], '" id="api_settings">';

	if (! empty($context['error_message'])) {
		echo '
		<div class="errorbox">', $context['error_message'], '</div>';
	}

	echo '
		<div class="cat_bar">
			<h3 class="catbg">', $txt['api_title'], '</h3>
		</div>
		<div class="information">', $txt['api_keys_info'], '</div>
		<div class="windowbg noup">
			<dl class="settings">
				<dt>
					<strong><label for="api_enabled">', $txt['api_enabled'], '</label></strong>
					<div class="smalltext">', $txt['api_enabled_subtext'], '</div>
				</dt>
				<dd>
					<input type="checkbox" name="api_enabled" id="api_enabled" value="1"', empty($modSettings['api_enabled']) ? '' : ' checked', '>
				</dd>
			</dl>
		</div>
		<br>
		<div class="cat_bar">
			<h3 class="catbg">', $txt['api_keys_title'], '</h3>
		</div>
		<table class="table_grid" id="api_keys_table">
			<thead>
				<tr class="title_bar">
					<th class="lefttext">', $txt['api_keys_token'], '</th>
					<th class="lefttext" style="width: 16ex; white-space: nowrap">', $txt['api_keys_member'], '</th>
					<th class="centertext" style="width: 30ex; white-space: nowrap">', $txt['api_keys_actions'], '</th>
				</tr>
			</thead>
			<tbody id="api_keys_body">';

	foreach ($context['api_keys'] as $key) {
		echo '
				<tr class="windowbg">
					<td class="api_cell_token"><input type="text" class="api_token" name="api_key_token[]" value="', $key['token'], '" size="40" required></td>
					<td class="api_cell_member"><input type="number" min="1" name="api_key_member[]" value="', $key['member'], '" required></td>
					<td class="centertext api_cell_actions" style="white-space: nowrap">
						<button type="button" class="button api_gen">', $txt['api_keys_generate'], '</button>
						<button type="button" class="button api_del">', $txt['api_keys_remove'], '</button>
					</td>
				</tr>';
	}

	echo '
				<tr class="windowbg" id="api_keys_empty"', empty($context['api_keys']) ? '' : ' style="display: none"', '>
					<td colspan="3" class="centertext">', $txt['api_keys_none'], '</td>
				</tr>
			</tbody>
		</table>
		<div class="windowbg noup">
			<a href="javascript:void(0);" class="button" id="api_add_key">', $txt['api_keys_add'], '</a>
		</div>
		<div class="righttext padding">
			<input type="submit" class="button" value="', $txt['save'], '">
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '">
	</form>';

	echo /** @lang text */ '
	<script>
	(function () {
		function apiToken() {
			const b = new Uint8Array(16);
			(window.crypto || window.msCrypto).getRandomValues(b);
			return Array.prototype.map.call(b, function (x) {
				return ("0" + x.toString(16)).slice(-2);
			}).join("");
		}

		const body = document.getElementById("api_keys_body");
		const empty = document.getElementById("api_keys_empty");

		function refreshEmpty() {
			const rows = body.querySelectorAll("tr.windowbg:not(#api_keys_empty)");
			empty.style.display = rows.length ? "none" : "";
		}

		document.getElementById("api_add_key").addEventListener("click", function () {
			const tr = document.createElement("tr");
			tr.className = "windowbg";
			tr.innerHTML = \'<td class="api_cell_token"><input type="text" class="api_token" name="api_key_token[]" size="40" required></td>\'
				+ \'<td class="api_cell_member"><input type="number" min="1" name="api_key_member[]" required></td>\'
				+ \'<td class="centertext api_cell_actions" style="white-space: nowrap"><button type="button" class="button api_gen">', $txt['api_keys_generate'], '</button> \'
				+ \'<button type="button" class="button api_del">', $txt['api_keys_remove'], '</button></td>\';
			body.insertBefore(tr, empty);
			tr.querySelector(".api_token").value = apiToken();
			refreshEmpty();
		});

		body.addEventListener("click", function (e) {
			if (e.target.classList.contains("api_del")) {
				e.target.closest("tr").remove();
				refreshEmpty();
			} else if (e.target.classList.contains("api_gen")) {
				e.target.closest("tr").querySelector(".api_token").value = apiToken();
			}
		});
	})();
	</script>';
}
