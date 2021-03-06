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

namespace Drupal\auth_apic\Form;

use Drupal\change_pwd_page\Form\ChangePasswordForm;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\auth_apic\Service\Interfaces\UserManagerInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// TODO: use UrlGeneratorTrait to enable unit test of the redirects.
// at the moment importing them to have them in the ctors ready for doing this properly.

class ApicUserChangePasswordForm extends ChangePasswordForm {

  use StringTranslationTrait;
  use UrlGeneratorTrait;
  protected $moduleHandler;
  protected $userManager;
  protected $entityManager;

  /**
   * Constructs a ChangePasswordForm object.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   Module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   Translation interface, see https://www.drupal.org/docs/8/api/translation-api/overview
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   Url generator - for redirect handling.
   * @param \Drupal\Core\Password\PasswordInterface $password_hasher
   *   The password hasher.
   * @param \Drupal\auth_apic\Service\Interfaces\UserManagerInterface $user_manager
   *   User manager.
   */
  public function __construct(AccountInterface $account,
                              ModuleHandler $module_handler,
                              LoggerInterface $logger,
                              TranslationInterface $string_translation,
                              UrlGeneratorInterface $url_generator,
                              PasswordInterface $password_hasher,
                              UserManagerInterface $user_manager,
                              EntityManagerInterface $entity_manager) {
    $this->account = $account;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;
    $this->stringTranslation = $string_translation;
    $this->urlGenerator = $url_generator;
    $this->password_hasher = $password_hasher;
    $this->userManager = $user_manager;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('logger.channel.auth_apic'),
      $container->get('string_translation'),
      $container->get('url_generator'),
      $container->get('password'),
      $container->get('auth_apic.usermanager'),
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'apic_change_pwd_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL) {

    $this->user_profile = $account = $user;
    $user = $this->account;

    if ($this->getRequest()->get('pass-reset-token')) {
      // admin password reset or one-time login form.
      // this request has come via the change_pwd_page ChangePasswordResetForm page so fall back to their processing..
      return parent::buildForm($form, $form_state, $account);
    }

    if (!$user->isAnonymous()) {
      // Account information.
      $form['account'] = array(
        '#type' => 'container',
        '#weight' => -10,
      );

      $form['account']['current_pass'] = array(
        '#type' => 'password',
        '#title' => $this->t('Current password'),
        '#size' => 25,
        //'#access' => !$form_state->get('user_pass_reset'),
        '#weight' => -5,
        // Do not let web browsers remember this password, since we are
        // trying to confirm that the person submitting the form actually
        // knows the current one.
        '#attributes' => array('autocomplete' => 'off'),
        '#required' => TRUE,
      );
      $form_state->set('user', $account);

      $form['account']['pass'] = array(
        '#type' => 'password_confirm',
        '#required' => TRUE,
        '#description' => $this->t('Provide a password.'),
      );

      $form['#form_id'] = $this->getFormId();
      $form['account']['roles'] = array();
      $form['account']['roles']['#default_value'] = array();

      // If the password policy module is enabled, modify this form to show
      // the configured policy.
      $showPasswordPolicy = FALSE;

      if ($this->moduleHandler->moduleExists('password_policy')) {
        $showPasswordPolicy = _password_policy_show_policy();
      }

      if ($showPasswordPolicy) {
        $form['account']['password_policy_status'] = array(
          '#title' => $this->t('Password policies'),
          '#type' => 'table',
          '#header' => array(t('Policy'), t('Status'), t('Constraint')),
          '#empty' => t('There are no constraints for the selected user roles'),
          '#weight' => '400',
          '#prefix' => '<div id="password-policy-status" class="hidden">',
          '#suffix' => '</div>',
          '#rows' => _password_policy_constraints_table($form, $form_state),
        );

        $form['auth-apic-password-policy-status'] = ibm_apim_password_policy_check_constraints($form, $form_state);
      }

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Submit')];

      return $form;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user = $this->currentUser();

    // special case original admin user who uses the drupal db.
    if ($user->id() === '1') {
      $this->logger->notice('change password form validation for admin user');
      parent::validateForm($form, $form_state);
    }
    else {
      $this->logger->notice('change password form validation for non-admin user');
      // no-op for non-admin
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // special case original admin user who uses the drupal db.
    if ($this->currentUser()->id() === '1') {
      $this->logger->notice('change password form submit for admin user');
      $form_state->setRedirect('<front>');
      parent::submitForm($form, $form_state);
    }
    else {
      $user = $this->entityManager->getStorage('user')->load($this->currentUser()->id());

      $this->logger->notice('change password form submit for non-admin user');
      $success = $this->userManager->changePassword($user, $form_state->getValue('current_pass'), $form_state->getValue('pass'));
      if ($success) {
        drupal_set_message(t('Password changed successfully'));
        //$form_state->setRedirect('user.logout');
      }
    }
  }
}
