<?php

namespace Drupal\Tests\sap_case_api\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the delivery and retry cycle end to end.
 *
 * A submission that cannot be delivered must survive until the API is back,
 * without the visitor ever noticing and without duplicating the case. These
 * tests exercise the real save pipeline, the queue, and the cron worker.
 */
#[Group('sap_case_api')]
class CaseApiRetryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path',
    'path_alias',
    'field',
    'webform',
    'dblog',
    'key',
    'oauth2_client',
    'sap_case_api',
  ];

  /**
   * Responses the mocked HTTP client returns, in order.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $httpResponses;

  /**
   * Requests the mocked HTTP client received.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * The webform carrying the handler.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['webform', 'sap_case_api']);

    $this->config('sap_case_api.settings')
      ->set('endpoint_url', 'https://example.test/Inbound')
      ->set('oauth2_client', 'sap_case_api')
      ->save();

    $this->httpResponses = new MockHandler();
    $stack = HandlerStack::create($this->httpResponses);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'token-123']));
    $this->container->set('oauth2_client.service', $oauth);

    $this->webform = Webform::create([
      'id' => 'kontakt_test',
      'elements' => Yaml::encode([
        'name' => ['#type' => 'textfield'],
        'vorname' => ['#type' => 'textfield'],
        'e_mail' => ['#type' => 'email'],
        'bemerkungen' => ['#type' => 'textarea'],
      ]),
    ]);
    $this->webform->save();

    $handler = \Drupal::service('plugin.manager.webform.handler')
      ->createInstance('sap_case_api');
    // setConfiguration() replaces the whole array, so the identity of the
    // handler is declared here rather than through the individual setters.
    $handler->setConfiguration([
      'handler_id' => 'sap_case_api',
      'label' => 'SAP Service Cloud case',
      'status' => TRUE,
      'weight' => 1,
      'settings' => [
        'contact_form_variant' => 'Example variant',
        'subject' => 'Contact form',
        'mapping' => implode("\n", [
          'customer.familyName: name',
          'customer.givenName: vorname',
          'customer.eMail: e_mail',
          'case.content: bemerkungen',
        ]),
        'attachment_elements' => '',
        'max_attempts' => 3,
        'debug' => FALSE,
      ],
    ]);
    $this->webform->addWebformHandler($handler);
    $this->webform->save();
  }

  /**
   * Submits the test form.
   */
  protected function submit(): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => $this->webform->id(),
      'in_draft' => FALSE,
      'completed' => \Drupal::time()->getRequestTime(),
      'data' => [
        'name' => 'Muster',
        'vorname' => 'Erika',
        'e_mail' => 'erika@example.com',
        'bemerkungen' => 'Eine Rückmeldung.',
      ],
    ]);
    $submission->save();

    return $submission;
  }

  /**
   * Returns the retry queue.
   */
  protected function queue() {
    return \Drupal::queue('sap_case_api_retry');
  }

  /**
   * Runs the queue worker over every currently queued item once.
   */
  protected function runQueue(): void {
    $queue = $this->queue();
    $worker = \Drupal::service('plugin.manager.queue_worker')
      ->createInstance('sap_case_api_retry');

    $pending = $queue->numberOfItems();
    for ($i = 0; $i < $pending; $i++) {
      $item = $queue->claimItem();
      if (!$item) {
        break;
      }
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * A successful delivery sends the payload once and queues nothing.
   */
  public function testSuccessfulDeliveryQueuesNothing(): void {
    $this->httpResponses->append(new Response(201, [], json_encode([
      'caseId' => 'abc',
      'displayId' => '4711',
      'status' => 'created',
    ])));

    $this->submit();

    $this->assertCount(1, $this->history);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * Deliveries are logged to the module's own channel.
   *
   * Operators filter the log by this channel, so a handler that logged under
   * the generic webform channel would make failures effectively invisible.
   */
  public function testDeliveryIsLoggedToTheModuleChannel(): void {
    $this->httpResponses->append(new Response(201, [], json_encode([
      'caseId' => 'abc',
      'displayId' => '4711',
      'status' => 'created',
    ])));

    $this->submit();

    $types = \Drupal::database()
      ->query('SELECT DISTINCT type FROM {watchdog}')
      ->fetchCol();

    $this->assertContains('sap_case_api', $types);
  }

  /**
   * A server error queues the submission for a later retry.
   */
  public function testTransientFailureIsQueued(): void {
    $this->httpResponses->append(new Response(503, [], 'unavailable'));

    $submission = $this->submit();

    $this->assertSame(1, $this->queue()->numberOfItems());

    $item = $this->queue()->claimItem();
    $this->assertSame(1, $item->data['attempts']);
    $this->assertSame($this->webform->id(), $item->data['webform_id']);
    $this->assertSame('sap_case_api', $item->data['handler_id']);
    $this->assertGreaterThan(\Drupal::time()->getRequestTime(), $item->data['not_before']);
    $this->assertSame($submission->uuid(), $item->data['payload']['requestId']);
  }

  /**
   * A rejected payload is not retried.
   */
  public function testPermanentFailureIsNotQueued(): void {
    $this->httpResponses->append(new Response(400, [], 'invalid payload'));

    $this->submit();

    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * A queued retry that succeeds resends the identical payload and stops.
   *
   * Resending the same requestId is what lets the API recognise the retry as
   * a duplicate rather than opening a second case.
   */
  public function testQueuedRetrySucceedsWithTheSameRequestId(): void {
    $this->httpResponses->append(new Response(503, [], 'unavailable'));
    $submission = $this->submit();

    // Make the backoff delay elapse.
    $item = $this->queue()->claimItem();
    $data = $item->data;
    $this->queue()->deleteItem($item);
    $data['not_before'] = \Drupal::time()->getRequestTime() - 1;
    $this->queue()->createItem($data);

    $this->httpResponses->append(new Response(201, [], json_encode([
      'caseId' => 'abc',
      'displayId' => '4711',
      'status' => 'created',
    ])));
    $this->runQueue();

    $this->assertCount(2, $this->history);
    $this->assertSame(0, $this->queue()->numberOfItems());

    $first = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $second = json_decode((string) $this->history[1]['request']->getBody(), TRUE);
    $this->assertSame($submission->uuid(), $second['requestId']);
    $this->assertSame($first, $second);
  }

  /**
   * An item whose backoff has not elapsed is put back without a request.
   */
  public function testItemIsNotSentBeforeItsBackoffElapses(): void {
    $this->httpResponses->append(new Response(503, [], 'unavailable'));
    $this->submit();

    $this->runQueue();

    // Only the original attempt was made; the item is still waiting.
    $this->assertCount(1, $this->history);
    $this->assertSame(1, $this->queue()->numberOfItems());
  }

  /**
   * Retries stop once the configured maximum is reached.
   */
  public function testRetriesStopAtTheConfiguredMaximum(): void {
    $this->httpResponses->append(new Response(503, [], 'unavailable'));
    $this->submit();

    // Attempts 2 and 3 of a maximum of 3.
    for ($i = 0; $i < 2; $i++) {
      $item = $this->queue()->claimItem();
      $this->assertNotFalse($item, 'A retry was queued.');
      $data = $item->data;
      $this->queue()->deleteItem($item);
      $data['not_before'] = \Drupal::time()->getRequestTime() - 1;
      $this->queue()->createItem($data);

      $this->httpResponses->append(new Response(503, [], 'unavailable'));
      $this->runQueue();
    }

    $this->assertCount(3, $this->history);
    $this->assertSame(0, $this->queue()->numberOfItems(), 'The delivery was given up on.');
  }

}
