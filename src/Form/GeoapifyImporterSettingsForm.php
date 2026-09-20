<?php

namespace Drupal\geoapify_importer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
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
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
	parent::__construct($config_factory);
	$this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
	return new static(
	  $container->get('config.factory'),
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
		'When a place returned by the Geoapify Places API does not include a verified civic street address (no house number), the importer can optionally make a <strong>second, separate API call</strong> to the Geoapify Reverse Geocoding API to check whether that location independently resolves to a confident, verified address. If this check passes, the address and town are trusted automatically instead of being routed to editorial review. <strong>Enabling this will increase your Geoapify API usage and cost</strong>, since it adds one extra request for every place that does not already have a verified address from the initial Places API response. Leave this disabled to keep API usage to the minimum and send all unverified addresses to manual review instead.'
	  ),
	];

	$form['reverse_geocode']['reverse_geocode_confidence_threshold'] = [
	  '#type' => 'number',
	  '#title' => $this->t('Minimum building-level confidence'),
	  '#min' => 0,
	  '#max' => 1,
	  '#step' => 0.05,
	  '#default_value' => $config->get('reverse_geocode_confidence_threshold') ?? 0.8,
	  '#description' => $this->t('A value from 0 to 1. Reverse-geocode results scoring below this confidence will not be treated as verified, even if the match type below is accepted. Higher is stricter (fewer auto-approvals, more sent to review).'),
	  '#states' => [
		'visible' => [
		  ':input[name="reverse_geocode_enabled"]' => ['checked' => TRUE],
		],
	  ],
	];

	$match_type_options = [
	  'full_match' => $this->t('Full match'),
	  'match_by_building' => $this->t('Match by building'),
	  'match_by_street' => $this->t('Match by street'),
	  'match_by_postcode' => $this->t('Match by postcode'),
	  'match_by_city_or_disrict' => $this->t('Match by city or district'),
	  'match_by_country_or_state' => $this->t('Match by country or state'),
	  'inner_part' => $this->t('Inner part'),
	];

	$form['reverse_geocode']['reverse_geocode_accepted_match_types'] = [
	  '#type' => 'checkboxes',
	  '#title' => $this->t('Accepted match types'),
	  '#options' => $match_type_options,
	  '#default_value' => $config->get('reverse_geocode_accepted_match_types') ?? ['full_match', 'match_by_building'],
	  '#description' => $this->t('Only reverse-geocode results with one of these checked match types (and meeting the confidence threshold above) are treated as verified. Stricter settings send more records to manual review; looser settings trust the fallback more but risk incorrect auto-approvals.'),
	  '#states' => [
		'visible' => [
		  ':input[name="reverse_geocode_enabled"]' => ['checked' => TRUE],
		],
	  ],
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
	  ->set('reverse_geocode_confidence_threshold', (float) $form_state->getValue('reverse_geocode_confidence_threshold'))
	  ->set('reverse_geocode_accepted_match_types', array_values(array_filter($form_state->getValue('reverse_geocode_accepted_match_types'))))
	  ->save();

	parent::submitForm($form, $form_state);
  }

}