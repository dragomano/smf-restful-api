<?php

// Admin settings page.
$txt['api_title'] = 'RESTful API';
$txt['api_desc'] = 'Expose forum data over a JSON HTTP API at index.php?action=api.';
$txt['api_enabled'] = 'Enable the API';
$txt['api_enabled_subtext'] = 'When off, every endpoint answers 503.';

// API keys manager.
$txt['api_keys_title'] = 'API keys';
$txt['api_keys_info'] = 'Each key is a secret token bound to a forum member. A request authenticates with its token (Authorization: Bearer header, X-API-Key header, or api_key query parameter) and then acts with that member&rsquo;s permissions.';
$txt['api_keys_token'] = 'Token';
$txt['api_keys_member'] = 'Member ID';
$txt['api_keys_actions'] = 'Actions';
$txt['api_keys_generate'] = 'Generate';
$txt['api_keys_remove'] = 'Remove';
$txt['api_keys_add'] = 'Add key';
$txt['api_keys_none'] = 'No keys yet — add one below.';
$txt['api_keys_invalid'] = 'Every API key needs a non-empty token and a positive member id.';
