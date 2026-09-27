<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\TopicController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(TopicController::class)]
final class TopicControllerTest
{
    // Fixture rows carry STRING column values on purpose: the controller casts
    // every id/count to (int) and every flag to (bool), so string inputs make
    // those casts observable (a dropped cast leaves a string/other type).
    private const STRING_ROW = [
        'id_topic'     => '7',
        'id_board'     => '2',
        'id_first_msg' => '100',
        'id_last_msg'  => '140',
        'num_replies'  => '3',
        'num_views'    => '50',
        'locked'       => '1',
        'is_sticky'    => '0',
        'approved'     => '1',
        'subject'      => 'Topic',
        'poster_time'  => '1234',
        'starter_id'   => '9',
        'starter_name' => 'Starter',
    ];

    // The fully transformed shape of STRING_ROW (everything coerced to int/bool).
    private const TRANSFORMED = [
        'id'           => 7,
        'board'        => 2,
        'subject'      => 'Topic',
        'starter'      => ['id' => 9, 'name' => 'Starter'],
        'poster_time'  => 1234,
        'num_replies'  => 3,
        'num_views'    => 50,
        'locked'       => true,
        'is_sticky'    => false,
        'approved'     => true,
        'id_first_msg' => 100,
        'id_last_msg'  => 140,
    ];

    public function indexReturnsTransformedTopics(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::STRING_ROW];

