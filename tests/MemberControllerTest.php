<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\MemberController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(MemberController::class)]
final class MemberControllerTest
{
    // Fixtures deliberately use string values so the controller's (int) casts
    // are observable: a dropped cast would leave the raw string in the payload.
    private const MEMBER_ROW = [
        'id_member'       => '5',
        'member_name'     => 'alice',
        'real_name'       => 'Alice',
        'email_address'   => 'alice@example.test',
        'posts'           => '10',
        'date_registered' => '1000',
        'id_group'        => '2',
        'id_post_group'   => '4',
        'last_login'      => '2000',
        'is_activated'    => '1',
    ];

    // The public (non-admin) shape of MEMBER_ROW after transform(), fully typed.
    private const PUBLIC_MEMBER = [
        'id'              => 5,
        'username'        => 'alice',
        'name'            => 'Alice',
        'posts'           => 10,
        'date_registered' => 1000,
        'last_login'      => 2000,
        'primary_group'   => 2,
        'post_group'      => 4,
    ];

    // The administrator shape adds the email address at the end.
    private const ADMIN_MEMBER = self::PUBLIC_MEMBER + ['email' => 'alice@example.test'];

    public function indexReturnsTransformedMembers(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['view_mlist'];
        $GLOBALS['api_total']       = 1;
        $GLOBALS['api_rows']        = [self::MEMBER_ROW];

        $response = (new MemberController(Environment::request()))->index([]);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data[0], self::PUBLIC_MEMBER);
        // The active filter is bound to the count/select queries as {int:active}=1.
        Assert::same($GLOBALS['api_queries'][0]['params'], ['active' => 1]);
    }

    public function indexIsRefusedWithoutViewMlist(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new MemberController(Environment::request()))->index([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function showHidesTheEmailFromNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [self::MEMBER_ROW];

        $response = (new MemberController(Environment::request()))->show(['id' => '5']);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data, self::PUBLIC_MEMBER);
        // The row is selected by the {int:id} bound parameter.
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 5]);
    }

    public function showExposesTheEmailToAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_rows'] = [self::MEMBER_ROW];

        $response = (new MemberController(Environment::request()))->show(['id' => '5']);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data, self::ADMIN_MEMBER);
    }

    public function showRejectsAninactiveOrMissingMember(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['is_activated' => '0'] + self::MEMBER_ROW];

        $exception = $this->error(fn () => (new MemberController(Environment::request()))->show(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeRegistersAmemberAndAnswersTwoZeroOne(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['moderate_forum'];
        $GLOBALS['api_register_result'] = 20;

        // Surrounding whitespace must be trimmed off name/email; a non-string
        // password must be cast to string before it reaches registerMember.
        $request  = Environment::request('POST', ['username' => ' bob ', 'email' => ' bob@example.test ', 'password' => 123]);
        $response = (new MemberController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data'], ['id' => 20, 'username' => 'bob', 'email' => 'bob@example.test']);
        Assert::same($GLOBALS['api_register'][0], [
            'interface'           => 'admin',
            'username'            => 'bob',
            'email'               => 'bob@example.test',
            'password'            => '123',
            'password_check'      => '123',
            'check_reserved_name' => true,
            'require'             => 'nothing',
            'send_welcome_email'  => false,
            'memberGroup'         => 0,
        ]);
    }

    public function storeSurfacesRegistrationErrors(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['moderate_forum'];
        // Keyed (non-sequential) errors so array_values() is not a no-op.
        $GLOBALS['api_register_result'] = ['username' => 'error_username_taken', 'email' => 'error_bad_email'];

        $request   = Environment::request('POST', ['username' => 'bob', 'email' => 'bob@example.test']);
        $exception = $this->error(fn () => (new MemberController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getDetails(), ['errors' => ['error_username_taken', 'error_bad_email']]);
    }

    public function storeValidatesRequiredFields(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['moderate_forum'];

        $request   = Environment::request('POST', ['username' => 'bob']);
        $exception = $this->error(fn () => (new MemberController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeIsRefusedWithoutModerateForum(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['username' => 'bob', 'email' => 'bob@example.test']);
        $exception = $this->error(fn () => (new MemberController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateChangesTheDisplayName(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [
            ['id_member' => '5', 'is_activated' => '1'],
            ['real_name' => 'Alicia'] + self::MEMBER_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Alicia']);
        $response = (new MemberController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Alicia');
        Assert::same($GLOBALS['api_update_member'][0], [5, ['real_name' => 'Alicia']]);
    }

    public function updateRejectsAnEmailAlreadyInUse(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'is_activated' => '1']];
        $GLOBALS['api_num_rows'] = 1;

        $request   = Environment::request('PATCH', ['email' => 'taken@example.test']);
        $exception = $this->error(fn () => (new MemberController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function updateTrimsAndValidatesTheEmail(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [
            ['id_member' => '5', 'is_activated' => '1'],
            self::MEMBER_ROW,
        ];

        // Leading/trailing spaces must be trimmed before validation and storage;
        // without the trim the address would fail FILTER_VALIDATE_EMAIL.
        $request  = Environment::request('PATCH', ['email' => '  clean@example.test  ']);
        $response = (new MemberController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_update_member'][0], [5, ['email_address' => 'clean@example.test']]);
    }

    public function updateRejectsAnInactiveMember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'is_activated' => '0']];

        $request   = Environment::request('PATCH', ['name' => 'Alicia']);
        $exception = $this->error(fn () => (new MemberController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function updateExposesTheEmailToAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [
            ['id_member' => '5', 'is_activated' => '1'],
            self::MEMBER_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Alice']);
        $response = (new MemberController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data'], self::ADMIN_MEMBER);
    }

    public function updateAllowsSelfEditWithTheOwnPermission(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['id'] = '5';
        $GLOBALS['api_permissions'] = ['profile_identity_own'];
        $GLOBALS['api_rows'] = [
            ['id_member' => '5', 'is_activated' => '1'],
            self::MEMBER_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Alice']);
        $response = (new MemberController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_update_member'][0], [5, ['real_name' => 'Alice']]);
    }

    public function updateIsRefusedForAnotherMemberWithoutTheAnyPermission(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id_member' => '5', 'is_activated' => '1']];

        $request   = Environment::request('PATCH', ['name' => 'Alicia']);
        $exception = $this->error(fn () => (new MemberController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function replaceRequiresEveryField(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'is_activated' => '1']];

        // A full replace (PUT) that omits "email" must be rejected.
        $request   = Environment::request('PUT', ['name' => 'X']);
        $exception = $this->error(fn () => (new MemberController($request))->replace(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function replaceRejectsAmissingMember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_identity_any'];

        $request   = Environment::request('PUT', ['name' => 'X', 'email' => 'x@example.test']);
        $exception = $this->error(fn () => (new MemberController($request))->replace(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function destroyRemovesAmember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_remove_any'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'id_group' => '2', 'additional_groups' => '']];

        $response = (new MemberController(Environment::request('DELETE')))->destroy(['id' => '5']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_members'][0], [[5], true]);
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 5]);
    }

    public function destroyAllowsSelfDeletionWithTheOwnPermission(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['id'] = '5';
        $GLOBALS['api_permissions'] = ['profile_remove_own'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'id_group' => '2', 'additional_groups' => '']];

        $response = (new MemberController(Environment::request('DELETE')))->destroy(['id' => '5']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_members'][0], [[5], true]);
    }

    public function destroyProtectsAdministratorsFromNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_remove_any'];
        $GLOBALS['api_rows'] = [['id_member' => '5', 'id_group' => '1', 'additional_groups' => '']];

        $exception = $this->error(fn () => (new MemberController(Environment::request('DELETE')))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyProtectsAdministratorsFlaggedByAnAdditionalGroup(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['profile_remove_any'];
        // Primary group is not the admin group, but group 1 sits among the
        // additional groups, which must still count as an administrator.
        $GLOBALS['api_rows'] = [['id_member' => '5', 'id_group' => '2', 'additional_groups' => '3,1']];

        $exception = $this->error(fn () => (new MemberController(Environment::request('DELETE')))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id_member' => '5', 'id_group' => '2', 'additional_groups' => '']];

        $exception = $this->error(fn () => (new MemberController(Environment::request('DELETE')))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    private function error(callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected the request to be rejected');
    }
}
