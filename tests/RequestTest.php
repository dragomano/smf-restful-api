<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Request;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Request::class)]
final class RequestTest
{
    public function defaultsToGet(): void
    {
        Environment::reset();

        Assert::same(Environment::request()->getMethod(), 'GET');
    }

    public function readsThePostVerb(): void
    {
        Environment::reset();

        Assert::same(Environment::request('POST')->getMethod(), 'POST');
    }

    public function upperCasesTheRequestVerb(): void
    {
        Environment::reset();

        Assert::same(Environment::request('post')->getMethod(), 'POST');
    }

    public function honoursTheMethodOverrideHeaderForPosts(): void
    {
        Environment::reset();

        $request = Environment::request('POST', [], [], ['X-HTTP-Method-Override' => 'PUT']);

        Assert::same($request->getMethod(), 'PUT');
    }

    public function honoursTheMethodOverrideQueryForPosts(): void
    {
        Environment::reset();

        $request = Environment::request('POST', [], ['_method' => 'delete']);

        Assert::same($request->getMethod(), 'DELETE');
    }

    public function ignoresTheOverrideWhenTheVerbIsNotPost(): void
    {
        Environment::reset();

        $request = Environment::request('GET', [], ['_method' => 'DELETE']);

        Assert::same($request->getMethod(), 'GET');
    }

    public function splitsThePathQueryIntoSegments(): void
    {
        Environment::reset();

        $request = Environment::request('GET', [], ['path' => '/v1/members/5/']);

        Assert::same($request->getSegments(), ['v1', 'members', '5']);
    }

    public function dropsEmptySegments(): void
    {
        Environment::reset();

        $request = Environment::request('GET', [], ['path' => 'v1//members']);

        Assert::same($request->getSegments(), ['v1', 'members']);
    }

    public function fallsBackToPathInfoAndStripsTheApiPrefix(): void
    {
        Environment::reset();
        $_SERVER['PATH_INFO'] = '/api/v1/topics/9';

        Assert::same((new Request())->getSegments(), ['v1', 'topics', '9']);
    }

    public function hasNoSegmentsForAnEmptyPath(): void
    {
        Environment::reset();

        Assert::same(Environment::request()->getSegments(), []);
    }

    public function exposesPositionalSegmentsWithADefault(): void
    {
        Environment::reset();

        $request = Environment::request('GET', [], ['path' => 'v1/members']);

        Assert::same($request->segment(1), 'members');
        Assert::same($request->segment(5, 'fallback'), 'fallback');
        // A present segment wins over the supplied default (guards the ?? order).
        Assert::same($request->segment(1, 'zzz'), 'members');
    }

    public function readsQueryBodyAndHeaders(): void
    {
        Environment::reset();

        $request = Environment::request(
            'POST',
            ['name' => 'Alice', 'email' => 'alice@example.test'],
            ['limit' => '50'],
            ['X-Api-Key' => 'secret']
        );

        Assert::same($request->query('limit'), '50');
        Assert::same($request->input('name'), 'Alice');
        Assert::same($request->all(), ['name' => 'Alice', 'email' => 'alice@example.test']);
        Assert::same($request->header('x-api-key'), 'secret');
        // Header lookup is case-insensitive on the requested name.
        Assert::same($request->header('X-Api-Key'), 'secret');
        Assert::same($request->input('missing', 'default'), 'default');
        // A present value wins over the supplied default (guards the ?? order).
        Assert::same($request->query('limit', 'other'), '50');
        Assert::same($request->input('name', 'other'), 'Alice');
    }

    public function pathQueryTakesPrecedenceOverPathInfo(): void
    {
        Environment::reset();
        $_SERVER['PATH_INFO'] = '/api/v1/other';

        $request = Environment::request('GET', [], ['path' => 'v1/members']);

        Assert::same($request->getSegments(), ['v1', 'members']);
    }

    public function detectsAjsonContentTypeCaseInsensitively(): void
    {
        Environment::reset();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'APPLICATION/JSON';

        $caught = null;

        try {
            new Request();
        } catch (ApiException $exception) {
            $caught = $exception;
        }

        // An uppercase content-type must still be treated as JSON (empty body → malformed).
        Assert::instanceOf($caught, ApiException::class);
    }

    public function keepsEveryHeaderAndPrefersTheHttpPrefix(): void
    {
        Environment::reset();
        $_SERVER['HTTP_AUTHORIZATION'] = 'real-token';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'redirect-token';
        $_SERVER['HTTP_X_CUSTOM'] = 'custom-value';

        $request = new Request();

        // The direct HTTP_ header wins over the REDIRECT_HTTP_ fallback...
        Assert::same($request->header('authorization'), 'real-token');
        // ...and unrelated headers are all retained.
        Assert::same($request->header('x-custom'), 'custom-value');
    }

    public function readsHeadersExposedThroughTheRedirectPrefix(): void
    {
        Environment::reset();
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer token';

        Assert::same((new Request())->header('authorization'), 'Bearer token');
    }

    public function rejectsAMalformedJsonBody(): void
    {
        Environment::reset();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';

        $caught = null;

        try {
            new Request();
        } catch (ApiException $exception) {
            $caught = $exception;
        }

        Assert::instanceOf($caught, ApiException::class);
        Assert::same($caught->getStatusCode(), 400);
    }
}
