<?php

namespace Drupal\sap_case_api;

use Drupal\sap_case_api\Exception\CaseApiException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;

/**
 * Sends Formular Case Requests to the SAP Service Cloud integration endpoint.
 *
 * Authentication is delegated to the OAuth2 Client module, which performs the
 * client credentials grant and stores the resulting token. This class only
 * adds the bearer token to the request and classifies failures, so the caller
 * can decide whether a delivery is worth retrying.
 */
class CaseApiClient {

  /**
   * Constructs a CaseApiClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\oauth2_client\Service\Oauth2ClientServiceInterface $oauth2Client
   *   The OAuth2 client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected Oauth2ClientServiceInterface $oauth2Client,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Posts a Formular Case Request and returns the decoded response.
   *
   * @param array $payload
   *   The FormularCaseRequest payload.
   *
   * @return array
   *   The decoded FormularCaseResponse.
   *
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If the case could not be created.
   */
  public function postCase(array $payload): array {
    $settings = $this->configFactory->get('sap_case_api.settings');
    $endpoint = (string) $settings->get('endpoint_url');
    $oauth2_client = (string) $settings->get('oauth2_client');
    $timeout = (int) ($settings->get('timeout') ?: 30);

    if ($endpoint === '' || $oauth2_client === '') {
      throw CaseApiException::permanent('The Case API endpoint or OAuth2 client is not configured.');
    }

    try {
      $response = $this->request($payload, $endpoint, $this->getAccessToken($oauth2_client), $timeout);
    }
    catch (BadResponseException $e) {
      // A rejected token is indistinguishable from a misconfigured one, so
      // discard the stored token and allow exactly one more attempt.
      if ($e->getResponse()->getStatusCode() !== 401) {
        throw $this->responseException($e);
      }

      $this->oauth2Client->clearAccessToken($oauth2_client);

      try {
        $response = $this->request($payload, $endpoint, $this->getAccessToken($oauth2_client), $timeout);
      }
      catch (BadResponseException $retry) {
        // A freshly issued token that is rejected again points at the
        // credentials, not at this request, so stop instead of looping.
        throw $this->responseException($retry);
      }
    }

    return $this->decodeResponse((string) $response->getBody());
  }

  /**
   * Performs the authenticated POST request.
   *
   * @param array $payload
   *   The FormularCaseRequest payload.
   * @param string $endpoint
   *   The endpoint URL.
   * @param string $token
   *   The bearer token.
   * @param int $timeout
   *   The request timeout in seconds.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response.
   *
   * @throws \GuzzleHttp\Exception\BadResponseException
   *   If the endpoint answered with an error status code.
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If the endpoint could not be reached.
   */
  protected function request(array $payload, string $endpoint, string $token, int $timeout) {
    try {
      return $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'timeout' => $timeout,
        'http_errors' => TRUE,
      ]);
    }
    catch (BadResponseException $e) {
      throw $e;
    }
    catch (TransferException $e) {
      throw CaseApiException::transient(sprintf('Case API is unreachable: %s', $e->getMessage()), $e);
    }
  }

  /**
   * Returns a bearer token for the configured OAuth2 client.
   *
   * @param string $oauth2_client
   *   Machine name of the OAuth2 client configuration entity. The client ID
   *   and secret themselves are held by that entity's credential provider,
   *   never by this module.
   *
   * @return string
   *   The access token.
   *
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If no token could be obtained.
   */
  protected function getAccessToken(string $oauth2_client): string {
    // The interface declares the credentials argument without a default, so
    // it is passed explicitly even though this grant type ignores it.
    $token = $this->oauth2Client->getAccessToken($oauth2_client, NULL);
    if ($token === NULL) {
      // The OAuth2 Client module logs the underlying cause. Treat this as
      // transient: an expired secret is fixed by an editor, not by the payload.
      throw CaseApiException::transient(sprintf('No access token could be obtained for OAuth2 client "%s".', $oauth2_client));
    }

    return $token->getToken();
  }

  /**
   * Decodes and validates the API response.
   *
   * @param string $body
   *   The raw response body.
   *
   * @return array
   *   The decoded response.
   *
   * @throws \Drupal\sap_case_api\Exception\CaseApiException
   *   If the response is unusable or reports an error.
   */
  protected function decodeResponse(string $body): array {
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw CaseApiException::transient(sprintf('Case API returned a non-JSON response: %s', mb_substr($body, 0, 500)));
    }

    // The endpoint answers 201 with a status field; "error" means the payload
    // reached SAP but was rejected there, which retrying will not fix.
    if (($decoded['status'] ?? NULL) === 'error') {
      throw CaseApiException::permanent(sprintf('Case API rejected the request: %s', mb_substr($body, 0, 500)));
    }

    return $decoded;
  }

  /**
   * Converts an HTTP error response into a classified exception.
   *
   * @param \GuzzleHttp\Exception\BadResponseException $e
   *   The Guzzle exception.
   *
   * @return \Drupal\sap_case_api\Exception\CaseApiException
   *   The classified exception.
   */
  protected function responseException(BadResponseException $e) {
    $status = $e->getResponse()->getStatusCode();
    $body = mb_substr((string) $e->getResponse()->getBody(), 0, 500);
    $message = sprintf('Case API request failed with HTTP %d: %s', $status, $body);

    // 4xx means the request itself is wrong — except 408 and 429, which are
    // about timing and are worth retrying.
    if ($status >= 400 && $status < 500 && !in_array($status, [408, 429], TRUE)) {
      return CaseApiException::permanent($message, $e);
    }

    return CaseApiException::transient($message, $e);
  }

}
