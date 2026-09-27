<?php

declare(strict_types=1);

use SMF\API\Request;
use SMF\API\Response;

if (! defined('SMF')) {
    define('SMF', true);
}

// --- SMF core function stubs -------------------------------------------------
// The mod calls a handful of SMF globals and helpers. Each stub records what it
// received (so tests can assert on it) or returns a value tests can configure
// through the $GLOBALS['api_*'] slots that Environment::reset() initializes.

if (! function_exists('add_integration_function')) {
    function add_integration_function(string $hook, string $callback, bool $permanent = false, string $file = ''): void
    {
        $GLOBALS['api_hooks'][] = [$hook, $callback, $permanent];
    }
}

if (! function_exists('call_integration_hook')) {
    function call_integration_hook(string $hook, array $data = [])
    {
        $GLOBALS['api_hook_calls'][] = $hook;

        // Let a test register API routes the way a third-party mod would, by
        // handing it the Router the hook receives in $data[0].
        if ($hook === 'integrate_api_routes' && isset($GLOBALS['api_routes_hook'])) {
            ($GLOBALS['api_routes_hook'])($data[0]);
        }

        // The authentication hook resolves a member id into $data[1], which is a
        // reference to the caller's $idMember. Assigning through it lets tests
        // exercise the "a mod authenticated the request" branch.
        if ($hook === 'integrate_api_authenticate' && isset($GLOBALS['api_auth_hook_id'])) {
            $data[1] = $GLOBALS['api_auth_hook_id'];
        }

        return null;
    }
}

if (! function_exists('loadLanguage')) {
    function loadLanguage(string $language): void
    {
        $GLOBALS['api_languages_loaded'][] = $language;
    }
}

if (! function_exists('loadTemplate')) {
    function loadTemplate(string $template): void
    {
        $GLOBALS['api_templates_loaded'][] = $template;
    }
}

if (! function_exists('loadCSSFile')) {
    function loadCSSFile(string $fileName, array $params = [], string $id = ''): void
    {
    }
}

if (! function_exists('checkSession')) {
    function checkSession(string $type = 'post'): void
    {
        $GLOBALS['api_check_session'] = true;
    }
}

if (! function_exists('updateSettings')) {
    function updateSettings(array $changeArray): void
    {
        $GLOBALS['api_updated_settings'] = $changeArray;
    }
}

if (! function_exists('redirectexit')) {
    function redirectexit(string $setLocation = ''): never
    {
        throw new \RuntimeException('redirect:' . $setLocation);
    }
}

if (! function_exists('fatal_error')) {
    function fatal_error(string $error, $log = true): never
    {
        throw new \RuntimeException('fatal:' . $error);
    }
}

if (! function_exists('allowedTo')) {
    function allowedTo($permission, $boards = null): bool
    {
        $permissions = $GLOBALS['api_permissions'] ?? [];

        if ($permissions === true || in_array('*', (array) $permissions, true)) {
            return true;
        }

        return in_array($permission, (array) $permissions, true);
    }
}

if (! function_exists('build_query_board')) {
    function build_query_board(int $id): array
    {
        $GLOBALS['api_build_query_board'] = $id;

        return ['query_see_board' => '1=1'];
    }
}

if (! function_exists('loadPermissions')) {
    function loadPermissions(): void
    {
        $GLOBALS['api_load_permissions'] = true;
    }
}

if (! function_exists('parse_bbc')) {
    function parse_bbc($message, $smileys = true, $cache_id = '')
    {
        return 'parsed:' . $message;
    }
}

if (! function_exists('preparsecode')) {
    function preparsecode(&$message, $previewing = false): void
    {
        $GLOBALS['api_preparsecode'][] = $message;
    }
}

if (! function_exists('createCategory')) {
    function createCategory(array $catOptions): int
    {
        $GLOBALS['api_create_category'][] = $catOptions;

        return $GLOBALS['api_new_id'] ?? 10;
    }
}

if (! function_exists('modifyCategory')) {
    function modifyCategory(int $categoryID, array $catOptions): void
    {
        $GLOBALS['api_modify_category'][] = [$categoryID, $catOptions];
    }
}

if (! function_exists('deleteCategories')) {
    function deleteCategories(array $categories, $moveBoardsTo = null): void
    {
        $GLOBALS['api_delete_categories'][] = [$categories, $moveBoardsTo];
    }
}

