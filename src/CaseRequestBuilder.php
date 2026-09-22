<?php

namespace Drupal\sap_case_api;

use Drupal\sap_case_api\Exception\CaseApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a FormularCaseRequest payload from a webform submission.
 *
 * The mapping between webform elements and the API schema is configured per
 * handler, so the same handler serves every contact form variant the API
 * accepts. Length limits and enum translations come from the API contract and
 * are applied here rather than being left to each form's configuration.
 */
class CaseRequestBuilder {

  /**
   * Maximum string length per API property path, from the OpenAPI schema.
   */
  protected const MAX_LENGTHS = [
    'requestId' => 40,
    'customer.formOfAddress' => 4,
    'customer.familyName' => 40,
    'customer.givenName' => 40,
    'customer.eMail' => 255,
    'customer.phoneNormalisedNumber' => 40,
    'customer.streetName' => 80,
    'customer.houseId' => 10,
    'customer.postalCode' => 10,
    'customer.cityName' => 40,
    'case.content' => 5000,
    'case.vehicleNumber' => 40,
    'case.lineDescription' => 40,
    'case.stopDescription' => 40,
    'case.direction' => 40,
    'case.itemDescription' => 40,
  ];

  /**
   * Property paths the API rejects the request without.
   */
  protected const REQUIRED_PATHS = [
    'customer.familyName',
    'customer.givenName',
    'customer.eMail',
    'case.subject',
    'case.content',
  ];

  /**
   * SAP form-of-address codes keyed by the German salutation they represent.
   */
  protected const FORM_OF_ADDRESS = [
    'frau' => '0001',
    'herr' => '0002',
  ];

  /**
   * The code used for any salutation that is neither "Frau" nor "Herr".
   */
  protected const FORM_OF_ADDRESS_DIVERSE = 'Z001';

  /**
   * MIME types the API accepts for attachments.
   */
  protected const ALLOWED_MIME_TYPES = [
    'text/csv',
    'image/heic',
    'image/png',
    'image/jpeg',
    'application/zip',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/rtf',
    'application/pdf',
    'application/msword',
  ];

  /**
   * Maximum number of attachments the API accepts per request.
   */
  protected const MAX_ATTACHMENTS = 5;

  /**
   * Constructs a CaseRequestBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load attached files.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Builds the payload for a submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission to convert.
   * @param array $settings
   *   The handler settings: contact_form_variant, subject, mapping and
   *   attachment_elements.
   *
   * @return array
   *   The FormularCaseRequest payload.
   *
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If a property the API requires cannot be resolved.
   */
  public function build(WebformSubmissionInterface $webform_submission, array $settings): array {
    $payload = [];

    // The submission UUID doubles as the idempotency key: a retried delivery
    // carries the same requestId, so SAP can recognise the duplicate.
    $this->setPath($payload, 'requestId', $webform_submission->uuid());
    $this->setPath($payload, 'contactFormVariant', $settings['contact_form_variant'] ?? '');
    $this->setPath($payload, 'reportedOn', $this->formatReportedOn($webform_submission));
    $this->setPath($payload, 'case.subject', $settings['subject'] ?? '');

    foreach ($this->parseMapping($settings['mapping'] ?? '') as $path => $instruction) {
      $value = $this->resolveValue($webform_submission, $instruction);
      $this->setPath($payload, $path, $value);
    }

    $attachments = $this->buildAttachments($webform_submission, $settings['attachment_elements'] ?? '');
    if ($attachments) {
      $payload['attachments'] = $attachments;
    }

    $this->assertRequired($payload, $webform_submission);

    return $payload;
  }

  /**
   * Parses the configured mapping into property path => instruction pairs.
   *
   * Each line reads "api.property: element_key" with an optional transform,
   * for example "case.responseRequested: antwort_erwunscht|boolean:Ja".
   *
   * @param string $mapping
   *   The raw mapping configuration.
   *
   * @return array
   *   Instructions keyed by API property path, each with an "element" and
   *   optional "transform" and "argument".
   */
  public function parseMapping($mapping): array {
    $instructions = [];

    foreach (preg_split('/\R/', (string) $mapping) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      [$path, $target] = array_pad(explode(':', $line, 2), 2, '');
      $path = trim($path);
      $target = trim($target);
      if ($path === '' || $target === '') {
        continue;
      }

      [$element, $transform] = array_pad(explode('|', $target, 2), 2, '');
      [$transform, $argument] = array_pad(explode(':', (string) $transform, 2), 2, NULL);

      $instructions[$path] = [
        'element' => trim($element),
        'transform' => trim((string) $transform),
        'argument' => $argument === NULL ? NULL : trim($argument),
      ];
    }

    return $instructions;
  }

  /**
   * Resolves and transforms a single submission value.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission.
   * @param array $instruction
   *   The mapping instruction.
   *
   * @return mixed
   *   The transformed value, or NULL when the element is empty.
   */
  protected function resolveValue(WebformSubmissionInterface $webform_submission, array $instruction) {
    $value = $webform_submission->getElementData($instruction['element']);

    // Composite elements such as webform_email_confirm store their result in
    // an array; the confirmation copy is not part of the payload.
    if (is_array($value)) {
      $value = reset($value);
    }

    if ($value === NULL || $value === '') {
      return NULL;
    }

    return match ($instruction['transform']) {
      'form_of_address' => $this->toFormOfAddress($value),
      'boolean' => $this->toBoolean($value, $instruction['argument']),
      'date' => $this->reformatDateTime($value, 'Y-m-d'),
      'time' => $this->reformatDateTime($value, 'H:i:s'),
      default => (string) $value,
    };
  }

