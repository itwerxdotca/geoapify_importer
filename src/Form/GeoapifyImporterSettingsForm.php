<?php

namespace Drupal\geoapify_importer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GeoapifyImporterSettingsForm extends ConfigFormBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs the settings form.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, StateInterface $state) {
  parent::__construct($config_factory, $typed_config_manager);
  $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
  return new static(
    $container->get('config.factory'),
    $container->get('config.typed'),
    $container->get('state')
  );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
  return ['geoapify_importer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
  return 'geoapify_importer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
  $api_key = $this->state->get('geoapify_importer.api_key');

  if (!empty($api_key)) {
    $status = $this->t('API key is currently set.');
  }
  else {
    $status = $this->t('No API key is currently set.');
  }

  $form['api_key_status'] = [
    '#type' => 'container',
    '#attributes' => [
    'class' => ['messages', 'messages--status'],
    ],
    'message' => [
    '#markup' => $status,
    ],
  ];

  $form['api_key'] = [
    '#type' => 'password',
    '#title' => $this->t('Geoapify API key'),
    '#description' => $this->t('Enter a new API key to replace the existing key. Leave this field blank to keep the current key.'),
    '#attributes' => [
    'autocomplete' => 'new-password',
    ],
  ];

  $config = $this->config('geoapify_importer.settings');

  $form['reverse_geocode'] = [
    '#type' => 'details',
    '#title' => $this->t('Reverse-geocode address verification (fallback)'),
    '#open' => TRUE,
  ];

  $form['reverse_geocode']['reverse_geocode_enabled'] = [
    '#type' => 'checkbox',
    '#title' => $this->t('Enable reverse-geocode fallback'),
    '#default_value' => $config->get('reverse_geocode_enabled') ?? FALSE,
    '#description' => $this->t(
    'When a place returned by the Geoapify Places API does not include a verified civic street address (no house number), the importer can optionally make a <strong>second, separate API call</strong> to the Geoapify Reverse Geocoding API to check whether that location independently resolves to a street address with a house number. If it does, the address and town are trusted automatically instead of being routed to editorial review. <strong>Enabling this will increase your Geoapify API usage and cost</strong>, since it adds one extra request for every place that does not already have a verified address from the initial Places API response. Leave this disabled to keep API usage to the minimum and send all unverified addresses to manual review instead.'
    ),
  ];

  $form['rate_limit'] = [
    '#type' => 'details',
    '#title' => $this->t('Geoapify API usage limit'),
    '#open' => TRUE,
  ];

  $form['rate_limit']['geoapify_rate_limit_enabled'] = [
    '#type' => 'checkbox',
    '#title' => $this->t('Enable a daily limit on Geoapify API requests'),
    '#default_value' => $config->get('geoapify_rate_limit_enabled') ?? TRUE,
    '#description' => $this->t(
    'Geoapify bills on a single shared daily credit allowance for your whole account, regardless of which feature makes the request — place search, geocoding, or Place Details enrichment below all draw from the same pool. When this limit is enabled, every Geoapify API call the importer makes counts toward one shared daily total (resetting at midnight UTC); once the limit is reached, further requests are blocked for the rest of the day rather than risking an unattended import exceeding your plan\'s actual daily allowance. Leave enabled unless you have a specific reason to run without a cap.'
    ),
  ];

  $form['rate_limit']['geoapify_daily_request_limit'] = [
    '#type' => 'number',
    '#title' => $this->t('Maximum Geoapify API requests per day'),
    '#min' => 1,
    '#step' => 1,
    '#default_value' => $config->get('geoapify_daily_request_limit') ?? 2500,
    '#description' => $this->t('Set this below your Geoapify plan\'s actual daily credit allowance, leaving some headroom for requests made outside this importer (e.g. directly through Geoapify\'s dashboard or playground) on the same account.'),
    '#states' => [
    'visible' => [
      ':input[name="geoapify_rate_limit_enabled"]' => ['checked' => TRUE],
    ],
    ],
  ];

  $form['place_details'] = [
    '#type' => 'details',
    '#title' => $this->t('Place Details enrichment'),
    '#open' => TRUE,
  ];

  $form['place_details']['place_details_enabled'] = [
    '#type' => 'checkbox',
    '#title' => $this->t('Enable Place Details enrichment'),
    '#default_value' => $config->get('place_details_enabled') ?? FALSE,
    '#description' => $this->t(
    'When enabled, the importer can look up additional information for a place — phone, email, ownership/operator details, and category-specific detail — using the Geoapify Place Details API, looked up by the place\'s own OpenStreetMap reference (no extra lookup needed). This is a separate, metered API call per place, subject to the shared daily request limit above. Leave disabled to skip enrichment entirely.'
    ),
  ];

  return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  $api_key = trim($form_state->getValue('api_key'));

  if ($api_key !== '') {
    $this->state->set('geoapify_importer.api_key', $api_key);
    $this->messenger()->addStatus($this->t('Geoapify API key saved.'));
  }

  $this->config('geoapify_importer.settings')
    ->set('reverse_geocode_enabled', (bool) $form_state->getValue('reverse_geocode_enabled'))
    ->set('geoapify_rate_limit_enabled', (bool) $form_state->getValue('geoapify_rate_limit_enabled'))
    ->set('geoapify_daily_request_limit', (int) $form_state->getValue('geoapify_daily_request_limit'))
    ->set('place_details_enabled', (bool) $form_state->getValue('place_details_enabled'))
    ->save();

  parent::submitForm($form, $form_state);
  }

}
