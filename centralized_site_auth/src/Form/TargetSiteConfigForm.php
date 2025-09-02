<?php

namespace Drupal\centralized_site_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class TargetSiteConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['centralized_site_auth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'target_site_config_form';
  }

  /**
   * Build the config form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('centralized_site_auth.settings');

    $form['target_site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target Site URL'),
      '#description' => $this->t('Enter the base URL of the target site. Example: http://localhost/wp_other_site/web'),
      '#default_value' => $config->get('target_site_url'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Submit handler for the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('centralized_site_auth.settings')
      ->set('target_site_url', $form_state->getValue('target_site_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
