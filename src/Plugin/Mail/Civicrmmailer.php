<?php

/**
 * @file
 * Contains \Drupal\mailsystem\Plugin\mailsystem\Dummy.
 */

namespace Drupal\civicrmmailer\Plugin\Mail;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Database;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;

/**
 * Sends and logs Drupal mails in CiviCRM.
 *
 * @Mail(
 *   id = "civicrmmailer",
 *   label = @Translation("CiviCRM Mailer Mail-Plugin"),
 *   description = @Translation("CiviCRM Mailer Mail-Plugin for sending emails through CiviCRM.")
 * )
 */
class Civicrmmailer implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $default = new PhpMail();
    return $default->format($message);
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    \Drupal::service('civicrm')->initialize();

    $contact = NULL;
    $cid = 0;

    // Drupal user emails are unique, so fetch the Drupal record
    // then we'll fetch the civicrm contact record.
    $user = user_load_by_mail($message['to']);

    if ($user) {
      $uid = $user->id();

      try {
        $uf = civicrm_api3('UFMatch', 'getsingle', [
          'uf_id' => $uid,
        ]);
        $cid = $uf['contact_id'];
      }
      catch (\Exception $e) {
        // The uf_match might not exist yet.
        // For example: user account creation through a CiviCRM profile.
        // This is OK, the code below will fetch by email.
      }
    }

    // The user might not exist. It could be a webform email.
    // Find a matching email in CiviCRM, prioritize primary emails.
    if (!$cid) {
      $dao = CRM_Core_DAO::executeQuery('SELECT contact_id FROM civicrm_email WHERE email = %1 AND contact_id IS NOT NULL ORDER BY is_primary DESC', [
        1 => [$message['to'], 'String'],
      ]);

      if ($dao->fetch()) {
        $cid = $dao->contact_id;
      } else {
        // Contact is missing. Let's create it.
        // We are assuming that the recipient is an individual.
        try {
          $contact = civicrm_api3('Contact', 'create', [
            'contact_type' => 'Individual',
            'email' => $message['to'],
          ]);
          $cid = $contact['id'];
        }
        catch (\Exception $e) {
          \Drupal::logger('civicrmmailer')->error(
            'Failed to create missing CiviCRM contact for email address %to.', [
              '%to' => $message['to'],
            ]
           );
          return FALSE;
        }
      }
    }

    try {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $cid
      ]);
    }
    catch (\Exception $e) {
      // Contact should normally exist at this point.
      \Drupal::logger('civicrmmailer')->error(
        'Could not find CiviCRM contact #%cid (%to). Email will not be sent.', [
          '%to' => $message['to'],
          '%cid' => $cid
        ]
       );
      return FALSE;
    }

    $formattedContactDetails = [];
    $formattedContactDetails[] = $contact;

    // By default Drupal sends text emails
    // Should we try to detect if the message is already in HTML?
    $html_message = nl2br($message['body']);

    // Fetch the default organisation for the domain
    $domain_id = \CRM_Core_Config::domainID();

    $default_org_id = \CRM_Core_DAO::singleValueQuery('SELECT contact_id FROM civicrm_domain WHERE id = %1', [
      1 => [$domain_id, 'Positive'],
    ]);

    \Drupal::logger('civicrmmailer')->debug(
      'CiviCRM contact #%from_id will now attempt to send an email to contact #%to_id (%to_email).', [
      '%to_id' => $cid,
      '%to_email' => $message['to'],
      '%from_id' => $default_org_id
      ]
    );

    // Send message from Drupal's site email
    $fromEmail = \Drupal::config('system.site')->get('mail');
    $fromName = \Drupal::config('system.site')->get('name');
    $from = $fromName ? "$fromName <$fromEmail>" : $fromEmail;

    list($sent, $activityId) = \CRM_Activity_BAO_Activity::sendEmail(
      $formattedContactDetails, // Recipient contact / To email
      $message['subject'],
      $message['body'], // Text message
      $html_message, // HTML body
      NULL,
      $default_org_id, // Sending contact / Default From email
      $from
    );

    // Assign activity to the recipient
    // Mark recipent as target even though he might be the source of the event
    try{
      $result = civicrm_api3('ActivityContact', 'create', [
        'contact_id' => $cid,
        'activity_id' => $activityId,
        'record_type_id' => 'Activity Targets',
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      // Fail silently, but log the error to the watchdog nevertheless
      \Drupal::logger('civicrmmailer')->error(
        'Failed to attach activity #%activity_id to contact #%contact_id: %msg', [
        '%contact_id' => $cid,
        '%activity_id' => $activityId,
        '%msg' => $e->description,
        ]
      );
    }

    return $sent;
  }
}
