<?php

namespace Drupal\sap_case_api\Plugin\Oauth2Client;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oauth2_client\Attribute\Oauth2Client;
use Drupal\oauth2_client\Plugin\Oauth2Client\Oauth2ClientPluginBase;
use Drupal\oauth2_client\Plugin\Oauth2Client\StateTokenStorage;

/**
 * OAuth2 client for the SAP Service Cloud Formular Case Request API.
 *
 * The token endpoint differs per environment, so it is read from this module's
 * settings instead of being hard coded in the plugin definition. The client ID
 * and secret come from the OAuth2 client configuration entity, which stores the
 * secret in a key rather than in exported configuration.
 *
 * The token is stored in state rather than per user session, because every
 * submission talks to the same SAP tenant with the same machine credentials.
 */
#[Oauth2Client(
  id: 'sap_case_api',
  name: new TranslatableMarkup('SAP Service Cloud Case API'),
  grant_type: 'client_credentials',
  authorization_uri: '',
  token_uri: '',
)]
class CaseApiOauth2Client extends Oauth2ClientPluginBase {

  use StateTokenStorage;

  /**
   * {@inheritdoc}
   */
  public function getTokenUri(): string {
    $configured = (string) $this->configFactory
      ->get('sap_case_api.settings')
      ->get('token_uri');

    return $configured !== '' ? $configured : parent::getTokenUri();
  }

}
