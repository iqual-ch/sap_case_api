<?php

namespace Drupal\sap_case_api\Plugin\WebformHandler;

use Drupal\sap_case_api\Exception\CaseApiException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Submits webform submissions to the SAP Service Cloud Case API.
 *
 * The connection itself is configured once for the whole site, because a
 * single SAP tenant serves every contact form variant. This handler only
 * describes how one form's elements map onto the API schema.
 *
 * @WebformHandler(
 *   id = "sap_case_api",
 *   label = @Translation("SAP Service Cloud case"),
 *   category = @Translation("External"),
 *   description = @Translation("Creates a case in the SAP Service Cloud via the Formular Case Request API, retrying failed deliveries on cron."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class CaseApiWebformHandler extends WebformHandlerBase {

  /**
   * The name of the queue holding deliveries that must be retried.
   */
  public const QUEUE_NAME = 'sap_case_api_retry';

  /**
   * The log channel this integration writes to.
   */
  public const LOGGER_CHANNEL = 'sap_case_api';

  /**
   * Delay in seconds before each retry attempt.
   */
  protected const BACKOFF = [300, 900, 3600, 10800, 43200];

  /**
   * The Case API client.
   *
   * @var \Drupal\sap_case_api\CaseApiClient
   */
  protected $caseApiClient;

  /**
   * The payload builder.
   *
   * @var \Drupal\sap_case_api\CaseRequestBuilder
   */
  protected $requestBuilder;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->caseApiClient = $container->get('sap_case_api.client');
    $instance->requestBuilder = $container->get('sap_case_api.request_builder');
    $instance->queueFactory = $container->get('queue');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Logs to the module's own channel so that the integration can be followed
   * separately from general webform activity, and alongside the queue worker.
   */
  protected function getLogger($channel = self::LOGGER_CHANNEL) {
    return $this->loggerFactory->get($channel);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'contact_form_variant' => '',
      'subject' => '',
      'mapping' => $this->defaultMapping(),
      'attachment_elements' => '',
      'max_attempts' => 5,
      'debug' => FALSE,
    ];
  }

  /**
   * Returns an example mapping for a German language contact form.
   *
   * @return string
   *   An example mapping, to be adjusted to the form's own elements.
   */
  protected function defaultMapping(): string {
    return <<<'MAPPING'
customer.formOfAddress: anrede|form_of_address
customer.familyName: name
customer.givenName: vorname
customer.eMail: e_mail
customer.phoneNormalisedNumber: telefon
customer.streetName: strasse
customer.houseId: hausnummer
customer.postalCode: plz
customer.cityName: ort
case.content: bemerkungen
case.incidentDate: datum_des_vorfalls|date
case.incidentTime: uhrzeit|time
case.stopDescription: haltestelle
case.lineDescription: linie
case.vehicleNumber: fahrzeugnummer
case.direction: fahrtrichtung
case.responseRequested: antwort_erwunscht|boolean:Ich wünsche eine Antwort
MAPPING;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['connection_notice'] = [
      '#type' => 'webform_message',
      '#message_type' => 'info',
      '#message_message' => $this->t('The endpoint and credentials are configured once for the whole site under <a href=":url">Case API settings</a>.', [
        ':url' => Url::fromRoute('sap_case_api.settings')->toString(),
      ]),
    ];

    $form['case'] = [
      '#type' => 'details',
      '#title' => $this->t('Case'),
      '#open' => TRUE,
    ];
    $form['case']['contact_form_variant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact form variant'),
      '#description' => $this->t('Tells the API which form the case originates from. The accepted values are defined by the SAP tenant.'),
      '#default_value' => $this->configuration['contact_form_variant'],
      '#required' => TRUE,
    ];
    $form['case']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('Subject of the created case. The API requires a value.'),
      '#default_value' => $this->configuration['subject'],
      '#required' => TRUE,
    ];

    $form['mapping_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Field mapping'),
      '#open' => TRUE,
    ];
    $form['mapping_wrapper']['mapping'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mapping'),
      '#description' => $this->t('One line per field, written as <em>api.property: element_key</em>. Add <em>|date</em>, <em>|time</em>, <em>|form_of_address</em> or <em>|boolean:Value meaning yes</em> to convert a value. Lines starting with # are ignored. Empty values are omitted from the request and strings are truncated to the lengths the API allows.'),
      '#default_value' => $this->configuration['mapping'],
      '#rows' => 18,
      '#required' => TRUE,
    ];
    $form['mapping_wrapper']['attachment_elements'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Attachment elements'),
      '#description' => $this->t('Optional, comma separated keys of file upload elements. At most 5 files are sent, and only in the formats the API accepts.'),
      '#default_value' => $this->configuration['attachment_elements'],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];
    $form['advanced']['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum delivery attempts'),
      '#description' => $this->t('Includes the first attempt. Retries run on cron with an increasing delay; once they are exhausted the failure is logged as critical.'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => $this->configuration['max_attempts'],
    ];
    $form['advanced']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log the request payload'),
      '#description' => $this->t('Writes every payload to the log. The payload contains personal data, so enable this only while debugging.'),
      '#default_value' => $this->configuration['debug'],
      '#return_value' => TRUE,
    ];

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $mapping = $this->requestBuilder->parseMapping($form_state->getValue('mapping') ?? '');
    if (!$mapping) {
      $form_state->setErrorByName('mapping', $this->t('The mapping does not contain a single usable line.'));
      return;
    }

    $elements = $this->getWebform()->getElementsDecodedAndFlattened();
    $unknown = [];
    foreach ($mapping as $instruction) {
      if (!isset($elements[$instruction['element']])) {
        $unknown[] = $instruction['element'];
      }
    }

    if ($unknown) {
      $form_state->setErrorByName('mapping', $this->t('This form has no element named: @elements.', [
        '@elements' => implode(', ', array_unique($unknown)),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    // Only completed, newly created submissions open a case; drafts and later
    // edits must not create duplicates in the SAP Service Cloud.
    if ($update || $webform_submission->isDraft() || !$webform_submission->isCompleted()) {
      return;
    }

    try {
      $payload = $this->requestBuilder->build($webform_submission, $this->configuration);
    }
    catch (CaseApiException $e) {
      $this->getLogger()->error('@message', ['@message' => $e->getMessage()]);
      return;
    }

    if ($this->configuration['debug']) {
      $this->getLogger()->debug('Case API payload for submission @sid: <pre>@payload</pre>', [
        '@sid' => $webform_submission->id(),
        '@payload' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
      ]);
    }

    $this->deliver([
      'webform_id' => $this->getWebform()->id(),
      'handler_id' => $this->getHandlerId(),
      'sid' => $webform_submission->id(),
      'payload' => $payload,
      'attempts' => 0,
    ]);
  }

  /**
   * Delivers a queued or fresh payload, scheduling a retry when one may help.
   *
   * @param array $item
   *   The delivery item: webform_id, handler_id, sid, payload and attempts.
   *
   * @return bool
   *   TRUE if the case was created.
   */
  public function deliver(array $item): bool {
    $attempt = (int) $item['attempts'] + 1;

    try {
      $response = $this->caseApiClient->postCase($item['payload']);
      $this->getLogger()->info('Created case @display_id for submission @sid on attempt @attempt.', [
        '@display_id' => $response['displayId'] ?? ($response['caseId'] ?? 'unknown'),
        '@sid' => $item['sid'] ?? 'unknown',
        '@attempt' => $attempt,
      ]);
      return TRUE;
    }
    catch (CaseApiException $e) {
      $this->handleFailure($e, $item, $attempt);
      return FALSE;
    }
  }

  /**
   * Logs a failed delivery and queues a retry when one is warranted.
   *
   * @param \Drupal\sap_case_api\Exception\CaseApiException $e
   *   The failure.
   * @param array $item
   *   The delivery item.
   * @param int $attempt
   *   The attempt number that just failed.
   */
  protected function handleFailure(CaseApiException $e, array $item, int $attempt): void {
    $context = [
      '@sid' => $item['sid'] ?? 'unknown',
      '@attempt' => $attempt,
      '@message' => $e->getMessage(),
    ];

    if ($e->isPermanent()) {
      $this->getLogger()->error('Submission @sid was rejected by the Case API and will not be retried: @message', $context);
      return;
    }

    if ($attempt >= (int) $this->configuration['max_attempts']) {
      $this->getLogger()->critical('Submission @sid could not be delivered to the Case API after @attempt attempts and was given up on: @message', $context);
      return;
    }

    $delay = static::BACKOFF[min($attempt - 1, count(static::BACKOFF) - 1)];
    $item['attempts'] = $attempt;
    $item['not_before'] = $this->time->getRequestTime() + $delay;
    $this->queueFactory->get(static::QUEUE_NAME)->createItem($item);

    $this->getLogger()->warning('Attempt @attempt for submission @sid failed, retrying in @delay seconds: @message', $context + ['@delay' => $delay]);
  }

}
