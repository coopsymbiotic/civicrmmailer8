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

    // Drupal user emails are unique, so fetch the Drupal record
    // then we'll fetch the civicrm contact record.
    $user = user_load_by_mail($message['to']);

    if ($user) {
      $uid = $user->id();
      $uf = civicrm_api3('UFMatch', 'getsingle', [
        'uf_id' => $uid,
      ]);

      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $uf['contact_id'],
      ]);
    }

    // The user might not exist. It could be a webform email.
    // Find a matching email in CiviCRM, prioritize primary emails.
    if (empty($contact)) {
      $dao = CRM_Core_DAO::executeQuery('SELECT contact_id FROM civicrm_email WHERE email = %1 ORDER BY is_primary DESC', [
        1 => [$message['to'], 'String'],
      ]);

      if ($dao->fetch()) {
        $contact = civicrm_api3('Contact', 'getsingle', [
          'id' => $dao->contact_id,
        ]);
      }
    }

    // FIXME: maybe return FALSE? for now, we prefer to get notifications
    // when it fails.
    if (empty($contact)) {
      throw new Exception("Could not find Drupal user for {$message['to']}");
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

    list($sent, $activityId) = \CRM_Activity_BAO_Activity::sendEmail(
      $formattedContactDetails,
      $message['subject'],
      $message['body'], // text message
      $html_message, // html body
      NULL,
      $default_org_id // used for the "from"
    );

    return TRUE;
  }
}
