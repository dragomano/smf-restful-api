<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Authenticator;
use SMF\API\Request;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Authenticator::class)]
final class AuthenticatorTest
{
    /** A fully activated member row as loadMember() would read it (DB returns strings). */
    private const MEMBER = [
        'id_member'         => '5',
        'member_name'       => 'alice',
        'real_name'         => 'Alice',
        'email_address'     => 'alice@example.test',
        'id_group'          => '2',
        'id_post_group'     => '4',
        'additional_groups' => '',
        'is_activated'      => '1',
    ];

    public function rejectsARequestWithoutAkey(): void
    {
        Environment::reset();

        $exception = $this->authenticateFailure(Environment::request());

        Assert::same($exception->getStatusCode(), 401);
        Assert::same($exception->getMessage(), 'Missing API key');
    }

    public function rejectsAnUnknownKey(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['good-token' => 5]);

        $exception = $this->authenticateFailure(
            Environment::request('GET', [], ['api_key' => 'wrong-token'])
        );

        Assert::same($exception->getStatusCode(), 401);
        Assert::same($exception->getMessage(), 'Invalid API key');
    }

    public function acceptsAvalidKeyAndRebuildsTheUserContext(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        $GLOBALS['api_rows'] = [self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request('GET', [], ['api_key' => 'secret-token']));

        Assert::same($auth->id(), 5);
        Assert::same($auth->member()['member_name'], 'alice');
        Assert::false($auth->isAdmin());

        Assert::same($GLOBALS['user_info']['id'], 5);
        Assert::same($GLOBALS['user_info']['username'], 'alice');
        Assert::same($GLOBALS['user_info']['name'], 'Alice');
        Assert::same($GLOBALS['user_info']['email'], 'alice@example.test');
        Assert::same($GLOBALS['user_info']['groups'], [2, 4]);
        Assert::false($GLOBALS['user_info']['is_guest']);
        Assert::false($GLOBALS['user_info']['possibly_robot']);
        Assert::false($GLOBALS['user_info']['can_manage_boards']);
        Assert::same($GLOBALS['api_build_query_board'], 5);
        Assert::true($GLOBALS['api_load_permissions']);
    }

    public function buildsTheGroupListFromPrimaryPostAndAdditionalGroups(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        // Duplicate primary group, an empty entry, and the admin group (1).
        $GLOBALS['api_rows'] = [['additional_groups' => '2,,3,1'] + self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request('GET', [], ['api_key' => 'secret-token']));

        // [2,4] + [2,3,1] → filtered, int-cast, de-duplicated, re-indexed.
        Assert::same($GLOBALS['user_info']['groups'], [2, 4, 3, 1]);
        Assert::true($auth->isAdmin());
    }

    public function grantsBoardManagementToConfiguredGroups(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        $GLOBALS['modSettings']['board_manager_groups'] = '2,7';
        $GLOBALS['api_rows'] = [self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request('GET', [], ['api_key' => 'secret-token']));

        // Not an admin, but group 2 is a configured board manager group.
        Assert::false($GLOBALS['user_info']['is_admin']);
        Assert::true($GLOBALS['user_info']['can_manage_boards']);
    }

    public function deniesBoardManagementToUnlistedGroups(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        $GLOBALS['modSettings']['board_manager_groups'] = '7,8';
        $GLOBALS['api_rows'] = [self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request('GET', [], ['api_key' => 'secret-token']));

        Assert::false($GLOBALS['user_info']['can_manage_boards']);
    }

    public function marksMembersOfTheAdminGroupAsAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        $GLOBALS['api_rows'] = [['id_group' => 1] + self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request('GET', [], ['api_key' => 'secret-token']));

        Assert::true($auth->isAdmin());
        Assert::true($GLOBALS['user_info']['is_admin']);
        Assert::true($GLOBALS['user_info']['can_manage_boards']);
    }

    public function letsAmodResolveTheMemberThroughTheHook(): void
    {
        Environment::reset();
        $GLOBALS['api_auth_hook_id'] = 5;
        $GLOBALS['api_rows'] = [self::MEMBER];

        $auth = new Authenticator();
        $auth->authenticate(Environment::request());

        Assert::same($auth->id(), 5);
    }

    public function rejectsAkeyWhoseMemberIsGoneOrInactive(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['secret-token' => 5]);
        $GLOBALS['api_rows'] = [['is_activated' => 0] + self::MEMBER];

        $exception = $this->authenticateFailure(
            Environment::request('GET', [], ['api_key' => 'secret-token'])
        );

        Assert::same($exception->getStatusCode(), 401);
        Assert::same($exception->getMessage(), 'The member tied to this key no longer exists');
    }

    public function requireGuardsAgainstMissingPermissions(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['moderate_forum'];

        $auth = new Authenticator();
        $auth->require('moderate_forum');

        $exception = null;

        try {
            $auth->require('admin_forum');
        } catch (ApiException $thrown) {
            $exception = $thrown;
        }

        Assert::instanceOf($exception, ApiException::class);
        Assert::same($exception->getStatusCode(), 403);
        Assert::same($exception->getMessage(), 'You lack the "admin_forum" permission');
    }

    public function extractsTheKeyInOrderOfPreference(): void
    {
        Environment::reset();

        Assert::same(
            $this->extractKey(Environment::request('GET', [], [], ['Authorization' => 'Bearer header-token'])),
            'header-token'
        );
        // The token is trimmed after the "Bearer " prefix is stripped.
        Assert::same(
            $this->extractKey(Environment::request('GET', [], [], ['Authorization' => 'Bearer   spaced-token  '])),
            'spaced-token'
        );
        Assert::same(
            $this->extractKey(Environment::request('GET', [], [], ['X-Api-Key' => '  api-key-header  '])),
            'api-key-header'
        );
        Assert::same(
            $this->extractKey(Environment::request('GET', [], ['api_key' => '  query-token  '])),
            'query-token'
        );
        Assert::same($this->extractKey(Environment::request()), '');
    }

    public function resolvesKeysAgainstTheStoredMap(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['api_keys'] = json_encode(['abc' => 7]);

        Assert::same($this->resolveKey('abc'), 7);
        Assert::null($this->resolveKey('nope'));

        // A string member id in the map is cast to int.
        $GLOBALS['modSettings']['api_keys'] = '{"str":"9"}';
        Assert::same($this->resolveKey('str'), 9);

        $GLOBALS['modSettings']['api_keys'] = 'not-json';
        Assert::null($this->resolveKey('abc'));
    }

    private function authenticateFailure(Request $request): ApiException
    {
        try {
            (new Authenticator())->authenticate($request);
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected authentication to fail');
    }

    private function extractKey(Request $request): string
    {
        $reflection = new ReflectionMethod(Authenticator::class, 'extractKey');
        $reflection->setAccessible(true);

        return $reflection->invoke(new Authenticator(), $request);
    }

    private function resolveKey(string $key): ?int
    {
        $reflection = new ReflectionMethod(Authenticator::class, 'resolveKey');
        $reflection->setAccessible(true);

        return $reflection->invoke(new Authenticator(), $key);
    }
}
