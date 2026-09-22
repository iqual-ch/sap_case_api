<?php

namespace Drupal\Tests\sap_case_api\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Drupal\sap_case_api\CaseApiClient;
use Drupal\sap_case_api\Exception\CaseApiException;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how the client classifies Case API failures.
 *
 * The classification decides whether a submission is retried on cron or
 * dropped with a log entry. Getting it wrong either loses a customer enquiry
 * or retries a payload the API will never accept, so each status code that
 * behaves differently is pinned here.
 */
#[Group('sap_case_api')]
#[CoversClass(CaseApiClient::class)]
class CaseApiClientTest extends UnitTestCase {

  /**
   * Requests recorded by the mock handler.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * Builds a client whose HTTP responses are predetermined.
   *
   * @param array $responses
   *   Responses or exceptions the HTTP client returns in order.
   * @param \Drupal\oauth2_client\Service\Oauth2ClientServiceInterface|null $oauth
   *   The OAuth2 service to use, or NULL for one returning a valid token.
   *
   * @return \Drupal\sap_case_api\CaseApiClient
   *   The client under test.
   */
  protected function buildClient(array $responses, ?Oauth2ClientServiceInterface $oauth = NULL): CaseApiClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    if ($oauth === NULL) {
      $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
      $oauth->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'token-123']));
    }

    return new CaseApiClient(
      new Client(['handler' => $stack]),
      $oauth,
      $this->getConfigFactoryStub([
        'sap_case_api.settings' => [
          'endpoint_url' => 'https://example.test/http/SCV2-FormularIntegration/Inbound',
          'oauth2_client' => 'sap_case_api',
          'timeout' => 30,
        ],
      ])
    );
  }

  /**
   * A created case is returned to the caller and sent as authenticated JSON.
   */
  public function testSuccessfulPostReturnsTheDecodedResponse(): void {
    $client = $this->buildClient([
      new Response(201, [], json_encode([
        'caseId' => '0b2d1b1e-0000-4000-8000-000000000000',
        'displayId' => '4711',
        'status' => 'created',
      ])),
    ]);

    $response = $client->postCase(['requestId' => 'abc']);

    $this->assertSame('4711', $response['displayId']);

    $request = $this->history[0]['request'];
    $this->assertSame('Bearer token-123', $request->getHeaderLine('Authorization'));
    $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    $this->assertSame(['requestId' => 'abc'], json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * A 201 response reporting an error is never retried.
   *
   * SAP answers with HTTP 201 even when it rejects the case, so the status
   * field — not the status code — decides here.
   */
  public function testErrorStatusInBodyIsPermanent(): void {
    $client = $this->buildClient([
      new Response(201, [], json_encode(['caseId' => 'x', 'status' => 'error'])),
    ]);

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertTrue($e->isPermanent());
    }
  }

  /**
   * HTTP status codes are classified as retryable or not.
   */
  #[DataProvider('providerStatusCodes')]
  public function testStatusCodeClassification(int $status, bool $permanent): void {
    $client = $this->buildClient([
      new Response($status, [], 'failure details'),
    ]);

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertSame($permanent, $e->isPermanent(), sprintf('HTTP %d classification', $status));
    }
  }

  /**
   * Provides status codes and whether they must stop the delivery for good.
   *
   * @return array
   *   Test cases.
   */
  public static function providerStatusCodes(): array {
    return [
      'bad payload' => [400, TRUE],
      'forbidden' => [403, TRUE],
      'not found' => [404, TRUE],
      'request timeout' => [408, FALSE],
      'rate limited' => [429, FALSE],
      'server error' => [500, FALSE],
      'bad gateway' => [502, FALSE],
      'unavailable' => [503, FALSE],
    ];
  }

  /**
   * A rejected token is discarded and the request is attempted once more.
   *
   * Without this, a token that SAP revoked early would burn every retry.
   */
  public function testUnauthorizedClearsTheTokenAndRetriesOnce(): void {
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'token-123']));
    $oauth->expects($this->once())
      ->method('clearAccessToken')
      ->with('sap_case_api');

    $client = $this->buildClient([
      new Response(401, [], 'token expired'),
      new Response(201, [], json_encode(['caseId' => 'x', 'displayId' => '4712', 'status' => 'created'])),
    ], $oauth);

    $response = $client->postCase(['requestId' => 'abc']);

    $this->assertSame('4712', $response['displayId']);
    $this->assertCount(2, $this->history);
  }

  /**
   * A second 401 gives up rather than looping.
   */
  public function testRepeatedUnauthorizedFailsPermanently(): void {
    $client = $this->buildClient([
      new Response(401, [], 'token expired'),
      new Response(401, [], 'token expired'),
    ]);

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertTrue($e->isPermanent());
      $this->assertCount(2, $this->history);
    }
  }

  /**
   * An unreachable endpoint is retried later.
   */
  public function testConnectionFailureIsTransient(): void {
    $client = $this->buildClient([
      new ConnectException(
        'Connection timed out',
        new Request('POST', 'https://example.test')
      ),
    ]);

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertFalse($e->isPermanent());
    }
  }

  /**
   * A missing token stops the attempt without sending anything.
   */
  public function testMissingTokenIsTransientAndSendsNothing(): void {
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('getAccessToken')->willReturn(NULL);

    $client = $this->buildClient([], $oauth);

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertFalse($e->isPermanent());
      $this->assertCount(0, $this->history);
    }
  }

  /**
   * An unconfigured endpoint fails permanently instead of queueing forever.
   */
  public function testUnconfiguredEndpointIsPermanent(): void {
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $client = new CaseApiClient(
      new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
      $oauth,
      $this->getConfigFactoryStub([
        'sap_case_api.settings' => [
          'endpoint_url' => '',
          'oauth2_client' => 'sap_case_api',
        ],
      ])
    );

    try {
      $client->postCase(['requestId' => 'abc']);
      $this->fail('Expected a CaseApiException.');
    }
    catch (CaseApiException $e) {
      $this->assertTrue($e->isPermanent());
    }
  }

}
