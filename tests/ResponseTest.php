<?php

declare(strict_types=1);

use SMF\API\Response;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Response::class)]
final class ResponseTest
{
    public function defaultsToAtwo00Status(): void
    {
        Assert::same(Environment::readResponse(new Response())['status'], 200);
    }

    public function wrapsDataUnderADataKey(): void
    {
        $response = (new Response())->data(['id' => 7]);

        Assert::same(Environment::readResponse($response)['payload'], ['data' => ['id' => 7]]);
    }

    public function buildsCollectionMetadataFromTheItems(): void
    {
        $response = (new Response())->collection([['id' => 1], ['id' => 2]], 40, 25, 0);

        $payload = Environment::readResponse($response)['payload'];

        Assert::same($payload['data'], [['id' => 1], ['id' => 2]]);
        Assert::same($payload['meta'], [
            'total'  => 40,
            'limit'  => 25,
            'offset' => 0,
            'count'  => 2,
        ]);
    }

    public function errorSetsBothTheStatusAndThePayload(): void
    {
        $response = (new Response())->error(404, 'Nope', 'not_found');

        $state = Environment::readResponse($response);

        Assert::same($state['status'], 404);
        Assert::same($state['payload'], [
            'error' => [
                'code'    => 'not_found',
                'message' => 'Nope',
            ],
        ]);
    }

    public function errorOmitsEmptyDetailsButKeepsSuppliedOnes(): void
    {
        $without = Environment::readResponse((new Response())->error(400, 'Bad', 'bad_request'));
        Assert::same(array_key_exists('details', $without['payload']['error']), false);

        $with = Environment::readResponse(
            (new Response())->error(400, 'Bad', 'bad_request', ['errors' => ['name']])
        );
        Assert::same($with['payload']['error']['details'], ['errors' => ['name']]);
    }

    public function isFluent(): void
    {
        $response = (new Response())
            ->status(201)
            ->header('Location', '/v1/members/7')
            ->data(['id' => 7]);

        $state = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['headers'], ['Location' => '/v1/members/7']);
        Assert::same($state['payload'], ['data' => ['id' => 7]]);
    }
}
