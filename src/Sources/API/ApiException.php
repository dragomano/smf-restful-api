<?php declare(strict_types=1);

/**
 * ApiException.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API;

use RuntimeException;

if (! defined('SMF'))
	die('No direct access...');

/**
 * An exception carrying an HTTP status code and an optional machine-readable
 * error identifier. The Router turns it into a JSON error response.
 */
class ApiException extends RuntimeException
{
	/** @var string Short machine-readable error slug (e.g. "not_found"). */
	protected string $errorCode;

	public function __construct(
		/** HTTP status code to send to the client. */
		protected int $statusCode,
		string $message,
		string $errorCode = '',
		/** Extra details attached to the error payload. */
		protected array $details = []
	) {
		parent::__construct($message);

		$this->errorCode = $errorCode !== '' ? $errorCode : self::slugFor($this->statusCode);
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	public function getDetails(): array
	{
		return $this->details;
	}

	public static function badRequest(string $message, array $details = []): self
	{
		return new self(400, $message, 'bad_request', $details);
	}

	public static function unauthorized(string $message = 'Authentication required'): self
	{
		return new self(401, $message, 'unauthorized');
	}

	public static function forbidden(string $message = 'Access denied'): self
	{
		return new self(403, $message, 'forbidden');
	}

	public static function notFound(string $message = 'Resource not found'): self
	{
		return new self(404, $message, 'not_found');
	}

	public static function methodNotAllowed(string $message = 'Method not allowed'): self
	{
		return new self(405, $message, 'method_not_allowed');
	}

	/**
	 * Fallback slug derived from the status code when none is supplied.
	 */
	private static function slugFor(int $statusCode): string
	{
		$map = [
			400 => 'bad_request',
			401 => 'unauthorized',
			403 => 'forbidden',
			404 => 'not_found',
			405 => 'method_not_allowed',
			429 => 'too_many_requests',
			500 => 'internal_error',
		];

		return $map[$statusCode] ?? 'error';
	}
}
