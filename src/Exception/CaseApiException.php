<?php

namespace Drupal\sap_case_api\Exception;

/**
 * Thrown when a Formular Case Request could not be delivered.
 *
 * Failures are classified as either transient (worth retrying — network
 * problems, throttling, server errors) or permanent (retrying would produce
 * the same result — invalid payloads, revoked credentials).
 */
class CaseApiException extends \RuntimeException {

  /**
   * Whether retrying the request would be pointless.
   *
   * @var bool
   */
  protected bool $permanent;

  /**
   * Constructs a CaseApiException.
   *
   * @param string $message
   *   The exception message.
   * @param bool $permanent
   *   TRUE if the request must not be retried.
   * @param \Throwable|null $previous
   *   The previous exception, if any.
   */
  public function __construct($message, bool $permanent = FALSE, ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
    $this->permanent = $permanent;
  }

  /**
   * Creates a permanent failure that must not be retried.
   *
   * @param string $message
   *   The exception message.
   * @param \Throwable|null $previous
   *   The previous exception, if any.
   *
   * @return static
   *   The exception.
   */
  public static function permanent($message, ?\Throwable $previous = NULL) {
    return new static($message, TRUE, $previous);
  }

  /**
   * Creates a transient failure that should be retried later.
   *
   * @param string $message
   *   The exception message.
   * @param \Throwable|null $previous
   *   The previous exception, if any.
   *
   * @return static
   *   The exception.
   */
  public static function transient($message, ?\Throwable $previous = NULL) {
    return new static($message, FALSE, $previous);
  }

  /**
   * Returns whether the failure is permanent.
   *
   * @return bool
   *   TRUE if retrying would be pointless.
   */
  public function isPermanent(): bool {
    return $this->permanent;
  }

}
