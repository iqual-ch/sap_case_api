<?php

namespace Drupal\sap_case_api\Plugin\QueueWorker;

use Drupal\sap_case_api\Plugin\WebformHandler\CaseApiWebformHandler;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries Case API deliveries that failed for a transient reason.
 *
 * Items carry the payload that was built at submission time, so a retry sends
 * exactly what the first attempt sent — including the requestId, which lets
 * the API recognise a duplicate.
 */
#[QueueWorker(
  id: CaseApiWebformHandler::QUEUE_NAME,
  title: new TranslatableMarkup('SAP Service Cloud Case API retry'),
  cron: ['time' => 30],
)]
class CaseApiRetryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a CaseApiRetryWorker.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('datetime.time'),
      $container->get('logger.channel.sap_case_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (!is_array($data) || empty($data['payload']) || empty($data['webform_id'])) {
      $this->logger->error('Discarded a malformed Case API retry item.');
      return;
    }

    // The backoff delay is carried on the item rather than enforced by the
    // queue, so put it back untouched until its delay has elapsed.
    if (!empty($data['not_before']) && $data['not_before'] > $this->time->getRequestTime()) {
      $this->queueFactory->get(CaseApiWebformHandler::QUEUE_NAME)->createItem($data);
      return;
    }

    $handler = $this->loadHandler($data);
    if (!$handler) {
      return;
    }

    $handler->deliver($data);
  }

  /**
   * Loads the handler that produced a queue item.
   *
   * @param array $data
   *   The queue item.
   *
   * @return \Drupal\sap_case_api\Plugin\WebformHandler\CaseApiWebformHandler|null
   *   The handler, or NULL if it is gone or disabled.
   */
  protected function loadHandler(array $data): ?CaseApiWebformHandler {
    $webform = $this->entityTypeManager->getStorage('webform')->load($data['webform_id']);
    if (!$webform) {
      $this->logger->error('Dropped a retry for submission @sid: webform @webform no longer exists.', [
        '@sid' => $data['sid'] ?? 'unknown',
        '@webform' => $data['webform_id'],
      ]);
      return NULL;
    }

    $handlers = $webform->getHandlers();
    $handler_id = $data['handler_id'] ?? '';
    if (!$handlers->has($handler_id)) {
      $this->logger->error('Dropped a retry for submission @sid: handler @handler is no longer configured on webform @webform.', [
        '@sid' => $data['sid'] ?? 'unknown',
        '@handler' => $handler_id,
        '@webform' => $data['webform_id'],
      ]);
      return NULL;
    }

    $handler = $handlers->get($handler_id);
    if (!$handler instanceof CaseApiWebformHandler || !$handler->isEnabled()) {
      $this->logger->warning('Dropped a retry for submission @sid: handler @handler is disabled.', [
        '@sid' => $data['sid'] ?? 'unknown',
        '@handler' => $handler_id,
      ]);
      return NULL;
    }

    return $handler;
  }

}
