<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\BoardController;
use SMF\API\Controllers\CategoryController;
use SMF\API\Controllers\MemberController;
use SMF\API\Controllers\PostController;
use SMF\API\Controllers\TopicController;
use SMF\API\Request;
use SMF\API\Router;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Router::class)]
final class RouterTest
{
    public function resolvesAtopLevelCollection(): void
    {
        Assert::same($this->match('GET', 'v1/categories'), [CategoryController::class, 'index', []]);
    }

    public function capturesAresourceIdentifier(): void
    {
        Assert::same($this->match('GET', 'v1/categories/5'), [CategoryController::class, 'show', ['id' => '5']]);
    }

    public function capturesANestedParentIdentifier(): void
    {
        Assert::same(
            $this->match('GET', 'v1/categories/5/boards'),
            [BoardController::class, 'index', ['cat_id' => '5']]
        );
    }

    public function routesByHttpVerb(): void
    {
        Assert::same($this->match('POST', 'v1/categories'), [CategoryController::class, 'store', []]);
        Assert::same($this->match('POST', 'v1/posts'), [PostController::class, 'store', []]);
    }

    public function rejectsAnEmptyResourcePath(): void
    {
        $this->assertNotFound('GET', 'v1', 'No API resource specified');
    }

    public function rejectsAnUnsupportedVersion(): void
    {
        $this->assertNotFound('GET', 'v2/categories', 'Unsupported API version: v2');
    }

    public function rejectsAnUnknownEndpoint(): void
    {
        $this->assertNotFound('GET', 'v1/widgets', 'Unknown API endpoint: widgets');
    }

    public function reportsAmethodMismatchAsFourZeroFive(): void
    {
        $exception = $this->matchFailure('DELETE', 'v1/categories');

        Assert::same($exception->getStatusCode(), 405);
    }

    public function registersRoutesAddedThroughTheVerbHelpers(): void
    {
        Environment::reset();
        $router = new Router();

        $self = $router->post('widgets/{id}', 'Vendor\\WidgetController', 'act');

        Assert::same($self, $router);
        Assert::same(
            $this->matchWith($router, 'POST', 'v1/widgets/8'),
            ['Vendor\\WidgetController', 'act', ['id' => '8']]
        );
    }

    public function matchesLiteralsExactlyAndCapturesPlaceholders(): void
    {
        Assert::null($this->matchPattern('categories', ['categories', '5']));
        Assert::null($this->matchPattern('categories', ['boards']));
        Assert::same($this->matchPattern('categories/{id}', ['categories', '7']), ['id' => '7']);
        Assert::same(
            $this->matchPattern('boards/{board_id}/topics', ['boards', '3', 'topics']),
            ['board_id' => '3']
        );
    }

    #[DataProvider('defaultRoutes')]
    public function resolvesEveryDefaultRoute(string $method, string $path, string $class, string $action, array $params): void
    {
        Assert::same($this->match($method, $path), [$class, $action, $params]);
    }

    public function invokesTheRouteRegistrationHookWithTheRouter(): void
    {
        Environment::reset();
        $captured = null;

        $GLOBALS['api_routes_hook'] = static function ($router) use (&$captured): void {
            $captured = $router;
            $router->post('widgets', 'Vendor\\WidgetController', 'store');
        };

        $router = new Router();

        Assert::instanceOf($captured, Router::class);
        Assert::contains($GLOBALS['api_hook_calls'], 'integrate_api_routes');
        Assert::same($this->matchWith($router, 'POST', 'v1/widgets'), ['Vendor\\WidgetController', 'store', []]);
    }

    public function normalizesTheHttpVerbToUppercase(): void
    {
        Environment::reset();
        $router = new Router();
        $router->add('get', 'widgets', 'Vendor\\WidgetController', 'index');

        Assert::same($this->matchWith($router, 'GET', 'v1/widgets'), ['Vendor\\WidgetController', 'index', []]);
    }

    public function trimsSurroundingSlashesFromRoutePatterns(): void
    {
        Environment::reset();
        $router = new Router();
        $router->add('GET', '/widgets/', 'Vendor\\WidgetController', 'index');

        Assert::same($this->matchWith($router, 'GET', 'v1/widgets'), ['Vendor\\WidgetController', 'index', []]);
    }

