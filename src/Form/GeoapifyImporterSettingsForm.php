<?php

namespace Drupal\geoapify_importer\Form;

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
  public function __construct(StateInterface $state) {
	$this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
	return new static(
	  $container->get('state')
	);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
	return [];
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

	parent::submitForm($form, $form_state);
  }

}