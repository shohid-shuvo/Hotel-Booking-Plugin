<?php
/**
 * API Result Wrapper.
 *
 * @package ARC\HotelCore\Api
 */

namespace ARC\HotelCore\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard API Result representation.
 */
class Api_Result {

	/**
	 * Whether the request succeeded.
	 *
	 * @var bool
	 */
	protected bool $succeeded;

	/**
	 * Response payload data.
	 *
	 * @var mixed
	 */
	protected mixed $data;

	/**
	 * Backend or internal error code.
	 *
	 * @var string
	 */
	protected string $error_code;

	/**
	 * Human-readable message.
	 *
	 * @var string
	 */
	protected string $message;

	/**
	 * HTTP response status code.
	 *
	 * @var int
	 */
	protected int $http_status;

	/**
	 * Raw response data.
	 *
	 * @var array
	 */
	protected array $raw;

	/**
	 * Constructor.
	 *
	 * @param bool   $succeeded   Whether succeeded.
	 * @param mixed  $data        Data payload.
	 * @param string $error_code  Error code.
	 * @param string $message     Message.
	 * @param int    $http_status HTTP status code.
	 * @param array  $raw         Raw response array.
	 */
	public function __construct( bool $succeeded, mixed $data = null, string $error_code = '', string $message = '', int $http_status = 200, array $raw = [] ) {
		$this->succeeded   = $succeeded;
		$this->data        = $data;
		$this->error_code  = $error_code;
		$this->message     = $message;
		$this->http_status = $http_status;
		$this->raw         = $raw;
	}

	/**
	 * Create a successful result instance.
	 *
	 * @param mixed  $data        Payload data.
	 * @param string $message     Success message.
	 * @param int    $http_status HTTP status.
	 * @param array  $raw         Raw data.
	 * @return self
	 */
	public static function success( mixed $data, string $message = '', int $http_status = 200, array $raw = [] ): self {
		return new self( true, $data, '', $message, $http_status, $raw );
	}

	/**
	 * Create an error result instance.
	 *
	 * @param string $error_code  Error code.
	 * @param string $message     Error message.
	 * @param int    $http_status HTTP status.
	 * @param array  $raw         Raw data.
	 * @param mixed  $data        Optional partial data.
	 * @return self
	 */
	public static function error( string $error_code, string $message, int $http_status = 400, array $raw = [], mixed $data = null ): self {
		return new self( false, $data, $error_code, $message, $http_status, $raw );
	}

	/**
	 * Check if the call succeeded.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->succeeded;
	}

	/**
	 * Get response data.
	 *
	 * @return mixed
	 */
	public function data(): mixed {
		return $this->data;
	}

	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get message.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Get HTTP status code.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Get raw array representation.
	 *
	 * @return array
	 */
	public function raw(): array {
		return $this->raw;
	}
}
