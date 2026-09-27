<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\PostController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(PostController::class)]
final class PostControllerTest
{
    // A message row with every value as a string, so a dropped (int)/(bool)
    // cast produces an observably different type in the transformed output.
    private const MESSAGE_ROW = [
        'id_msg'        => '100',
        'id_topic'      => '7',
        'id_board'      => '2',
        'id_member'     => '9',
        'poster_name'   => 'Poster',
        'poster_time'   => '1234',
        'subject'       => 'Re: Topic',
        'body'          => '[i]reply[/i]',
        'approved'      => '1',
        'modified_time' => '0',
        'modified_name' => '',
    ];

    // The exact transform() output for MESSAGE_ROW, with the correct types.
    private const MESSAGE_PAYLOAD = [
        'id'            => 100,
        'topic'         => 7,
        'board'         => 2,
        'subject'       => 'Re: Topic',
        'author'        => ['id' => 9, 'name' => 'Poster'],
        'poster_time'   => 1234,
        'modified_time' => 0,
        'modified_name' => '',
        'approved'      => true,
        'body'          => 'parsed:[i]reply[/i]',
        'body_raw'      => '[i]reply[/i]',
    ];

    // ----- index / show / transform ----------------------------------------

    public function indexReturnsThePostsOfAvisibleTopic(): void
    {
        Environment::reset();
        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [
            ['id_topic' => '7', 'approved' => '1'],
            self::MESSAGE_ROW,
        ];

        $response = (new PostController(Environment::request()))->index(['topic_id' => '7']);
        $data     = Environment::readResponse($response)['payload']['data'];

        // The whole transformed row, so every cast is pinned to its type.
        Assert::same($data[0], self::MESSAGE_PAYLOAD);

        // The topic-existence query carries the topic id.
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 7]);

        // For a non-admin the messages query filters on the topic AND approval.
        Assert::same($GLOBALS['api_queries'][1]['params'], ['topic' => 7, 'approved' => 1]);
        Assert::true(str_contains($GLOBALS['api_queries'][1]['query'], 'm.id_topic = {int:topic}'));
        Assert::true(str_contains($GLOBALS['api_queries'][1]['query'], 'm.approved = {int:approved}'));
    }

    public function indexForAnAdminSkipsTheApprovalFilter(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [
            ['id_topic' => '7', 'approved' => '0'],
            self::MESSAGE_ROW,
        ];

        $response = (new PostController(Environment::request()))->index(['topic_id' => '7']);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data[0], self::MESSAGE_PAYLOAD);
        // No approval filter for an admin: the query params stay topic-only.
        Assert::same($GLOBALS['api_queries'][1]['params'], ['topic' => 7]);
        Assert::false(str_contains($GLOBALS['api_queries'][1]['query'], 'm.approved = {int:approved}'));
    }

    public function indexHidesAnUnapprovedTopicFromNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id_topic' => '7', 'approved' => '0']];

        $exception = $this->error(fn () => (new PostController(Environment::request()))->index(['topic_id' => '7']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showReturnsApost(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [self::MESSAGE_ROW];

        $response = (new PostController(Environment::request()))->show(['id' => '100']);
        $data     = Environment::readResponse($response)['payload']['data'];

        // The full payload pins the transform casts, and approved='1' as a
        // string keeps the "(int) approved !== 1" visibility check meaningful.
        Assert::same($data, self::MESSAGE_PAYLOAD);
        // The lookup query keys the message id (=> must stay an array item).
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 100]);
    }

    public function showHidesAnUnapprovedPostFromNonAdministrators(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['approved' => '0'] + self::MESSAGE_ROW];

        $exception = $this->error(fn () => (new PostController(Environment::request()))->show(['id' => '100']));

        Assert::same($exception->getStatusCode(), 404);
    }

    // ----- store (reply) ---------------------------------------------------

    public function storePostsAreplyOnBehalfOfTheMember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_reply_any'];
        $GLOBALS['api_new_msg_id']  = '500'; // string, so the (int) cast in the reply is observable
        $GLOBALS['user_info']['id'] = '9';   // string, so the poster-id cast is observable
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => '  My reply  ']);
        $response = (new PostController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data'], ['id' => 500, 'topic' => 7, 'board' => 2, 'approved' => true]);

        // The whole msgOptions handed to createPost, recorded before the id is set.
        Assert::same($GLOBALS['api_create_post'][0][0], [
            'id' => 0, 'subject' => 'Re: Topic', 'body' => 'My reply', 'icon' => 'xx',
            'smileys_enabled' => true, 'attachments' => [], 'approved' => true,
        ]);
        Assert::same($GLOBALS['api_create_post'][0][1], [
            'id' => 7, 'board' => 2, 'mark_as_read' => true, 'is_approved' => true,
        ]);
        Assert::same($GLOBALS['api_create_post'][0][2], [
            'id' => 9, 'name' => 'Tester', 'email' => 'tester@example.test',
            'update_post_count' => true, 'ip' => '127.0.0.1',
        ]);

        // The body was run through preparsecode and the topic lookup was keyed.
        Assert::same($GLOBALS['api_preparsecode'], ['My reply']);
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 7]);
    }

    public function storeDoesNotDoublePrefixASubjectThatAlreadyStartsWithRe(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_reply_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Re: Existing'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        (new PostController($request))->store([]);

        Assert::same($GLOBALS['api_create_post'][0][0]['subject'], 'Re: Existing');
    }

    public function storeFallsBackToTheMembersOwnIpBeforeTheServerAddress(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions']  = ['post_reply_any'];
        $GLOBALS['user_info']['ip']  = '10.0.0.5';
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        (new PostController($request))->store([]);

        Assert::same($GLOBALS['api_create_post'][0][2]['ip'], '10.0.0.5');
    }

    public function storeQueuesForApprovalWhenTheMemberHoldsOnlyUnapprovedRights(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['api_permissions'] = ['post_unapproved_replies_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '5', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);
        $data     = Environment::readResponse($response)['payload']['data'];

        Assert::same($data['approved'], false);
        Assert::same($GLOBALS['api_create_post'][0][0]['approved'], false);
        Assert::same($GLOBALS['api_create_post'][0][1]['is_approved'], false);
    }

    public function storeApprovesImmediatelyWhenReplyAnyWithPostmodActive(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['api_permissions'] = ['post_reply_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '5', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['payload']['data']['approved'], true);
    }

    public function storeApprovesImmediatelyWhenPostmodIsInactive(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_unapproved_replies_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '5', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['payload']['data']['approved'], true);
    }

    public function storeApprovesAstarterUsingReplyOwnWithPostmodActive(): void
    {
        Environment::reset();
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['api_permissions'] = ['post_reply_own'];
        $GLOBALS['user_info']['id'] = 9; // int; starter id is a string, so both casts matter
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['payload']['data']['approved'], true);
    }

    public function storeAllowsAstarterUsingUnapprovedRepliesOwn(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_unapproved_replies_own'];
        $GLOBALS['user_info']['id'] = '9'; // string; starter id is an int, so both casts matter
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => 9, 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['status'], 201);
    }

    public function storeAllowsUnapprovedRepliesAnyForANonStarter(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_unapproved_replies_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '5', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['status'], 201);
    }

    public function storeRefusesAstarterWhoHoldsNoReplyPermission(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request   = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRefusesReplyOwnForANonStarter(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_reply_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '5', 'subject' => 'Topic'],
        ];

        $request   = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRefusesAlockedTopicForOrdinaryMembers(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['post_reply_any'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '1', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request   = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeIsRefusedWhenTheMemberCannotReply(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request   = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRejectsAmissingTopic(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeValidatesRequiredFields(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['body' => 'orphan']);
        $exception = $this->error(fn () => (new PostController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    // ----- save (update / replace) -----------------------------------------

    public function updateEditsTheBodyAndReturnsTheRefreshedPost(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
            [
                'id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_name' => 'Poster',
                'poster_time' => '1234', 'subject' => 'Edited subject', 'body' => 'edited', 'approved' => '1',
                'modified_time' => '0', 'modified_name' => 'Tester',
            ],
        ];

        $request  = Environment::request('PATCH', ['body' => '  edited  ', 'reason' => 'cleanup']);
        $response = (new PostController($request))->update(['id' => '100']);
        $state    = Environment::readResponse($response);

        // The whole refreshed payload, so every response cast is pinned.
        Assert::same($state['payload']['data'], [
            'id' => 100, 'topic' => 7, 'board' => 2, 'subject' => 'Edited subject',
            'author' => ['id' => 9, 'name' => 'Poster'],
            'poster_time' => 1234, 'modified_time' => 0, 'modified_name' => 'Tester',
            'approved' => true, 'body' => 'parsed:edited', 'body_raw' => 'edited',
        ]);

        // The body was trimmed, prepared and forwarded with the right options.
        $msg = $GLOBALS['api_modify_post'][0][0];
        Assert::same($msg['id'], 100);
        Assert::same($msg['body'], 'edited');
        Assert::same($msg['modify_name'], 'Tester');
        Assert::same($msg['modify_reason'], 'cleanup');
        Assert::true(is_int($msg['modify_time']));
        Assert::same($GLOBALS['api_modify_post'][0][1], ['id' => 7, 'board' => 2]);
        Assert::same($GLOBALS['api_modify_post'][0][2], ['id' => 9]);

        Assert::same($GLOBALS['api_preparsecode'], ['edited']);
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 100]);
        Assert::same($GLOBALS['api_queries'][1]['params'], ['id' => 100]);
    }

    public function updateIsRefusedForAnotherMembersPost(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
        ];

        $request   = Environment::request('PATCH', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateHidesAnotherMembersUnapprovedPost(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '0'],
        ];

        $request   = Environment::request('PATCH', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function updateFindsAnApprovedPostForANonAuthorWithModifyAny(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
            ['body' => 'edited'] + self::MESSAGE_ROW,
        ];

        $request  = Environment::request('PATCH', ['body' => 'edited']);
        $response = (new PostController($request))->update(['id' => '100']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 100);
    }

    public function updateLetsTheAuthorEditTheirOwnUnapprovedPost(): void
    {
        // Member id string, author id int: the "found" and "isAuthor" checks
        // both depend on the (int) casts on both sides of the comparison.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '0'],
            ['body' => 'edited'] + self::MESSAGE_ROW,
        ];

        $request  = Environment::request('PATCH', ['body' => 'edited']);
        $response = (new PostController($request))->update(['id' => '100']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 100);
    }

    public function updateLetsTheAuthorEditWhenTheirIdIsAnInteger(): void
    {
        // Member id int, author id string: mirrors the previous test to pin the
        // cast on the other operand.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => 9, 'poster_time' => '1234', 'approved' => '0'],
            ['body' => 'edited'] + self::MESSAGE_ROW,
        ];

        $request  = Environment::request('PATCH', ['body' => 'edited']);
        $response = (new PostController($request))->update(['id' => '100']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 100);
    }

    public function updateIsRefusedForAnAuthorWithoutTheModifyOwnPermission(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
        ];

        $request   = Environment::request('PATCH', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateIsRefusedForANonAuthorHoldingOnlyModifyOwn(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 0;
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
        ];

        $request   = Environment::request('PATCH', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateLetsTheAuthorEditWithModifyOwn(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
            ['body' => 'edited'] + self::MESSAGE_ROW,
        ];

        $request  = Environment::request('PATCH', ['body' => 'edited']);
        $response = (new PostController($request))->update(['id' => '100']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 100);
    }

    public function replaceRejectsAmissingPost(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];

        $request   = Environment::request('PUT', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->replace(['id' => '100']));

        Assert::same($exception->getStatusCode(), 404);
    }

    // ----- destroy ---------------------------------------------------------

    /**
     * A row as destroy()'s query returns it. Every value is a string so the
     * (int) casts are observable; override per case.
     */
    private function deleteRow(array $over = []): array
    {
        return $over + [
            'id_msg' => '101', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1',
            'id_board' => '2', 'id_first_msg' => '100', 'id_member_started' => '9', 'num_replies' => '3',
        ];
    }

    public function destroyRemovesAreply(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['api_rows'] = [$this->deleteRow()];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_remove_message'][0], 101);
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 101]);
    }

    public function destroyRejectsDeletingTheFirstPostWhileTheTopicHasReplies(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any', 'remove_any'];
        $GLOBALS['api_rows'] = [$this->deleteRow(['id_msg' => '100', 'id_first_msg' => '100', 'num_replies' => '3'])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '100']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyDeletesAsinglePostTopicWhenItHasNoReplies(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any', 'remove_any'];
        $GLOBALS['api_rows'] = [$this->deleteRow(['id_msg' => '100', 'id_first_msg' => '100', 'num_replies' => '0'])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '100']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_remove_message'][0], 100);
    }

    public function destroyHidesAnotherMembersUnapprovedPost(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = 0;
        $GLOBALS['api_rows'] = [$this->deleteRow(['approved' => '0', 'id_member' => '9'])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function destroyFindsAnApprovedPostOfAnotherMember(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = 0;
        $GLOBALS['api_rows'] = [$this->deleteRow(['approved' => '1', 'id_member' => '9'])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyLetsTheAuthorRemoveTheirOwnUnapprovedPost(): void
    {
        // Member id string, author id int: pins the casts in the "found" check.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow(['approved' => '0', 'id_member' => '9'])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyLetsTheAuthorRemoveWhenTheirIdIsAnInteger(): void
    {
        // Member id int, author id string: pins the cast on the other operand.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [$this->deleteRow(['approved' => '0', 'id_member' => 9])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();
        $GLOBALS['user_info']['id'] = 0;
        $GLOBALS['api_rows'] = [$this->deleteRow()];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyLetsTheAuthorDeleteTheirOwnReplyWithDeleteOwn(): void
    {
        // Author (member id string, author id int), not the topic starter.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow(['id_member' => '9', 'id_member_started' => '5'])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyRefusesAstarterWithoutDeleteRepliesForANonOwnedReply(): void
    {
        // Starter (member started id string, author id int) but not the author.
        Environment::reset();
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow(['id_member' => '5', 'id_member_started' => '9'])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyLetsAstarterDeleteAreplyWithDeleteReplies(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_replies'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow(['id_member' => '5', 'id_member_started' => '9'])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyRefusesTheFirstPostForANonAuthorWithoutRemovePermission(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = 0;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_msg' => '100', 'id_first_msg' => '100', 'num_replies' => '0', 'id_member' => '9',
        ])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyLetsTheAuthorRemoveTheirOwnFirstPostWithRemoveOwn(): void
    {
        // Author (member id int, author id string) removing a single-post topic.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any', 'remove_own'];
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_msg' => '100', 'id_first_msg' => '100', 'num_replies' => '0', 'id_member' => 9,
        ])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '100']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyRefusesTheAuthorsFirstPostWithoutRemovePermission(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_any'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_msg' => '100', 'id_first_msg' => '100', 'num_replies' => '0', 'id_member' => '9',
        ])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyRefusesApendingPostTheMemberCannotApprove(): void
    {
        // Only an admin reaches this branch: a non-admin non-author is already
        // hidden by the visibility check. The admin holds delete_replies and is
        // the starter, but the reply is unapproved and they cannot approve it.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_replies'];
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'approved' => '0', 'id_member' => '5', 'id_member_started' => '9',
        ])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyAllowsAnApprovedPendingBranchPost(): void
    {
        // Same as above but approved: the pending gate must not fire. Member
        // started id int, author id string to pin the isStarter casts.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_replies'];
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['user_info']['id'] = '9';
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'approved' => '1', 'id_member' => '5', 'id_member_started' => 9,
        ])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyAllowsAnUnapprovedPostWhenPostmodIsInactive(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_replies'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['user_info']['is_admin'] = true;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'approved' => '0', 'id_member' => '5', 'id_member_started' => '9',
        ])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    // ----- replace vs update strictness ------------------------------------

    public function replaceReportsThatTheBodyFieldIsRequired(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
        ];

        // A PUT carries no "body": the full-update path rejects it as required.
        $request   = Environment::request('PUT', ['other' => 'x']);
        $exception = $this->error(fn () => (new PostController($request))->replace(['id' => '100']));

        Assert::same($exception->getStatusCode(), 400);
        Assert::true(str_contains($exception->getMessage(), 'required for a full update'));
    }

    public function updateReportsThatNoWritableFieldsWereProvided(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_any'];
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => '1234', 'approved' => '1'],
        ];

        // A PATCH with no writable field is a no-op and rejected as such.
        $request   = Environment::request('PATCH', ['other' => 'x']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 400);
        Assert::true(str_contains($exception->getMessage(), 'No writable fields provided'));
    }

    // ----- store: queue-for-approval edge ----------------------------------

    public function storeQueuesAstarterWhoLacksReplyOwn(): void
    {
        // Starter, postmod active, only "unapproved" rights: the reply is queued
        // (the "$isStarter && post_reply_own" term must stay an AND).
        Environment::reset();
        $GLOBALS['modSettings']['postmod_active'] = 1;
        $GLOBALS['api_permissions'] = ['post_unapproved_replies_any'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [
            ['id_board' => '2', 'locked' => '0', 'id_member_started' => '9', 'subject' => 'Topic'],
        ];

        $request  = Environment::request('POST', ['topic' => '7', 'body' => 'My reply']);
        $response = (new PostController($request))->store([]);

        Assert::same(Environment::readResponse($response)['payload']['data']['approved'], false);
    }

    // ----- save: edit-time window ------------------------------------------

    public function saveAllowsAnAuthorEditingJustInsideTheEditWindow(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['modSettings']['edit_disable_time'] = 10; // 10 * 60 = 600s window
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => (string) (time() - 595), 'approved' => '1'],
            ['body' => 'edited'] + self::MESSAGE_ROW,
        ];

        $request  = Environment::request('PATCH', ['body' => 'edited']);
        $response = (new PostController($request))->update(['id' => '100']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 100);
    }

    public function saveRefusesAnAuthorEditingJustOutsideTheEditWindow(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['modify_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['modSettings']['edit_disable_time'] = 10;
        $GLOBALS['api_rows'] = [
            ['id_msg' => '100', 'id_topic' => '7', 'id_board' => '2', 'id_member' => '9', 'poster_time' => (string) (time() - 605), 'approved' => '1'],
        ];

        $request   = Environment::request('PATCH', ['body' => 'edited']);
        $exception = $this->error(fn () => (new PostController($request))->update(['id' => '100']));

        Assert::same($exception->getStatusCode(), 403);
    }

    // ----- destroy: delete-own time window & starter fallbacks -------------

    public function destroyAllowsTheAuthorDeletingJustInsideTheEditWindow(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['modSettings']['edit_disable_time'] = 10;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_member' => '9', 'id_member_started' => '5', 'poster_time' => (string) (time() - 595),
        ])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyRefusesTheAuthorDeletingJustOutsideTheEditWindow(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_own'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['modSettings']['edit_disable_time'] = 10;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_member' => '9', 'id_member_started' => '5', 'poster_time' => (string) (time() - 605),
        ])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyLetsAnAuthorStarterFallBackToDeleteReplies(): void
    {
        // Author and starter without delete_own: delete_replies saves the
        // request via the second half of the guard on line 441.
        Environment::reset();
        $GLOBALS['api_permissions'] = ['delete_replies'];
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_member' => '9', 'id_member_started' => '9',
        ])];

        $response = (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']);

        Assert::same(Environment::readResponse($response)['status'], 204);
    }

    public function destroyRefusesAnAuthorStarterHoldingNeitherDeleteOwnNorDeleteReplies(): void
    {
        // Author and starter but with no delete permission at all: the author
        // branch's guard keeps "$isStarter && delete_replies" an AND, so the
        // missing delete_replies still yields a 403.
        Environment::reset();
        $GLOBALS['user_info']['id'] = 9;
        $GLOBALS['api_rows'] = [$this->deleteRow([
            'id_member' => '9', 'id_member_started' => '9',
        ])];

        $exception = $this->error(fn () => (new PostController(Environment::request('DELETE')))->destroy(['id' => '101']));

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
