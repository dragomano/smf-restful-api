<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\BoardController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(BoardController::class)]
final class BoardControllerTest
{
    // Fixtures use string values on purpose: a real DB hands every column back
    // as a string, so the transform() (int) casts are only observable when the
    // raw row is a string. Native ints would make dropping the casts equivalent.
    private const BOARD_ROW = [
        'id_board'    => '4',
        'id_cat'      => '2',
        'id_parent'   => '0',
        'name'        => 'Chat',
        'description' => 'General chat',
        'num_topics'  => '8',
        'num_posts'   => '40',
        'board_order' => '1',
    ];

    public function indexReturnsTransformedBoards(): void
    {
        Environment::reset();
        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->index([]);
        $data     = Environment::readResponse($response)['payload']['data'];

        // Full-array assert against ints kills every transform() cast/field mutant.
        Assert::same($data[0], [
            'id'          => 4,
            'category'    => 2,
            'parent'      => 0,
            'name'        => 'Chat',
            'description' => 'General chat',
            'num_topics'  => 8,
            'num_posts'   => 40,
            'order'       => 1,
        ]);
    }

    public function indexScopedToAcategoryChecksItExists(): void
    {
        Environment::reset();
        $GLOBALS['api_num_rows'] = 1;
        $GLOBALS['api_total']    = 1;
        $GLOBALS['api_rows']     = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->index(['cat_id' => '2']);

        Assert::same(Environment::readResponse($response)['payload']['meta']['total'], 1);

        // The board query must keep the {query_see_board} guard alongside the
        // category filter (kills the ".=" -> "=" assignment mutant on the WHERE).
        $boardQuery = '';
        foreach ($GLOBALS['api_queries'] as $recorded) {
            if (str_contains($recorded['query'], '{db_prefix}boards AS b')) {
                $boardQuery = $recorded['query'];
                break;
            }
        }

        Assert::true(str_contains($boardQuery, '{query_see_board}'));
        Assert::true(str_contains($boardQuery, 'b.id_cat = {int:cat}'));
    }

    public function indexScopedToAmissingCategoryIsNotFound(): void
    {
        Environment::reset();
        $GLOBALS['api_num_rows'] = 0;

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->index(['cat_id' => '99']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showReturnsAboard(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->show(['id' => '4']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 4);
    }

    public function showHidesAninvisibleBoardAsNotFound(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->show(['id' => '4']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeCreatesAboardInsideAcategory(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;
        $GLOBALS['api_new_id']      = 15;

        // A string "category" (as a form request delivers) makes the (int) cast
        // on the target_category observable.
        $request  = Environment::request('POST', ['category' => '2', 'name' => 'Ideas', 'description' => 'd']);
        $response = (new BoardController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data']['id'], 15);
        Assert::same($GLOBALS['api_create_board'][0], [
            'board_name'        => 'Ideas',
            'board_description' => 'd',
            'move_to'           => 'bottom',
            'target_category'   => 2,
        ]);
    }

    public function storeRejectsAmissingCategory(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows'] = 0;

        $request   = Environment::request('POST', ['category' => 99, 'name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeValidatesRequiredFields(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];

        $request   = Environment::request('POST', ['name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['category' => 2, 'name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateAppliesTheNameAndPassesTheOriginalCategory(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2'],
            ['name' => 'Renamed'] + self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Renamed']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Renamed');
        // Full recorded-args assert; old_id_cat coming back as int 2 pins the
        // (int) cast on $current['id_cat'].
        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2, 'board_name' => 'Renamed']]);
    }

    public function updateLeavesTheCategoryUntouchedWhenUnchanged(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        // Current category is the string "2"; sending the string "2" back must be
        // recognised as "no change" (both sides go through (int)), so no move
        // options and no category-existence lookup are triggered.
        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2'],
            self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'X', 'category' => '2']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2, 'board_name' => 'X']]);
    }

    public function updateMovesTheBoardToAnotherCategory(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows'] = 1; // the target category exists
        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2'],
            self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Moved', 'category' => '5']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_board'][0], [
            4,
            ['old_id_cat' => 2, 'board_name' => 'Moved', 'move_to' => 'bottom', 'target_category' => 5],
        ]);
    }

    public function replaceRejectsAmissingBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];

        $request   = Environment::request('PUT', ['name' => 'X', 'description' => 'Y']);
        $exception = $this->error(fn () => (new BoardController($request))->replace(['id' => '4']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function replaceDemandsEveryWritableField(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        // The board exists, but only "name" is supplied. A full replace (PUT)
        // must reject the partial body; a partial update (PATCH) would accept it.
        $GLOBALS['api_rows'] = [['id_board' => '4', 'id_cat' => '2']];

        $request   = Environment::request('PUT', ['name' => 'X']);
        $exception = $this->error(fn () => (new BoardController($request))->replace(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRemovesTheBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows'] = 1;

        $response = (new BoardController(Environment::request()))->destroy(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_boards'][0], [[4], null]);
    }

    public function destroyMovesChildrenToAnotherBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        // The board being deleted and the move target both exist.
        $GLOBALS['api_num_rows_queue'] = [1, 1];

        $request  = Environment::request('DELETE', [], ['move_children_to' => '7']);
        $response = (new BoardController($request))->destroy(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        // The move target must be the int 7 (kills the (int) cast on the query param).
        Assert::same($GLOBALS['api_delete_boards'][0], [[4], 7]);
    }

    public function destroyRejectsMovingChildrenIntoTheSameBoard(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows_queue'] = [1, 1];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '4']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsMoveTargetEqualToTheBoardEvenWhenAbsent(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        // Guard passes; the second existence lookup would report "missing".
        $GLOBALS['api_num_rows_queue'] = [1, 0];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '4']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsAmissingMoveTarget(): void
    {
        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        // The board exists, but the move target does not.
        $GLOBALS['api_num_rows_queue'] = [1, 0];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '7']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->destroy(['id' => '4']));

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
