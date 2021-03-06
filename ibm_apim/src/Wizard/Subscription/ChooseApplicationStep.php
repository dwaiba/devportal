<?php
/********************************************************* {COPYRIGHT-TOP} ***
 * Licensed Materials - Property of IBM
 * 5725-L30, 5725-Z22
 *
 * (C) Copyright IBM Corporation 2018
 *
 * All Rights Reserved.
 * US Government Users Restricted Rights - Use, duplication or disclosure
 * restricted by GSA ADP Schedule Contract with IBM Corp.
 ********************************************************** {COPYRIGHT-END} **/

namespace Drupal\ibm_apim\Wizard\Subscription;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

use Drupal\apic_app\Application;
use Drupal\ibm_apim\Wizard\IbmWizardStepBase;

use Symfony\Component\HttpFoundation\RedirectResponse;

class ChooseApplicationStep extends IbmWizardStepBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'subscription_wizard_choose_application';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // If a non-developer user somehow gets in to the wizard, validateAccess will send them away again
    if($this->validateAccess()) {

      $cached_values = $form_state->getTemporaryValue('wizard');

      // if refering page was not another part of the subscription wizard, store a reference to it in the drupal session
      if(strpos($_SERVER['HTTP_REFERER'], '/subscription') === FALSE && strpos($_SERVER['HTTP_REFERER'], '/login') === FALSE){
        \Drupal::service('tempstore.private')->get('ibm_apim')->set('subscription_wizard_referer', $_SERVER['HTTP_REFERER']);
      }

      // Check for any query params from where we've started at this step
      $product_id = \Drupal::request()->query->get('productId');
      $plan_title = \Drupal::request()->query->get('planTitle');
      $plan_id = \Drupal::request()->query->get('planId');

      if(isset($product_id) && isset($plan_title) && isset($plan_id)) {
        $product_node = Node::load($product_id);
        $cached_values['productId'] = $product_id;
        $cached_values['productName'] = $product_node->getTitle();
        $cached_values['productUrl'] = $product_node->get('apic_url')->value;
        $cached_values['planName'] = $plan_title;
        $cached_values['planId'] = $plan_id;
        $form_state->setTemporaryValue('wizard', $cached_values);
      }

      $productName = $cached_values['productName'];
      $planId = $cached_values['planId'];
      $parts = explode(':', $planId);
      $product_url = $parts[0];

      $allApps = Application::listApplications();
      $allApps = Node::loadMultiple($allApps);
      $validApps = array();
      $suspendedApps = array();
      $subscribedApps = array();

      // Do some checks on the apps
      // - if they are suspended don't show them
      // - if they are already subscribed to any plan in this product don't show them
      // - if there are no apps left to display after that, put up a message

      foreach($allApps as $nid => $nextApp) {
        if (isset($nextApp->apic_state->value) && mb_strtoupper($nextApp->apic_state->value) == 'SUSPENDED') {
          array_push($suspendedApps, $nextApp);
        }
        else if (isset($nextApp->application_subscriptions->value)) {
          $subs = unserialize($nextApp->application_subscriptions->value);
          if (is_array($subs)) {
            $appSubscribedToProduct = FALSE;
            foreach ($subs as $sub) {
              if (isset($sub['product_url']) && $sub['product_url'] == $product_url) {
                array_push($subscribedApps, $nextApp);
                $appSubscribedToProduct = TRUE;
                break;
              }
            }
            if(!$appSubscribedToProduct) {
              array_push($validApps, $nextApp);
            }
          }
        }
        else {
          array_push($validApps, $nextApp);
        }
      }

      if(!empty($suspendedApps)) {
        $form['#messages']['suspendedAppsNotice'] = t('There are %number suspended applications not displayed in this list.', array('%number' => sizeof($suspendedApps)));
      }

      if(!empty($subscribedApps)) {
        $form['#messages']['subscribedAppsNotice'] = t('There are %number applications that are already subscribed to the %product product. They are not displayed in this list.',
                    array('%number' => sizeof($subscribedApps), '%product' => $productName));
      }

      $form['createNewApp'] = [
        '#type' => 'link',
        '#title' => $this->t('Create New'),
        '#url' => Url::fromRoute('apic_app.create_modal'),
        '#attributes' => array(
          'class' => array(
            'use-ajax',
            'button',
          ),
        ),
        '#prefix' => '<div class="apicNewAppWrapper">',
        '#suffix' => '</div>',
      ];

      if(!empty($validApps)) {
        $form['apps'] = node_view_multiple($validApps, 'subscribewizard');
        $form['apps']['#prefix'] = "<div class='apicSubscribeAppsList'>";
        $form['apps']['#suffix'] = "</div>";
      }
      else {
        $form['#messages']['noAppsNotice'] = t('There are no applications that can be subscribed to this Plan.');
      }

      // Attach the library for pop-up dialogs/modals.
      $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
      $form['#attached']['library'][] = 'apic_app/basic';

      $form_state->setTemporaryValue('wizard', $cached_values);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    if(empty($form_state->getUserInput()['selectedApplication'])) {
      $form_state->setErrorByName('selectedApplication', t('You must select an application to create a subscription.'));
      return FALSE;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cached_values = $form_state->getTemporaryValue('wizard');

    $application = Node::load($form_state->getUserInput()['selectedApplication']);
    $cached_values['applicationUrl'] = $application->get('apic_url')->value;
    $cached_values['applicationName'] = $application->getTitle();
    $cached_values['applicationNodeId'] = $form_state->getUserInput()['selectedApplication'];

    $form_state->setTemporaryValue('wizard', $cached_values);
  }

}
