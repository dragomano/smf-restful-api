<?php

// Admin settings page.
$txt['api_title'] = 'RESTful API';
$txt['api_desc'] = 'Предоставляет доступ к данным форума через JSON HTTP API по адресу index.php?action=api.';
$txt['api_enabled'] = 'Включить API';
$txt['api_enabled_subtext'] = 'Когда выключено, любой запрос возвращает ошибку 503.';

// API keys manager.
$txt['api_keys_title'] = 'Ключи API';
$txt['api_keys_info'] = 'Каждый ключ — это секретный токен, привязанный к участнику форума. Запрос авторизуется своим токеном (заголовок Authorization: Bearer, заголовок X-API-Key или параметр запроса api_key) и выполняется с правами этого участника.';
$txt['api_keys_token'] = 'Токен';
$txt['api_keys_member'] = 'ID участника';
$txt['api_keys_actions'] = 'Действия';
$txt['api_keys_generate'] = 'Сгенерировать';
$txt['api_keys_remove'] = 'Удалить';
$txt['api_keys_add'] = 'Добавить ключ';
$txt['api_keys_none'] = 'Пока нет ни одного ключа — добавьте его ниже.';
$txt['api_keys_invalid'] = 'Для каждого ключа нужны непустой токен и положительный ID участника.';
