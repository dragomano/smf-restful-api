<?php

declare(strict_types=1);

use SMF\API\ApiException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(ApiException::class)]
final class ApiExceptionTest
{
    public function badRequestCarriesStatusSlugAndDetails(): void
    {
        $exception = ApiException::badRequest('Bad input', ['errors' => ['name']]);

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getErrorCode(), 'bad_request');
        Assert::same($exception->getMessage(), 'Bad input');
        Assert::same($exception->getDetails(), ['errors' => ['name']]);
    }

    #[DataSet(['unauthorized', 401, 'unauthorized'], '401 unauthorized')]
    #[DataSet(['forbidden', 403, 'forbidden'], '403 forbidden')]
    #[DataSet(['notFound', 404, 'not_found'], '404 not found')]
    #[DataSet(['methodNotAllowed', 405, 'method_not_allowed'], '405 method not allowed')]
    public function factoriesUseTheMatchingStatusAndSlug(string $factory, int $status, string $slug): void
    {
        $exception = ApiException::$factory();

        Assert::same($exception->getStatusCode(), $status);
        Assert::same($exception->getErrorCode(), $slug);
        Assert::same($exception->getDetails(), []);
    }

    public function keepsAnExplicitErrorCode(): void
    {
        $exception = new ApiException(400, 'Nope', 'validation_failed');

        Assert::same($exception->getErrorCode(), 'validation_failed');
    }

    #[DataSet([400, 'bad_request'], 'bad request maps to its slug')]
    #[DataSet([401, 'unauthorized'], 'unauthorized maps to its slug')]
    #[DataSet([403, 'forbidden'], 'forbidden maps to its slug')]
    #[DataSet([404, 'not_found'], 'not found maps to its slug')]
    #[DataSet([405, 'method_not_allowed'], 'method not allowed maps to its slug')]
    #[DataSet([429, 'too_many_requests'], 'rate limit maps to its slug')]
    #[DataSet([500, 'internal_error'], 'server error maps to its slug')]
    #[DataSet([418, 'error'], 'an unmapped status falls back to a generic slug')]
    public function derivesTheSlugFromTheStatusWhenNoneGiven(int $status, string $expectedSlug): void
    {
        $exception = new ApiException($status, 'Boom');

        Assert::same($exception->getErrorCode(), $expectedSlug);
    }

    public function isThrowableAsARuntimeException(): void
    {
        Assert::instanceOf(ApiException::notFound(), RuntimeException::class);
    }
}