    public static function defaultRoutes(): iterable
    {
        $cat    = CategoryController::class;
        $board  = BoardController::class;
        $member = MemberController::class;
        $topic  = TopicController::class;
        $post   = PostController::class;

        yield 'GET categories'            => ['GET', 'v1/categories', $cat, 'index', []];
        yield 'GET categories/{id}'       => ['GET', 'v1/categories/5', $cat, 'show', ['id' => '5']];
        yield 'GET category boards'       => ['GET', 'v1/categories/5/boards', $board, 'index', ['cat_id' => '5']];
        yield 'POST categories'           => ['POST', 'v1/categories', $cat, 'store', []];
        yield 'PUT categories/{id}'       => ['PUT', 'v1/categories/5', $cat, 'replace', ['id' => '5']];
        yield 'PATCH categories/{id}'     => ['PATCH', 'v1/categories/5', $cat, 'update', ['id' => '5']];
        yield 'DELETE categories/{id}'    => ['DELETE', 'v1/categories/5', $cat, 'destroy', ['id' => '5']];
        yield 'GET members'               => ['GET', 'v1/members', $member, 'index', []];
        yield 'GET members/{id}'          => ['GET', 'v1/members/5', $member, 'show', ['id' => '5']];
        yield 'POST members'              => ['POST', 'v1/members', $member, 'store', []];
        yield 'PUT members/{id}'          => ['PUT', 'v1/members/5', $member, 'replace', ['id' => '5']];
        yield 'PATCH members/{id}'        => ['PATCH', 'v1/members/5', $member, 'update', ['id' => '5']];
        yield 'DELETE members/{id}'       => ['DELETE', 'v1/members/5', $member, 'destroy', ['id' => '5']];
        yield 'GET boards'                => ['GET', 'v1/boards', $board, 'index', []];
        yield 'GET boards/{id}'           => ['GET', 'v1/boards/5', $board, 'show', ['id' => '5']];
        yield 'GET board topics'          => ['GET', 'v1/boards/5/topics', $topic, 'index', ['board_id' => '5']];
        yield 'POST boards'               => ['POST', 'v1/boards', $board, 'store', []];
        yield 'PUT boards/{id}'           => ['PUT', 'v1/boards/5', $board, 'replace', ['id' => '5']];
        yield 'PATCH boards/{id}'         => ['PATCH', 'v1/boards/5', $board, 'update', ['id' => '5']];
        yield 'DELETE boards/{id}'        => ['DELETE', 'v1/boards/5', $board, 'destroy', ['id' => '5']];
        yield 'GET topics'                => ['GET', 'v1/topics', $topic, 'index', []];
        yield 'GET topics/{id}'           => ['GET', 'v1/topics/5', $topic, 'show', ['id' => '5']];
        yield 'GET topic posts'           => ['GET', 'v1/topics/5/posts', $post, 'index', ['topic_id' => '5']];
        yield 'POST topics'               => ['POST', 'v1/topics', $topic, 'store', []];
        yield 'PUT topics/{id}'           => ['PUT', 'v1/topics/5', $topic, 'replace', ['id' => '5']];
        yield 'PATCH topics/{id}'         => ['PATCH', 'v1/topics/5', $topic, 'update', ['id' => '5']];
        yield 'DELETE topics/{id}'        => ['DELETE', 'v1/topics/5', $topic, 'destroy', ['id' => '5']];
        yield 'GET posts/{id}'            => ['GET', 'v1/posts/5', $post, 'show', ['id' => '5']];
        yield 'POST posts'                => ['POST', 'v1/posts', $post, 'store', []];
        yield 'PUT posts/{id}'            => ['PUT', 'v1/posts/5', $post, 'replace', ['id' => '5']];
        yield 'PATCH posts/{id}'          => ['PATCH', 'v1/posts/5', $post, 'update', ['id' => '5']];
        yield 'DELETE posts/{id}'         => ['DELETE', 'v1/posts/5', $post, 'destroy', ['id' => '5']];
    }

    private function match(string $method, string $path): array
    {
        Environment::reset();

        return $this->matchWith(new Router(), $method, $path);
    }

    private function matchWith(Router $router, string $method, string $path): array
    {
        $request = Environment::request($method, [], ['path' => $path]);

        $reflection = new ReflectionMethod(Router::class, 'match');
        $reflection->setAccessible(true);

        return $reflection->invoke($router, $request);
    }

    private function matchFailure(string $method, string $path): ApiException
    {
        try {
            $this->match($method, $path);
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected the route to be rejected');
    }

    private function assertNotFound(string $method, string $path, string $message): void
    {
        $exception = $this->matchFailure($method, $path);

        Assert::same($exception->getStatusCode(), 404);
        Assert::same($exception->getMessage(), $message);
    }

    private function matchPattern(string $pattern, array $segments): ?array
    {
        Environment::reset();

        $reflection = new ReflectionMethod(Router::class, 'matchPattern');
        $reflection->setAccessible(true);

        return $reflection->invoke(new Router(), $pattern, $segments);
    }
}