if (! function_exists('createBoard')) {
    function createBoard(array $boardOptions): int
    {
        $GLOBALS['api_create_board'][] = $boardOptions;

        return $GLOBALS['api_new_id'] ?? 10;
    }
}

if (! function_exists('modifyBoard')) {
    function modifyBoard(int $board_id, array $boardOptions): void
    {
        $GLOBALS['api_modify_board'][] = [$board_id, $boardOptions];
    }
}

if (! function_exists('deleteBoards')) {
    function deleteBoards(array $boards_to_remove, $moveChildrenTo = null): void
    {
        $GLOBALS['api_delete_boards'][] = [$boards_to_remove, $moveChildrenTo];
    }
}

if (! function_exists('registerMember')) {
    function registerMember(array $regOptions, bool $return_errors = false)
    {
        $GLOBALS['api_register'][] = $regOptions;

        return $GLOBALS['api_register_result'] ?? 0;
    }
}

if (! function_exists('updateMemberData')) {
    function updateMemberData($members, array $data): void
    {
        $GLOBALS['api_update_member'][] = [$members, $data];
    }
}

if (! function_exists('deleteMembers')) {
    function deleteMembers($users, bool $check_not_admin = false): void
    {
        $GLOBALS['api_delete_members'][] = [$users, $check_not_admin];
    }
}

if (! function_exists('createPost')) {
    function createPost(array &$msgOptions, array &$topicOptions, array &$posterOptions): bool
    {
        $GLOBALS['api_create_post'][] = [$msgOptions, $topicOptions, $posterOptions];

        $msgOptions['id'] = $GLOBALS['api_new_msg_id'] ?? 0;

        // A new topic arrives with id 0 and receives the fresh topic id; a reply
        // keeps the existing topic id it was given.
        if ((int) ($topicOptions['id'] ?? 0) === 0) {
            $topicOptions['id'] = $GLOBALS['api_new_topic_id'] ?? 0;
        }

        return $GLOBALS['api_create_post_result'] ?? true;
    }
}

if (! function_exists('modifyPost')) {
    function modifyPost(array &$msgOptions, array &$topicOptions, array &$posterOptions): void
    {
        $GLOBALS['api_modify_post'][] = [$msgOptions, $topicOptions, $posterOptions];
    }
}

if (! function_exists('removeTopics')) {
    function removeTopics($topics, bool $decreasePostCount = true, bool $ignoreRecycling = false): void
    {
        $GLOBALS['api_remove_topics'][] = $topics;
    }
}

if (! function_exists('removeMessage')) {
    function removeMessage(int $message, bool $decreasePostCount = true)
    {
        $GLOBALS['api_remove_message'][] = $message;

        return null;
    }
}

// The SMF\API classes are resolved through composer's PSR-4 autoloader
// (SMF\API\ => src/Sources/API). SMF is defined above, so the "no direct
// access" guard at the top of each source file passes when it is autoloaded.

