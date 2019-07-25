CiviCRM Mailer
==============

Once enabled, this module sends all Drupal emails through CiviCRM.

This assumes:

* All emails sent are sent to Drupal users (who therefore have a CiviCRM contact record).
  * or .. emails are sent to CiviCRM contacts (ex: if using webform, your webform uses webform_civicrm to create a contact).
* The site is using the DefaultMailer (when enabled, it replaces the default mailer).
* The emails sent are plain HTML (they automatically go through nl2br).

Why?

* It logs all user emails to their contact record, which can be useful (welcome emails, password resets).
* If using a third-party email service, you can configure only it once (in CiviCRM) and ignore Drupal settings.
* You can use CiviCRM tokens in Drupal emails.

Needs testing:

* Webform emails (to and cc).


Warning
-------

Emails are currently sent from CiviCRM domain contact (user ID 1, typically) and not from the "FROM Email Addresses" as defined in CiviCRM.

Installation
------------

The installer tries to change the default mailer, but if that does not work, use the 'mailsystem' module to set the mailer explicitly :

```bash
drush config-set system.mail interface.default civicrmmailer
```

In order to revert to the usual php mailer, the following may work :

```bash
drush config-set system.mail interface.default php_mail
```

To verify mailer configuration :

```bash
drush cget system.mail
```

Troubleshooting
---------------

When civicrmmailer is active, mailing errors should be logged to the `ConfigAndLog` folder.

Support
-------

Please post bug reports in the issue tracker of this project on github: 
https://github.com/coopsymbiotic/civicrmmailer/issues

Commercial support via Coop SymbioTIC:  
https://www.symbiotic.coop/en

License
-------

(C) 2018 Mathieu Lutfy <mathieu@symbiotic.coop>  
(C) 2018 Coop SymbioTIC <info@symbiotic.coop>

Distributed under the terms of the GNU Affero General public license (AGPL).
See LICENSE.txt for details.
