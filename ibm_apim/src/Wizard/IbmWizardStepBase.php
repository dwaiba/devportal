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

namespace Drupal\ibm_apim\Wizard;

use Drupal\Core\Form\FormBase;

use Symfony\Component\HttpFoundation\RedirectResponse;

abstract class IbmWizardStepBase extends FormBase {

  /**
   * Checks that the current user has developer permissions i.e. is able
   * to create subscriptions for apps. If not, redirects to the home page.
   */
  protected function validateAccess() {

    ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, NULL);

    $return_value = TRUE;

    $user_utils = \Drupal::service('ibm_apim.user_utils');

    if(\Drupal::currentUser()->isAnonymous()) {
      // The user needs to log in / sign up first but we need to make note that they want to start the subscription wizard
      user_cookie_save(array('startSubscriptionWizard' => \Drupal::request()->get('productId')));
      drupal_set_message(t("Sign in or create an account to subscribe to products and start using our APIs"), 'error');
      $this->redirect("user.page")->send();
      $return_value = FALSE;
    }

    if(!$user_utils->checkHasPermission('subscription:manage')) {
      drupal_set_message(t("Only users with the required permissions can access the subscription wizard"), 'error');
      $this->redirect("<front>")->send();
      $return_value = FALSE;
    }

    ibm_apim_exit_trace(__CLASS__ . '::' . __FUNCTION__, $return_value);

    return $return_value;

  }

}
