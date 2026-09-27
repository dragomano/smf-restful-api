<?php declare(strict_types=1);

/**
 * Response.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Builds and emits a JSON HTTP response, then stops SMF from appending its
 * own template output.
 */
class Response
{
	private const REASON_PHRASES = [
		200 => 'OK',
		201 => 'Created',
		204 => 'No Content',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		503 => 'Service Unavailable',
	];

	private int $status = 200;

	/** @var array<string, string> */
	private array $headers = [];

	private mixed $payload = null;

	public function status(int $code): self
	{
		$this->status = $code;

		return $this;
	}

	public function header(string $name, string $value): self
	{
		$this->headers[$name] = $value;

		return $this;
	}

	public function data(mixed $data): self
	{
		$this->payload = ['data' => $data];

		return $this;
	}

	/**
	 * A collection response with pagination metadata.
	 */
	public function collection(array $items, int $total, int $limit, int $offset): self
	{
		$this->payload = [
			'data' => $items,
			'meta' => [
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
				'count'  => count($items),
			],
		];

		return $this;
	}

	public function error(int $code, string $message, string $errorCode, array $details = []): self
	{
		$this->status = $code;

		$this->payload = [
			'error' => array_filter([
				'code'    => $errorCode,
				'message' => $message,
				'details' => $details,
			], static fn ($v) => $v !== [] && $v !== null),
		];

		return $this;
	}

	/**
	 * Emit headers and body, then end the request so SMF's index.php does not
	 * wrap the JSON in a theme. Never returns.
	 */
	public function send(): void
	{
		// Discard any buffered template output opened by SMF's index.php.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		// Let mods adjust the payload, status or headers before output
		// (CORS, rate-limit headers, response envelopes, ...).
		call_integration_hook('integrate_api_pre_send', [&$this->payload, &$this->status, &$this->headers]);

		$reason = self::REASON_PHRASES[$this->status] ?? 'Unknown';

		if (! headers_sent()) {
			header('HTTP/1.1 ' . $this->status . ' ' . $reason);
			header('Content-Type: application/json; charset=utf-8');
			header('X-Content-Type-Options: nosniff');

			foreach ($this->headers as $name => $value) {
				header($name . ': ' . $value);
			}
		}

		if ($this->status !== 204) {
			echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		exit;
	}
}
