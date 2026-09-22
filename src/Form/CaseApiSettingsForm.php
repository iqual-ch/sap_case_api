<?php

namespace Drupal\sap_case_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configures the connection to the SAP Service Cloud Case API.
 */
class CaseApiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sap_case_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sap_case_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sap_case_api.settings');

    $form['endpoint_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint URL'),
      '#description' => $this->t('Full URL of the Inbound operation, for example https://example.it-cpi123-rt.cfapps.eu10.hana.ondemand.com/http/SCV2-FormularIntegration/Inbound.'),
      '#default_value' => $config->get('endpoint_url'),
      '#required' => TRUE,
    ];
    $form['token_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('OAuth2 token URL'),
      '#description' => $this->t('Token endpoint used for the client credentials grant.'),
      '#default_value' => $config->get('token_uri'),
      '#required' => TRUE,
    ];
    $form['oauth2_client'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 client'),
      '#description' => $this->t('Machine name of the <a href=":url">OAuth2 client</a> to authenticate with. The client ID and secret are held by that client, not here — this is only a reference to it.', [
        ':url' => Url::fromRoute('entity.oauth2_client.collection')->toString(),
      ]),
      '#default_value' => $config->get('oauth2_client'),
      '#required' => TRUE,
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#max' => 120,
      '#default_value' => $config->get('timeout') ?: 30,
      '#required' => TRUE,
    ];

    $form['override_notice'] = [
      '#type' => 'item',
      '#markup' => $this->t('These values differ per environment. Override them in settings.local.php rather than exporting environment specific URLs, for example: <code>@example</code>', [
        '@example' => "\$config['sap_case_api.settings']['endpoint_url'] = 'https://…';",
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sap_case_api.settings')
      ->set('endpoint_url', $form_state->getValue('endpoint_url'))
      ->set('token_uri', $form_state->getValue('token_uri'))
      ->set('oauth2_client', $form_state->getValue('oauth2_client'))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