  /**
   * Translates a salutation into the SAP form-of-address code.
   *
   * @param string $value
   *   The submitted salutation.
   *
   * @return string
   *   One of the codes the API accepts.
   */
  protected function toFormOfAddress($value): string {
    foreach (static::FORM_OF_ADDRESS as $needle => $code) {
      if (str_contains(mb_strtolower((string) $value), $needle)) {
        return $code;
      }
    }

    return static::FORM_OF_ADDRESS_DIVERSE;
  }

  /**
   * Converts a submitted value into a boolean.
   *
   * @param mixed $value
   *   The submitted value.
   * @param string|null $true_value
   *   The exact value meaning TRUE. When omitted, common affirmative strings
   *   are recognised and German negations are treated as FALSE.
   *
   * @return bool
   *   The boolean value.
   */
  protected function toBoolean($value, $true_value): bool {
    if ($true_value !== NULL && $true_value !== '') {
      return (string) $value === $true_value;
    }

    $normalised = mb_strtolower(trim((string) $value));
    if (in_array($normalised, ['1', 'true', 'yes', 'ja'], TRUE)) {
      return TRUE;
    }
    if (in_array($normalised, ['0', 'false', 'no', 'nein'], TRUE)) {
      return FALSE;
    }

    return !str_contains($normalised, 'kein');
  }

  /**
   * Reformats a submitted date or time into the format the API expects.
   *
   * @param string $value
   *   The submitted value.
   * @param string $format
   *   The target date format.
   *
   * @return string|null
   *   The reformatted value, or NULL if it could not be parsed.
   */
  protected function reformatDateTime($value, string $format): ?string {
    $timestamp = strtotime((string) $value);

    return $timestamp === FALSE ? NULL : date($format, $timestamp);
  }

  /**
   * Formats the submission's creation time as an RFC3339 timestamp.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission.
   *
   * @return string
   *   The formatted timestamp.
   */
  protected function formatReportedOn(WebformSubmissionInterface $webform_submission): string {
    $created = $webform_submission->getCreatedTime() ?: $this->time->getRequestTime();

    return date(DATE_ATOM, $created);
  }

  /**
   * Builds the attachment list from the configured file elements.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission.
   * @param string $element_keys
   *   Comma or newline separated file element keys.
   *
   * @return array
   *   The attachment structures, capped at the API's limit.
   */
  protected function buildAttachments(WebformSubmissionInterface $webform_submission, $element_keys): array {
    $keys = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $element_keys)));
    if (!$keys) {
      return [];
    }

    $fids = [];
    foreach ($keys as $key) {
      $value = $webform_submission->getElementData($key);
      foreach ((array) $value as $fid) {
        if ($fid) {
          $fids[] = $fid;
        }
      }
    }

    if (!$fids) {
      return [];
    }

    $attachments = [];
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($fids);
    foreach ($files as $file) {
      if (count($attachments) >= static::MAX_ATTACHMENTS) {
        $this->logger->warning('Submission @sid has more than @max attachments; the remaining files were not sent.', [
          '@sid' => $webform_submission->id(),
          '@max' => static::MAX_ATTACHMENTS,
        ]);
        break;
      }

      $mime_type = $file->getMimeType();
      if (!in_array($mime_type, static::ALLOWED_MIME_TYPES, TRUE)) {
        $this->logger->warning('Attachment @name of submission @sid was skipped: the API does not accept @mime.', [
          '@name' => $file->getFilename(),
          '@sid' => $webform_submission->id(),
          '@mime' => $mime_type,
        ]);
        continue;
      }

      $contents = @file_get_contents($file->getFileUri());
      if ($contents === FALSE) {
        $this->logger->warning('Attachment @name of submission @sid could not be read.', [
          '@name' => $file->getFilename(),
          '@sid' => $webform_submission->id(),
        ]);
        continue;
      }

      $attachments[] = [
        'fileName' => $file->getFilename(),
        'mimeType' => $mime_type,
        'content' => base64_encode($contents),
      ];
    }

    return $attachments;
  }

  /**
   * Writes a value into the payload, truncating and skipping empties.
   *
   * @param array $payload
   *   The payload being built, by reference.
   * @param string $path
   *   The dot-separated property path.
   * @param mixed $value
   *   The value to write.
   */
  protected function setPath(array &$payload, string $path, $value): void {
    if ($value === NULL || $value === '') {
      return;
    }

    if (is_string($value) && isset(static::MAX_LENGTHS[$path])) {
      $value = mb_substr($value, 0, static::MAX_LENGTHS[$path]);
    }

    $target = &$payload;
    foreach (explode('.', $path) as $segment) {
      if (!isset($target[$segment]) || !is_array($target[$segment])) {
        $target[$segment] = [];
      }
      $target = &$target[$segment];
    }
    $target = $value;
  }

  /**
   * Verifies that every property the API requires is present.
   *
   * @param array $payload
   *   The built payload.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The submission, used for the error message.
   *
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If a required property is missing.
   */
  protected function assertRequired(array $payload, WebformSubmissionInterface $webform_submission): void {
    $missing = [];
    foreach (static::REQUIRED_PATHS as $path) {
      $value = $payload;
      foreach (explode('.', $path) as $segment) {
        $value = is_array($value) && isset($value[$segment]) ? $value[$segment] : NULL;
      }
      if ($value === NULL || $value === '') {
        $missing[] = $path;
      }
    }

    if ($missing) {
      throw CaseApiException::permanent(sprintf(
        'Submission %s cannot be sent: the mapping produced no value for %s.',
        $webform_submission->uuid(),
        implode(', ', $missing)
      ));
    }
  }

}
