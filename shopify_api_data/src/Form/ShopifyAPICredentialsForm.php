<?php

namespace Drupal\shopify_api_data\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ShopifyAPICredentialsForm extends FormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'shopify_api_data_api_credentials_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $current_user = \Drupal::currentUser();
    $roles = $current_user->getRoles();

    if (in_array('administrator', $roles)) {
      $uid = $current_user->id();

      $form['roles'] = [
        '#type' => 'hidden',
        '#value' => 'administrator',
      ];

      $form['uid'] = [
        '#type' => 'hidden',
        '#value' => $uid,
      ];

      $form['api_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API Key'),
        '#required' => TRUE,
      ];

      $form['api_secret_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API Secret Key'),
        '#required' => TRUE,
      ];

      $form['storeID'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Store ID'),
        '#required' => TRUE,
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
      ];

      return $form;
    } else {
      $url = Url::fromRoute('system.403');
      return new RedirectResponse($url->toString());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    // Validation logic can be added here if needed.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    try {
      $conn = Database::getConnection();

      $field = $form_state->getValues();
      $fields = [
        'uid' => $field['uid'],
        'api_key' => $field['api_key'],
        'api_secret_key' => $field['api_secret_key'],
        'storeID' => $field['storeID'],
        'roles' => $field['roles'],
      ];

      $exist = $conn
        ->select('shopify_credentials', 'sc')
        ->fields('sc', ['uid', 'api_key', 'api_secret_key', 'storeID'])
        ->condition('uid', $field['uid'])
        ->condition('roles', $field['roles'])
        ->execute()
        ->fetchAll();

      if ($exist) {
        $conn
          ->update('shopify_credentials')
          ->fields($fields)
          ->condition('uid', $field['uid'])
          ->condition('roles', $field['roles'])
          ->execute();
        \Drupal::messenger()->addMessage($this->t('The data has been successfully updated'));
      } else {
        $conn
          ->insert('shopify_credentials')
          ->fields($fields)
          ->execute();
        \Drupal::messenger()->addMessage($this->t('The data has been successfully saved'));
      }
    } catch (\Exception $ex) {
      \Drupal::logger('shopify')->error($ex->getMessage());
    }
  }
}
