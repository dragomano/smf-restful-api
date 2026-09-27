<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\AbstractController;
use SMF\API\Query;
use SMF\API\Request;
use SMF\API\Response;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

/**
 * Exposes AbstractController's protected helpers so they can be exercised
 * directly. Not a test case itself (no #[Test]).
 */
final class ProbeController extends AbstractController
{
    public function callId(array $params, string $name = 'id'): int
    {
        return $this->id($params, $name);
    }

    public function callPagination(): array
    {
        return $this->pagination();
    }

    public function callPaginate(Query $query): Response
    {
        return $this->paginate($query);
    }

    public function callWritable(array $fields, bool $full): array
    {
        return $this->writable($fields, $full);
    }

    public function callBoolean(mixed $value): bool
    {
        return $this->boolean($value);
    }

    public function callClean(mixed $value): string
    {
        return $this->cleanString($value);
    }

    public function callNoContent(): Response
    {
        return $this->noContent();
    }

    public function callTransform(array $row): array
    {
        return $this->transform($row);
    }
}

#[Test]
#[Covers(AbstractController::class)]
final class AbstractControllerTest
{
    public function readsApositiveIdentifier(): void
    {
        Assert::same($this->probe()->callId(['id' => '5']), 5);
    }

    #[DataSet([['id' => 'abc']], 'non-numeric')]
    #[DataSet([['id' => '0']], 'zero')]
    #[DataSet([['id' => '-3']], 'negative')]
    #[DataSet([[]], 'missing')]
    public function rejectsAninvalidIdentifier(array $params): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callId($params));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'Invalid "id" identifier');
    }

    public function paginationDefaultsToTheFirstPage(): void
    {
        Assert::same($this->probe()->callPagination(), [25, 0]);
    }

    #[DataSet([['limit' => '50'], 50, 0], 'an explicit limit is honoured')]
    #[DataSet([['limit' => '500'], 100, 0], 'the limit is capped')]
    #[DataSet([['limit' => '0'], 1, 0], 'the limit floors at one')]
    #[DataSet([['offset' => '10'], 25, 10], 'an offset is honoured')]
    #[DataSet([['offset' => '-5'], 25, 0], 'a negative offset floors at zero')]
    public function paginationClampsToSaneBounds(array $query, int $limit, int $offset): void
    {
        Assert::same($this->probe($query)->callPagination(), [$limit, $offset]);
    }

    public function writableDemandsEveryFieldForAfullReplace(): void
    {
        $probe = $this->probe([], ['name' => 'A']);

        $exception = $this->badRequest(fn () => $probe->callWritable(['name', 'description'], true));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'Field "description" is required for a full update');
    }

    public function writableReturnsAllFieldsWhenTheReplaceIsComplete(): void
    {
        $probe = $this->probe([], ['name' => 'A', 'description' => 'B']);

        Assert::same($probe->callWritable(['name', 'description'], true), ['name' => 'A', 'description' => 'B']);
    }

    public function writableKeepsOnlyTheSuppliedFieldsForApatch(): void
    {
        $probe = $this->probe([], ['description' => 'B']);

        Assert::same($probe->callWritable(['name', 'description'], false), ['description' => 'B']);
    }

    public function writableRejectsAnEmptyPatch(): void
    {
        $probe = $this->probe([], ['unrelated' => 'x']);

        $exception = $this->badRequest(fn () => $probe->callWritable(['name'], false));

        Assert::same($exception->getStatusCode(), 400);
    }

    #[DataSet([true, true], 'boolean true')]
    #[DataSet(['yes', true], 'the word yes')]
    #[DataSet(['on', true], 'the word on')]
    #[DataSet(['1', true], 'the string one')]
    #[DataSet(['YES', true], 'an uppercase word is lower-cased first')]
    #[DataSet(['On', true], 'a mixed-case word is lower-cased first')]
    #[DataSet([false, false], 'boolean false')]
    #[DataSet(['no', false], 'the word no')]
    #[DataSet(['0', false], 'the string zero')]
    #[DataSet(['', false], 'the empty string')]
    public function interpretsTheUsualBooleanSpellings(mixed $value, bool $expected): void
    {
        Assert::same($this->probe()->callBoolean($value), $expected);
    }

    public function cleanStringTrimsAndEscapes(): void
    {
        Assert::same($this->probe()->callClean('  <b>hi</b>  '), '&lt;b&gt;hi&lt;/b&gt;');
    }

    public function noContentAnswersWithTwoZeroFour(): void
    {
        Assert::same(Environment::readResponse($this->probe()->callNoContent())['status'], 204);
    }

    public function transformPassesRowsThroughByDefault(): void
    {
        Assert::same($this->probe()->callTransform(['a' => 1, 'b' => 2]), ['a' => 1, 'b' => 2]);
    }

    public function paginateShapesAcollectionResponse(): void
    {
        Environment::reset();
        $GLOBALS['api_rows']  = [['id' => 1]];
        $GLOBALS['api_total'] = 5;

        $probe    = new ProbeController(Environment::request());
        $response = $probe->callPaginate((new Query())->from('{db_prefix}boards')->where('1=1'));
        $payload  = Environment::readResponse($response)['payload'];

        Assert::same($payload['data'], [['id' => 1]]);
        Assert::same($payload['meta'], [
            'total'  => 5,
            'limit'  => 25,
            'offset' => 0,
            'count'  => 1,
        ]);
    }

    public function paginateAppliesTransformToEachRow(): void
    {
        Environment::reset();
        $GLOBALS['api_rows']  = [['id' => 1]];
        $GLOBALS['api_total'] = 1;

        $probe = new class (Environment::request()) extends AbstractController {
            public function run(Query $query): Response
            {
                return $this->paginate($query);
            }

            protected function transform(array $row): array
            {
                return ['wrapped' => $row];
            }
        };

        $response = $probe->run((new Query())->from('{db_prefix}boards')->where('1=1'));

        Assert::same(Environment::readResponse($response)['payload']['data'], [['wrapped' => ['id' => 1]]]);
    }

    private function probe(array $query = [], array $body = []): ProbeController
    {
        Environment::reset();

        return new ProbeController(Environment::request($body === [] ? 'GET' : 'POST', $body, $query));
    }

    private function badRequest(callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected a bad request');
    }
}