final class Environment
{
    /**
     * Reset every SMF global the mod touches to a clean, predictable state.
     * Call it at the top of each test so nothing leaks between cases.
     */
    public static function reset(): void
    {
        $GLOBALS['modSettings'] = [];
        $GLOBALS['user_info']   = ['id' => 0, 'is_admin' => false, 'name' => 'Tester', 'email' => 'tester@example.test'];
        $GLOBALS['context']     = [];
        $GLOBALS['scripturl']   = 'https://example.test/index.php';
        $GLOBALS['txt']         = [
            'api_title'            => 'RESTful API',
            'api_enabled_subtext'  => 'Enable the API',
            'api_keys_invalid'     => 'Invalid API keys',
        ];

        // Permissions granted to the current member (a list of slugs, or true /
        // '*' for "allow everything").
        $GLOBALS['api_permissions'] = [];

        // Recorders populated by the stubs above.
        $GLOBALS['api_hooks']             = [];
        $GLOBALS['api_hook_calls']        = [];
        $GLOBALS['api_languages_loaded']  = [];
        $GLOBALS['api_templates_loaded']  = [];
        $GLOBALS['api_queries']           = [];
        $GLOBALS['api_create_category']   = [];
        $GLOBALS['api_modify_category']   = [];
        $GLOBALS['api_delete_categories'] = [];
        $GLOBALS['api_create_board']      = [];
        $GLOBALS['api_modify_board']      = [];
        $GLOBALS['api_delete_boards']     = [];
        $GLOBALS['api_register']          = [];
        $GLOBALS['api_update_member']     = [];
        $GLOBALS['api_delete_members']    = [];
        $GLOBALS['api_create_post']       = [];
        $GLOBALS['api_modify_post']       = [];
        $GLOBALS['api_preparsecode']      = [];
        $GLOBALS['api_remove_topics']     = [];
        $GLOBALS['api_remove_message']    = [];

        // Database fakes: rows feed db_fetch_assoc (FIFO), api_total feeds the
        // COUNT read, and api_num_rows(_queue) drives EXISTS checks.
        $GLOBALS['api_rows']           = [];
        $GLOBALS['api_total']          = 0;
        $GLOBALS['api_num_rows']       = 0;
        $GLOBALS['api_num_rows_queue'] = [];
        $GLOBALS['api_free_result']    = 0;

        // Return values for the SMF mutation helpers.
        $GLOBALS['api_new_id']             = 10;
        $GLOBALS['api_new_msg_id']         = 500;
        $GLOBALS['api_new_topic_id']       = 300;
        $GLOBALS['api_create_post_result'] = true;
        $GLOBALS['api_register_result']    = 0;

        unset($GLOBALS['api_auth_hook_id'], $GLOBALS['api_check_session'], $GLOBALS['api_updated_settings'], $GLOBALS['api_routes_hook']);

        $GLOBALS['smcFunc'] = [
            'db_query' => static function (string $identifier, string $query, array $params = []): object {
                $GLOBALS['api_queries'][] = ['query' => $query, 'params' => $params];

                return new \stdClass();
            },
            'db_fetch_assoc' => static function ($request = null): ?array {
                return array_shift($GLOBALS['api_rows']) ?: null;
            },
            'db_fetch_row' => static function ($request = null): array {
                return [$GLOBALS['api_total'] ?? 0];
            },
            'db_num_rows' => static function ($request = null): int {
                if (! empty($GLOBALS['api_num_rows_queue'])) {
                    return (int) array_shift($GLOBALS['api_num_rows_queue']);
                }

                return (int) ($GLOBALS['api_num_rows'] ?? 0);
            },
            'db_free_result' => static function ($request = null): void {
                $GLOBALS['api_free_result'] = ($GLOBALS['api_free_result'] ?? 0) + 1;
            },
            'htmlspecialchars' => static fn ($string, $flags = ENT_QUOTES): string
                => htmlspecialchars((string) $string, $flags),
        ];

        self::prepareSourcedir();

        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';

        // Drop any request headers a previous test injected.
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'REDIRECT_HTTP_')) {
                unset($_SERVER[$key]);
            }
        }

        unset($_SERVER['PATH_INFO']);
    }

    /**
     * Build a Request as the front controller would, from an injected method,
     * body and query string. Without a JSON content-type the body resolves to
     * the form payload ($_POST), which keeps php://input out of the picture.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public static function request(
        string $method = 'GET',
        array $body = [],
        array $query = [],
        array $headers = []
    ): Request {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET  = $query;
        $_POST = $method === 'GET' ? [] : $body;

        // Start from a clean header set so repeated calls within one test do not
        // inherit each other's headers.
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'REDIRECT_HTTP_')) {
                unset($_SERVER[$key]);
            }
        }

        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(strtr($name, '-', '_'))] = $value;
        }

        return new Request();
    }

    /**
     * Read a Response's private state without emitting it (send() calls exit).
     *
     * @return array{status: int, payload: mixed, headers: array<string, string>}
     */
    public static function readResponse(Response $response): array
    {
        $reflection = new \ReflectionObject($response);

        $read = static function (string $name) use ($reflection, $response) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($response);
        };

        return [
            'status'  => $read('status'),
            'payload' => $read('payload'),
            'headers' => $read('headers'),
        ];
    }

    /**
     * A throwaway $sourcedir holding empty stand-ins for the SMF library files
     * the write controllers require_once before calling their helpers.
     */
    private static function prepareSourcedir(): void
    {
        $dir = sys_get_temp_dir() . '/smf-api-tests-src';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach (['Subs-Categories.php', 'Subs-Boards.php', 'Subs-Members.php', 'Subs-Post.php', 'RemoveTopic.php'] as $file) {
            $path = $dir . '/' . $file;

            if (! is_file($path)) {
                file_put_contents($path, "<?php\n");
            }
        }

        $GLOBALS['sourcedir'] = $dir;
    }
}