        $response = (new TopicController(Environment::request()))->index([]);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data[0], self::TRANSFORMED);
    }

    public function indexScopesVisibilityAndApprovalForNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::STRING_ROW];

        (new TopicController(Environment::request()))->index([]);

        // The COUNT query is executed first; it must carry the board-visibility
        // token and only approved topics for a non-admin caller.
        $count = $GLOBALS['api_queries'][0];

        Assert::true(str_contains($count['query'], '{query_see_board}'));
        Assert::true(str_contains($count['query'], 't.approved = {int:approved}'));
        Assert::same($count['params'], ['approved' => 1]);
    }

    public function indexScopedToAninvisibleBoardIsNotFound(): void
    {
        Environment::reset();
        $GLOBALS['api_num_rows'] = 0;

        $exception = $this->error(fn () => (new TopicController(Environment::request()))->index(['board_id' => '2']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showReturnsAtopicWithAparsedBody(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['body' => '[b]hi[/b]'] + self::STRING_ROW];

        $response = (new TopicController(Environment::request()))->show(['id' => '7']);
        $data     = Environment::readResponse($response)['payload']['data'];

        // The full payload is the transformed row plus the parsed/raw body.
        Assert::same($data, self::TRANSFORMED + [
            'body'     => 'parsed:[b]hi[/b]',
            'body_raw' => '[b]hi[/b]',
        ]);

        // The single-row lookup is scoped to the requested id.
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 7]);
    }

    public function showHidesAnUnapprovedTopicFromNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['body' => 'x', 'approved' => '0'] + self::STRING_ROW];

        $exception = $this->error(fn () => (new TopicController(Environment::request()))->show(['id' => '7']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeCreatesAtopicOnBehalfOfTheMember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];
        $GLOBALS['api_num_rows'] = 1;
        $GLOBALS['api_new_topic_id'] = 300;
        $GLOBALS['api_new_msg_id'] = '500';
        $GLOBALS['user_info']['id'] = '5';

        // board is a string and body is padded so the (int) cast and trim() stay
        // observable in the recorded options and the response.
        $request  = Environment::request('POST', ['board' => '2', 'subject' => 'Hi', 'body' => '  Hello world  ']);
        $response = (new TopicController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data'], ['id' => 300, 'board' => 2, 'subject' => 'Hi', 'id_first_msg' => 500]);

        Assert::count($GLOBALS['api_create_post'], 1);

        Assert::same($GLOBALS['api_create_post'][0][0], [
            'id'              => 0,
            'subject'         => 'Hi',
            'body'            => 'Hello world',
            'icon'            => 'xx',
            'smileys_enabled' => true,
            'attachments'     => [],
            'approved'        => true,
        ]);
        Assert::same($GLOBALS['api_create_post'][0][1], [
            'id'           => 0,
            'board'        => 2,
            'mark_as_read' => true,
            'is_approved'  => true,
        ]);
        Assert::same($GLOBALS['api_create_post'][0][2], [
            'id'                => 5,
            'name'              => 'Tester',
            'email'             => 'tester@example.test',
            'update_post_count' => true,
            'ip'                => '127.0.0.1',
        ]);

        // The body is run through SMF's pre-parser before storage.
        Assert::same($GLOBALS['api_preparsecode'], ['Hello world']);
    }

    public function storeFallsBackToTheMembersOwnIpWhenSet(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];
        $GLOBALS['api_num_rows']    = 1;
        $GLOBALS['user_info']['ip'] = '203.0.113.9';

        $request = Environment::request('POST', ['board' => 2, 'subject' => 'Hi', 'body' => 'Body']);
        (new TopicController($request))->store([]);

        Assert::same($GLOBALS['api_create_post'][0][2]['ip'], '203.0.113.9');
    }

    public function storeQueuesTheTopicWhenOnlyTheUnapprovedPermissionApplies(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['api_permissions'] = ['post_unapproved_topics'];
        $GLOBALS['api_num_rows']    = 1;

        $request = Environment::request('POST', ['board' => 5, 'subject' => 'Q', 'body' => 'Body']);
        (new TopicController($request))->store([]);

        Assert::false($GLOBALS['api_create_post'][0][0]['approved']);
        Assert::false($GLOBALS['api_create_post'][0][1]['is_approved']);
    }

    public function storeApprovesWhenPostModerationIsOff(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_unapproved_topics'];
        $GLOBALS['api_num_rows']    = 1;

        $request = Environment::request('POST', ['board' => 5, 'subject' => 'Q', 'body' => 'Body']);
        (new TopicController($request))->store([]);

        Assert::true($GLOBALS['api_create_post'][0][0]['approved']);
        Assert::true($GLOBALS['api_create_post'][0][1]['is_approved']);
    }

    public function storeIsRefusedWhenTheMemberCannotPostThere(): void
    {
        Environment::reset();
        $GLOBALS['api_num_rows'] = 1;

        $request   = Environment::request('POST', ['board' => 2, 'subject' => 'Hi', 'body' => 'Hello']);
        $exception = $this->error(fn () => (new TopicController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRejectsAmissingBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];
        $GLOBALS['api_num_rows']    = 0;

        $request   = Environment::request('POST', ['board' => 99, 'subject' => 'Hi', 'body' => 'Hello']);
        $exception = $this->error(fn () => (new TopicController($request))->store([]));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeRejectsAnAbsentBoardField(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];
        $GLOBALS['api_num_rows']    = 0;

        // No "board" key at all: the default resolves to 0, which is rejected as
        // a bad request before any board lookup happens.
        $request   = Environment::request('POST', ['subject' => 'Hi', 'body' => 'Hello']);
        $exception = $this->error(fn () => (new TopicController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeRejectsAzeroBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];
        $GLOBALS['api_num_rows']    = 1;

        $request   = Environment::request('POST', ['board' => 0, 'subject' => 'Hi', 'body' => 'Hello']);
        $exception = $this->error(fn () => (new TopicController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeValidatesRequiredFields(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_new'];

        $request   = Environment::request('POST', ['board' => 2, 'subject' => 'Hi']);
        $exception = $this->error(fn () => (new TopicController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function updateEditsTheSubjectThroughTheOpeningPost(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            ['subject' => 'Renamed'] + self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['subject' => 'Renamed']);
        $response = (new TopicController($request))->update(['id' => '7']);

        Assert::same(Environment::readResponse($response)['payload']['data']['subject'], 'Renamed');
        Assert::same($GLOBALS['api_modify_post'][0][0]['subject'], 'Renamed');
        Assert::same($GLOBALS['api_modify_post'][0][0]['modify_name'], 'Tester');
        Assert::same($GLOBALS['api_modify_post'][0][0]['modify_reason'], '');
    }

    public function updateAuthorMayEditTheSubjectWithModifyOwn(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 1,
                'author_id'         => '9',
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['subject' => 'Renamed']);
        $response = (new TopicController($request))->update(['id' => '7']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_post'][0][0]['subject'], 'Renamed');
    }

    public function updateRefusesModifyOwnSubjectForANonAuthor(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 1,
                'author_id'         => 1,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
        ];

        $request   = Environment::request('PATCH', ['subject' => 'Renamed']);
        $exception = $this->error(fn () => (new TopicController($request))->update(['id' => '7']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateLocksAtopicAndRecordsTheFullModifyOptions(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['lock_any'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => '2',
                'id_first_msg'      => '100',
                'id_member_started' => 9,
                'author_id'         => '9',
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['locked' => true]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][0], ['id' => 100, 'body' => 'text']);
        Assert::same($GLOBALS['api_modify_post'][0][1], ['id' => 7, 'board' => 2, 'lock_mode' => 1]);
        Assert::same($GLOBALS['api_modify_post'][0][2], ['id' => 9]);
    }

    public function updateUnlocksAtopicWithLockModeZero(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['lock_any'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['locked' => false]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][1]['lock_mode'], 0);
    }

    public function updateStarterMayLockWithLockOwn(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['lock_own'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => '9',
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['locked' => true]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][1]['lock_mode'], 1);
    }

    public function updateRefusesLockOwnForANonStarter(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['lock_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 1,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
        ];

        $request   = Environment::request('PATCH', ['locked' => true]);
        $exception = $this->error(fn () => (new TopicController($request))->update(['id' => '7']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateStarterMayLockWithBothLockPermissions(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['lock_any', 'lock_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['locked' => true]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][1]['lock_mode'], 1);
    }

    public function updatePinsAtopicWithMakeSticky(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['make_sticky'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['sticky' => true]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][1]['sticky_mode'], 1);
    }

    public function updateUnpinsAtopicWithStickyModeZero(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['make_sticky'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        $request  = Environment::request('PATCH', ['sticky' => false]);
        (new TopicController($request))->update(['id' => '7']);

        Assert::same($GLOBALS['api_modify_post'][0][1]['sticky_mode'], 0);
    }

    public function updateRefusesPinningWithoutMakeSticky(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
        ];

        $request   = Environment::request('PATCH', ['sticky' => true]);
        $exception = $this->error(fn () => (new TopicController($request))->update(['id' => '7']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateRefusesAsubjectEditWithoutTheModifyPermission(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
        ];

        $request   = Environment::request('PATCH', ['subject' => 'Renamed']);
        $exception = $this->error(fn () => (new TopicController($request))->update(['id' => '7']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function replaceRequiresEveryWritableField(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any', 'lock_any', 'make_sticky'];
        $GLOBALS['api_rows'] = [
            [
                'id_topic'          => 7,
                'id_board'          => 2,
                'id_first_msg'      => 100,
                'id_member_started' => 9,
                'author_id'         => 9,
                'subject'           => 'Old',
                'body'              => 'text',
            ],
            self::STRING_ROW,
        ];

        // PUT is a full replace: omitting "locked"/"sticky" must be rejected.
        $request   = Environment::request('PUT', ['subject' => 'X']);
        $exception = $this->error(fn () => (new TopicController($request))->replace(['id' => '7']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function replaceRejectsAmissingTopic(): void
    {
        Environment::reset();

        $request   = Environment::request('PUT', ['subject' => 'X', 'locked' => false, 'sticky' => false]);
        $exception = $this->error(fn () => (new TopicController($request))->replace(['id' => '7']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function destroyRemovesAtopic(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['remove_any'];
        $GLOBALS['api_rows'] = [['id_topic' => 7, 'id_board' => 2, 'id_member_started' => 9]];

        $response = (new TopicController(Environment::request('DELETE')))->destroy(['id' => '7']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_remove_topics'][0], [7]);
    }

    public function destroyLetsTheStarterRemoveTheirOwnTopic(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['remove_own'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [['id_topic' => 7, 'id_board' => 2, 'id_member_started' => '9']];

        $response = (new TopicController(Environment::request('DELETE')))->destroy(['id' => '7']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_remove_topics'][0], [7]);
    }

    public function destroyRefusesRemoveOwnForANonStarter(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['remove_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [['id_topic' => 7, 'id_board' => 2, 'id_member_started' => 1]];

        $exception = $this->error(fn () => (new TopicController(Environment::request('DELETE')))->destroy(['id' => '7']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id_topic' => 7, 'id_board' => 2, 'id_member_started' => 9]];

        $exception = $this->error(fn () => (new TopicController(Environment::request('DELETE')))->destroy(['id' => '7']));

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
