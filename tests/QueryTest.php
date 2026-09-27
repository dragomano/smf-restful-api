<?php

declare(strict_types=1);

use SMF\API\Query;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Query::class)]
final class QueryTest
{
    public function buildsAPaginatedSelectFromTheParts(): void
    {
        $sql = (new Query())
            ->columns('id_cat, name')
            ->from('{db_prefix}categories')
            ->where('id_cat = {int:id}')
            ->orderBy('cat_order')
            ->selectQuery();

        Assert::same(
            $this->normalize($sql),
            'SELECT id_cat, name FROM {db_prefix}categories WHERE id_cat = {int:id} ORDER BY cat_order LIMIT {int:offset}, {int:limit}'
        );
    }

    public function countQueryCountsRows(): void
    {
        $sql = (new Query())->from('{db_prefix}members')->where('is_activated = 1')->countQuery();

        Assert::same($this->normalize($sql), 'SELECT COUNT(*) FROM {db_prefix}members WHERE is_activated = 1');
    }

    public function singleAndExistsQueriesAreCappedToOneRow(): void
    {
        $query = (new Query())->from('{db_prefix}boards')->where('id_board = {int:id}');

        Assert::same($this->normalize($query->singleQuery()), 'SELECT * FROM {db_prefix}boards WHERE id_board = {int:id} LIMIT 1');
        Assert::same($this->normalize($query->existsQuery()), 'SELECT 1 FROM {db_prefix}boards WHERE id_board = {int:id} LIMIT 1');
    }

    public function listQueryOmitsAnEmptyOrderBy(): void
    {
        $query = (new Query())->from('{db_prefix}boards')->where('1=1');

        Assert::same($this->normalize($query->listQuery()), 'SELECT * FROM {db_prefix}boards WHERE 1=1');
        Assert::same(
            $this->normalize($query->orderBy('board_order')->listQuery()),
            'SELECT * FROM {db_prefix}boards WHERE 1=1 ORDER BY board_order'
        );
    }

    private function normalize(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    public function firstReturnsTheRowAndSendsTheSingleQuery(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id_cat' => 3, 'name' => 'General']];

        $row = (new Query())
            ->from('{db_prefix}categories')
            ->where('id_cat = {int:id}')
            ->params(['id' => 3])
            ->first();

        Assert::same($row, ['id_cat' => 3, 'name' => 'General']);
        Assert::same($GLOBALS['api_queries'][0]['params'], ['id' => 3]);
        Assert::same($this->normalize($GLOBALS['api_queries'][0]['query']), 'SELECT * FROM {db_prefix}categories WHERE id_cat = {int:id} LIMIT 1');
        Assert::same($GLOBALS['api_free_result'], 1);
    }

    public function firstReturnsNullWhenNothingMatches(): void
    {
        Environment::reset();

        Assert::null((new Query())->from('{db_prefix}categories')->where('1=0')->first());
        Assert::same($GLOBALS['api_free_result'], 1);
    }

    public function existsReflectsTheRowCount(): void
    {
        Environment::reset();
        $GLOBALS['api_num_rows'] = 1;
        Assert::true((new Query())->from('{db_prefix}boards')->where('1=1')->exists());
        Assert::same($GLOBALS['api_free_result'], 1);

        Environment::reset();
        $GLOBALS['api_num_rows'] = 0;
        Assert::false((new Query())->from('{db_prefix}boards')->where('1=0')->exists());
    }

    public function fetchAllCollectsEveryRow(): void
    {
        Environment::reset();
        $GLOBALS['api_rows'] = [['id' => 1], ['id' => 2], ['id' => 3]];

        $rows = (new Query())->from('{db_prefix}boards')->where('1=1')->fetchAll();

        Assert::same($rows, [['id' => 1], ['id' => 2], ['id' => 3]]);
        Assert::same($GLOBALS['api_free_result'], 1);
    }

    public function fetchPageReturnsRowsWithTheGrandTotal(): void
    {
        Environment::reset();
        $GLOBALS['api_rows']  = [['id' => 1], ['id' => 2]];
        // A string total (as the DB layer returns) makes the (int) cast observable.
        $GLOBALS['api_total'] = '40';

        $page = (new Query())
            ->from('{db_prefix}members')
            ->where('is_activated = {int:active}')
            ->params(['active' => 1])
            ->fetchPage(25, 50);

        Assert::same($page['rows'], [['id' => 1], ['id' => 2]]);
        Assert::same($page['total'], 40);
        // The COUNT request and the paginated SELECT are both freed.
        Assert::same($GLOBALS['api_free_result'], 2);
    }

    public function fetchPageInjectsOffsetAndLimitOverAnyCallerKeys(): void
    {
        Environment::reset();

        (new Query())
            ->from('{db_prefix}members')
            ->where('1=1')
            ->params(['offset' => 999, 'limit' => 999, 'active' => 1])
            ->fetchPage(25, 50);

        // api_queries[0] is the COUNT, [1] is the paginated SELECT.
        Assert::same($GLOBALS['api_queries'][1]['params'], [
            'offset' => 50,
            'limit'  => 25,
            'active' => 1,
        ]);
    }
}
