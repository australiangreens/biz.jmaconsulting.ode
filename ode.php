<?php

require_once 'ode.civix.php';
use CRM_Ode_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function ode_civicrm_config(&$config) {
  _ode_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 */
function ode_civicrm_xmlMenu(&$files) {
  _ode_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 */
function ode_civicrm_install() {
  createOptionGroup();
  checkValidEmails();
  return _ode_civix_civicrm_install();
}

function ode_civicrm_postInstall() {
  return _ode_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 */
function ode_civicrm_uninstall() {
  return _ode_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 */
function ode_civicrm_enable() {
  checkValidEmails();
  return _ode_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 */
function ode_civicrm_disable() {
  return _ode_civix_civicrm_disable();
}

function ode_civicrm_alterSettingsFolders(&$metaDataFolders) {
  return _ode_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_upgrade().
 *
 */
function ode_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _ode_civix_civicrm_upgrade($op, $queue);
}

function createOptionGroup() {
  $check = civicrm_api3('OptionGroup', 'get', ['name' => 'ode_whitelist']);
  if (empty($check['values'])) {
    civicrm_api3('OptionGroup', 'create', [
      'title' => 'Whitelist of Domains for use in Outbound Domain Enforcmenet Extension',
      'name' => 'ode_whitelist',
      'is_reserved' => 1,
      'is_active' => 1,
    ]);
  }
}

/**
 *  Implements hook_civicrm_validateForm().
 */
function ode_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  switch ($formName) {
    case 'CRM_Contribute_Form_Contribution':
    case 'CRM_Event_Form_Participant':
    case 'CRM_Member_Form_Membership':
    case 'CRM_Pledge_Form_Pledge':

      $isReceiptField = array(
        'CRM_Contribute_Form_Contribution' => 'is_email_receipt',
        'CRM_Event_Form_Participant' => 'send_receipt',
        'CRM_Member_Form_Membership' => 'send_receipt',
        'CRM_Pledge_Form_Pledge' => 'is_acknowledge',
      );

      if (CRM_Utils_Array::value($isReceiptField[$formName], $fields) && !CRM_Utils_Array::value('from_email_address', $fields)) {
        $errors['from_email_address'] = ts('Receipt From is a required field.');
      }
      break;

    case 'CRM_Contribute_Form_ContributionPage_ThankYou':
    case 'CRM_Event_Form_ManageEvent_Registration':
    case 'CRM_Grant_Form_GrantPage_ThankYou':
      $isReceiptField = array(
        'CRM_Contribute_Form_ContributionPage_ThankYou' => array('is_email_receipt', 'receipt_from_email'),
        'CRM_Grant_Form_GrantPage_ThankYou' => array('is_email_receipt', 'receipt_from_email'),
        'CRM_Event_Form_ManageEvent_Registration' => array('is_email_confirm', 'confirm_from_email'),
      );

      if (CRM_Utils_Array::value($isReceiptField[$formName][0], $fields)) {
        $errors += toCheckEmail(CRM_Utils_Array::value($isReceiptField[$formName][1], $fields), $isReceiptField[$formName][1]);
        if (!empty($errors)) {
          $errors[$isReceiptField[$formName][1]] = ts('The Outbound Domain Enforcement extension has prevented this From Email Address from being used as it uses a different domain.');
        }
      }
      break;

    case 'CRM_Admin_Form_ScheduleReminders':
      $email = CRM_Utils_Array::value('from_email', $fields);
      if (!$email) {
        list($ignore, $email) = CRM_Core_BAO_Domain::getNameAndEmail();
      }
      $errors += toCheckEmail($email, 'from_email');
      break;

    case 'CRM_UF_Form_Group':
      if (CRM_Utils_Array::value('notify', $fields)) {
        list($ignore, $email) = CRM_Core_BAO_Domain::getNameAndEmail();
        $errors += toCheckEmail($email, 'notify');
      }
      break;

    case 'CRM_Batch_Form_Entry':
      foreach ($fields['field'] as $key => $value) {
        if (CRM_Utils_Array::value('send_receipt', $value)) {
          list($ignore, $email) = CRM_Core_BAO_Domain::getNameAndEmail();
          $errors += toCheckEmail($email, "field[$key][send_receipt]");
          break;
        }
      }
      break;

    case 'CRM_Contact_Form_Domain':
      $errors += toCheckEmail(CRM_Utils_Array::value('email_address', $fields), 'email_address');
      break;

    case (substr($formName, 0, 16) == 'CRM_Report_Form_' ? TRUE : FALSE):
      if (CRM_Utils_Array::value('email_to', $fields) || CRM_Utils_Array::value('email_cc', $fields)) {
        list($ignore, $email) = CRM_Core_BAO_Domain::getNameAndEmail();
        $errors += toCheckEmail($email, 'email_to');
      }
      break;
  }
}

/**
 * Function to check email address with domain.
 *
 * @param string $email
 * @param string $field
 * @param bool $returnHostName
 *
 * @return array|bool
 */
function toCheckEmail($email, $field, $returnHostName = FALSE) {
  $error = [];
  if (!$email) {
    return $error;
  }
  $domains = getWhiteListedDomains();

  if ($returnHostName) {
    return $domains;
  }

  $isError = TRUE;
  if (ode_get_settings_value()) {
    if (isFromEmail($email)) {
      $isError = FALSE;
    }
  }
  // We reach here either it wasn't in the list of from email addresses or we haven't enabled that setting.
  if ($isError) {
    foreach ($domains as $domain) {
      $host = '@' . $domain;
      $hostLength = strlen($host);
      if (substr($email, -$hostLength) == $host) {
        $isError = FALSE;
      }
    }
  }
  if ($isError) {
    $error[$field] = E::ts('The Outbound Domain Enforcement extension has prevented this From Email Address from being used as it uses a different domain than the System-generated Mail Settings From Email Address configured at Administer > Communications > Organization Address and Contact Info.');
  }
  return $error;
}

/**
 * @param $email
 *
 * @return bool
 *   check if passed email is in list of configured FROM email addresses
 *   return TRUE if so
 */
function isFromEmail($email) {
  $domainEmails = CRM_Core_BAO_Email::domainEmails();
  $params = ['id' => CRM_Core_Config::domainID()];
  CRM_Core_BAO_Domain::retrieve($params, $domainDefaults);
  $locParams = array('contact_id' => $domainDefaults['contact_id']);
  $locationDefaults = CRM_Core_BAO_Location::getValues($locParams);
  if (!empty($locationDefaults['email'])) {
    $domainEmails['<' . $locationDefaults['email'][CRM_Core_Config::domainID()]['email'] . '>'] = 1;
  }

  foreach ($domainEmails as $domainEmail => $dontCare) {
    if (strpos($domainEmail, "<{$email}>") !== FALSE) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 *  Implements hook_civicrm_buildForm().
 */
function ode_civicrm_buildForm($formName, &$form) {
  if (in_array($formName,
    [
      'CRM_Mailing_Form_Upload',
      'CRM_Contact_Form_Task_Email',
      'CRM_Contribute_Form_Contribution',
      'CRM_Event_Form_Participant',
      'CRM_Member_Form_Membership',
      'CRM_Pledge_Form_Pledge',
      'CRM_Contribute_Form_Task_Email',
      'CRM_Event_Form_Task_Email',
      'CRM_Member_Form_Task_Email',
    ])) {

    $fromField = 'from_email_address';
    if (in_array($formName,
      [
        'CRM_Contribute_Form_Task_Email',
        'CRM_Event_Form_Task_Email',
        'CRM_Member_Form_Task_Email',
      ])) {
      $fromField = 'fromEmailAddress';
    }

    if (!$form->elementExists($fromField)) {
      return NULL;
    }

    $showNotice = TRUE;
    if ($form->_flagSubmitted) {
      $showNotice = FALSE;
    }

    $elements = & $form->getElement($fromField);
    $options = & $elements->_options;
    ode_suppressEmails($options, $showNotice);

    if (empty($options)) {
      $options = [
        [
          'text' => ts('- Select -'),
          'attr' => ['value' => ''],
        ],
      ];
    }
    $options = array_values($options);
  }
}

/**
 * Function to get Whitelisted domains
 * Altered to return hard-coded greens.org.au domains
 *
 * @return array
 */
function getWhiteListedDomains(): array {
  return [
      'greens.org.au',
      'vic.greens.org.au',
      'act.greens.org.au',
      'nsw.greens.org.au',
      'qld.greens.org.au',
      'nt.greens.org.au',
      'wa.greens.org.au',
      'sa.greens.org.au',
      'tas.greens.org.au',
      'elists.greens.org.au',
      'elists.dev.greens.systems',
      'albury.nsw.greens.org.au',
      'ashfield.nsw.greens.org.au',
      'atg.nsw.greens.org.au',
      'ballina.nsw.greens.org.au',
      'bankstown.nsw.greens.org.au',
      'barrington-tops.nsw.greens.org.au',
      'bbg.nsw.greens.org.au',
      'bega.nsw.greens.org.au',
      'bellingen.nsw.greens.org.au',
      'blacktown.nsw.greens.org.au',
      'bluemountains.nsw.greens.org.au',
      'bmg.nsw.greens.org.au',
      'braidwood.nsw.greens.org.au',
      'btg.nsw.greens.org.au',
      'burrinjuck.nsw.greens.org.au',
      'byron.nsw.greens.org.au',
      'canadabay.nsw.greens.org.au',
      'canterbury.nsw.greens.org.au',
      'cbg.nsw.greens.org.au',
      'ccg.nsw.greens.org.au',
      'centralcoast.nsw.greens.org.au',
      'centralwest.nsw.greens.org.au',
      'cessnock-kurri.nsw.greens.org.au',
      'chg.nsw.greens.org.au',
      'ckg.nsw.greens.org.au',
      'clarence-valley.nsw.greens.org.au',
      'coffsharbour.nsw.greens.org.au',
      'cvg.nsw.greens.org.au',
      'cwg.nsw.greens.org.au',
      'eurobodalla.nsw.greens.org.au',
      'flg.nsw.greens.org.au',
      'gig.nsw.greens.org.au',
      'glen-innes.nsw.greens.org.au',
      'glg.nsw.greens.org.au',
      'goulburn.nsw.greens.org.au',
      'greatlakes.nsw.greens.org.au',
      'hawkesbury.nsw.greens.org.au',
      'hg.nsw.greens.org.au',
      'hornsby.nsw.greens.org.au',
      'illawarra.nsw.greens.org.au',
      'isg.nsw.greens.org.au',
      'kiama.nsw.greens.org.au',
      'krgg.nsw.greens.org.au',
      'ku-ring-gai.nsw.greens.org.au',
      'lake-macquarie.nsw.greens.org.au',
      'lismore.nsw.greens.org.au',
      'lmg.nsw.greens.org.au',
      'lnsg.nsw.greens.org.au',
      'lowernorthshore.nsw.greens.org.au',
      'macarthur.nsw.greens.org.au',
      'maitland.nsw.greens.org.au',
      'manly.nsw.greens.org.au',
      'marrickville.nsw.greens.org.au',
      'mg.nsw.greens.org.au',
      'nambucca-macleay.nsw.greens.org.au',
      'nbg.nsw.greens.org.au',
      'nepean.nsw.greens.org.au',
      'newcastle.nsw.greens.org.au',
      'nmg.nsw.greens.org.au',
      'northern-beaches.nsw.greens.org.au',
      'parramatta.nsw.greens.org.au',
      'petersham-newtown.nsw.greens.org.au',
      'pjg.nsw.greens.org.au',
      'pmg.nsw.greens.org.au',
      'pmhg.nsw.greens.org.au',
      'png.nsw.greens.org.au',
      'portjackson.nsw.greens.org.au',
      'port-stephens.nsw.greens.org.au',
      'psg.nsw.greens.org.au',
      'qmg.nsw.greens.org.au',
      'queanbeyan-monaro.nsw.greens.org.au',
      'randwick-botany.nsw.greens.org.au',
      'rbg.nsw.greens.org.au',
      'reg.nsw.greens.org.au',
      'riverina.nsw.greens.org.au',
      'ryde-epping.nsw.greens.org.au',
      'shg.nsw.greens.org.au',
      'shoalhaven.nsw.greens.org.au',
      'shvg.nsw.greens.org.au',
      'smg.nsw.greens.org.au',
      'southern-highlands.nsw.greens.org.au',
      'ssg.nsw.greens.org.au',
      'stgeorge.nsw.greens.org.au',
      'stgg.nsw.greens.org.au',
      'sutherland.nsw.greens.org.au',
      'the-hills.nsw.greens.org.au',
      'thg.nsw.greens.org.au',
      'tweed.nsw.greens.org.au',
      'uhg.nsw.greens.org.au',
      'upper-hunter.nsw.greens.org.au',
      'waverley.nsw.greens.org.au',
      'woollahra.nsw.greens.org.au',
    ];
}

/**
 * Function to supress email address.
 *
 * @param array $fromEmailAddress
 * @param bool $showNotice
 *
 * @return array|NULL
 */
function ode_suppressEmails(&$fromEmailAddress, $showNotice) {

  $domainEmails = $invalidEmails = [];
  $domains = getWhiteListedDomains();

  if (ode_get_settings_value()) {
    // Allow domains configured in 'From' admin settings.
    // The main objective of this setting is to bypass emails that have been whitelisted and have SPF in place.
    $fromAdminEmails = CRM_Core_OptionGroup::values('from_email_address');

    foreach ($fromAdminEmails as $key => $val) {
      if (preg_match('/<([^>]+)>/', $val, $matches)) {
        $domainEmails[] = $matches[1];
      }
    }
  }

  // Loop through all the From emails passed in to check if they should be suppressed or not
  foreach ($fromEmailAddress as $keys => $headers) {
    $emailNotValidated = TRUE;
    // Loop through all the possibly whitelisted domains.
    foreach ($domains as $domain) {
      // Only keep going if we haven't yet validated it (by setting the variable to be false
      if ($emailNotValidated) {
        $host = '@' . $domain;
        $hostLength = strlen($host);
        $email = pluckEmailFromHeader(html_entity_decode($headers['text']));
        if (empty($domainEmails)) {
          if (substr($email, -$hostLength) == $host) {
            $emailNotValidated = FALSE;
          }
        }
        else {
          if ((!in_array($email, $domainEmails)) && (substr($email, -$hostLength) == $host)) {
            $emailNotValidated = FALSE;
          }
        }
      }
    }
    if ($emailNotValidated) {
      $invalidEmails[] = $email;
      unset($fromEmailAddress[$keys]);
    }
  }

  if (!empty($invalidEmails) && $showNotice) {
    //redirect user to enter from email address.
    $session = CRM_Core_Session::singleton();
    $message = "";
    $url = NULL;
    if (empty($fromEmailAddress)) {
      $message = " You can add another one <a href='%2'>here.</a>";
      $url = CRM_Utils_System::url('civicrm/admin/options/from_email_address', 'group=from_email_address&action=add&reset=1');
    }
    $status = ts('The Outbound Domain Enforcement extension has prevented the following From Email Address option(s) from being used as it uses a different domain than the System-generated Mail Settings From Email Address configured at Administer > Communications > Organization Address and Contact Info: %1' . $message, array(1 => implode(', ', $invalidEmails), 2 => $url));
    if ($showNotice === 'returnMessage') {
      return ['msg' => $status];
    }
    $session->setStatus($status, ts('Notice'));
  }
  return NULL;
}

/**
 * Function to pluck email address from header.
 *
 * @param array $header
 *
 * @return string|NULL
 */
function pluckEmailFromHeader($header) {
  preg_match('/<([^<]*)>/', $header, $matches);

  if (isset($matches[1])) {
    return $matches[1];
  }
  return NULL;
}

/**
 * Function to get domain.
 *
 * @param string $domain
 * @param bool $debug
 *
 * @return string|bool
 */
function get_domain($domain, $debug = FALSE) {
  $original = $domain = strtolower($domain);

  if (filter_var($domain, FILTER_VALIDATE_IP)) {
    return $domain;
  }

  $debug ? print ('<strong style="color:green">&raquo;</strong> Parsing: ' . $original) : FALSE;

  //rebuild array indexes
  $arr = array_slice(array_filter(explode('.', $domain, 4), function($value) {
    return $value !== 'www';
  }), 0);

  if (count($arr) > 2) {
    $count = count($arr);
    $_sub = explode('.', $count === 4 ? $arr[3] : $arr[2]);

    $debug ? print (" (parts count: {$count})") : FALSE;

    if (count($_sub) === 2) {
      // two level TLD
      $removed = array_shift($arr);
      if ($count === 4) {
        // got a subdomain acting as a domain
        $removed = array_shift($arr);
      }
      $debug ? print ("<br>\n" . '[*] Two level TLD: <strong>' . implode('.', $_sub) . '</strong> ') : FALSE;
    }
    elseif (count($_sub) === 1) {
      // one level TLD
      // remove the subdomain
      $removed = array_shift($arr);

      if (strlen($_sub[0]) === 2 && $count === 3) {
        // TLD domain must be 2 letters
        array_unshift($arr, $removed);
      }
      else {
        // non country TLD according to IANA
        $tlds = array(
          'aero',
          'arpa',
          'asia',
          'biz',
          'cat',
          'com',
          'coop',
          'edu',
          'gov',
          'info',
          'jobs',
          'mil',
          'mobi',
          'museum',
          'name',
          'net',
          'org',
          'post',
          'pro',
          'tel',
          'travel',
          'xxx',
        );

        if (count($arr) > 2 && in_array($_sub[0], $tlds) !== FALSE) {
          //special TLD don't have a country
          array_shift($arr);
        }
      }
      $debug ? print ("<br>\n" . '[*] One level TLD: <strong>' . implode('.', $_sub) . '</strong> ') : FALSE;
    }
    else {
      // more than 3 levels, something is wrong
      for ($i = count($_sub); $i > 1; $i--) {
        $removed = array_shift($arr);
      }
      $debug ? print ("<br>\n" . '[*] Three level TLD: <strong>' . implode('.', $_sub) . '</strong> ') : FALSE;
    }
  }
  elseif (count($arr) === 2) {
    $arr0 = array_shift($arr);

    if (strpos(implode('.', $arr), '.') === FALSE
      && in_array($arr[0], array('localhost', 'test', 'invalid')) === FALSE
    ) {
      // not a reserved domain
      $debug ? print ("<br>\n" . 'Seems invalid domain: <strong>' . implode('.', $arr) . '</strong> re-adding: <strong>' . $arr0 . '</strong> ') : FALSE;
      // seems invalid domain, restore it
      array_unshift($arr, $arr0);
    }
  }

  $debug ? print ("<br>\n" . '<strong style="color:gray">&laquo;</strong> Done parsing: <span style="color:red">' . $original . '</span> as <span style="color:blue">' . implode('.', $arr) . "</span><br>\n") : FALSE;

  return implode('.', $arr);
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 */
function ode_civicrm_navigationMenu(&$menu) {
  _ode_civix_insert_navigation_menu($menu, 'Administer/Communications', array(
    'label' => E::ts('ODE Preferences'),
    'name' => 'ode_settings',
    'url' => 'civicrm/admin/setting/ode?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'AND',
    'separator' => 1,
  ));
  _ode_civix_navigationMenu($menu);
}

/**
 * Function to check from email address are configured correctlly for
 * 1. Contribution Page
 * 2. Event Page
 * 3. Schedule Reminders
 * 4. Organization Address and Contact Info
 * 5. If grant application is installed then application page
 */
function checkValidEmails() {
  $domains = toCheckEmail('dummy@dummy.com', NULL, TRUE);
  $config = CRM_Core_Config::singleton();
  if (property_exists($config, 'civiVersion')) {
    $civiVersion = $config->civiVersion;
  }
  else {
    $civiVersion = CRM_Core_BAO_Domain::version();
  }

  $error = [];

  $links = [
    'Contribution Page(s)' => 'civicrm/admin/contribute/thankyou',
    'Event(s)' => 'civicrm/event/manage/registration',
    'Schedule Reminder(s)' => 'civicrm/admin/scheduleReminders',
  ];

  foreach ($domains as $domain) {
    // Contribution Pages.
    $contributionPageParams = [
      'is_email_receipt' => 1,
      'receipt_from_email' => ["NOT LIKE" => '%' . $domain],
      'return' => ['id', 'title'],
    ];
    $result = civicrm_api3('contribution_page', 'get', $contributionPageParams);
    if ($result['count'] > 0) {
      foreach ($result['values'] as $values) {
        $error['Contribution Page(s)'][] = "<a target='_blank' href='" . CRM_Utils_System::url($links['Contribution Page(s)'], "reset=1&action=update&id={$values['id']}") . "'>{$values['title']}</a>";
      }
    }

    // Events.
    $eventParams = [
      'is_email_confirm' => 1,
      'is_template' => ["<>" => 1],
      'confirm_from_email' => ["NOT LIKE" => '%' . $domain],
      'return' => ['id', 'title'],
    ];
    $result = civicrm_api3('event', 'get', $eventParams);
    if ($result['count'] > 0) {
      foreach ($result['values'] as $values) {
        $error['Event(s)'][] = "<a target='_blank' href='" . CRM_Utils_System::url($links['Event(s)'], "reset=1&action=update&id={$values['id']}") . "'>{$values['title']}</a>";
      }
    }

    // Schedule Reminders.
    if (version_compare('4.5.0', $civiVersion) <= 0) {
      $dao = CRM_Core_DAO::executeQuery("SELECT id, title FROM civicrm_action_schedule WHERE `from_email` NOT LIKE '%{$domain}'");
      while ($dao->fetch()) {
        $error['Schedule Reminder(s)'][] = "<a target='_blank' href='" . CRM_Utils_System::url($links['Schedule Reminder(s)'], "reset=1&action=update&id={$dao->id}") . "'>{$dao->title}</a>";
      }
    }

    // Grant Application Pages.
    $query = "SELECT id FROM `civicrm_extension` WHERE full_name = 'biz.jmaconsulting.grantapplications' AND is_active = 1;";
    $dao = CRM_Core_DAO::executeQuery($query);
    if ($dao->N) {
      $links['Grant Application Page(s)'] = 'civicrm/admin/grant/thankyou';
      $grantAppParams = [
        'is_email_receipt' => 1,
        'receipt_from_email' => ["NOT LIKE" => '%' . $domain],
        'return' => ['id', 'title'],
      ];
      $result = civicrm_api3('grant_application_page', 'get', $grantAppParams);
      if ($result['count'] > 0) {
        foreach ($result['values'] as $values) {
          $error['Grant Application Page(s)'][] = "<a target='_blank' href='" . CRM_Utils_System::url($links['Grant Application Page(s)'], "reset=1&action=update&id={$values['id']}") . "'>{$values['title']}</a>";
        }
      }
    }

    list($ignore, $email) = CRM_Core_BAO_Domain::getNameAndEmail();
    $hostLength = strlen($domain);
    if (substr($email, -$hostLength) != $domain) {
      $error['Organization Address and Contact Info'][] = "<a target='_blank' href='" . CRM_Utils_System::url('civicrm/admin/domain', 'action=update&reset=1') . "'>Click Here</a>";
    }
  }

  if (!empty($error)) {
    $errorMessage = 'Please check the following configurations for emails that have an invalid domain name.<ul>';
    foreach ($error as $title => $links) {
      $errorMessage .= "<li>$title<ul>";
      foreach ($links as $link) {
        $errorMessage .= "<li>$link</li>";
      }
      $errorMessage .= '</ul></li>';
    }
    $errorMessage .= '</ul>';
    CRM_Core_Session::singleton()->setStatus($errorMessage, ts('Notice'));
  }
}

/**
 * Function to get settings value for ode_from_allowed.
 *
 * @return string
 */
function ode_get_settings_value() {
  $value = Civi::settings()->get('ode_from_allowed');
  return $value;
}

/**
 * Implements hook_civicrm_apiWrappers().
 *
 */
function ode_civicrm_apiWrappers(&$wrappers, $apiRequest) {
  if ($apiRequest['entity'] == 'OptionValue' && $apiRequest['action'] == 'get') {
    $optionGroupId = civicrm_api3('OptionGroup', 'getvalue', array(
      'return' => "id",
      'name' => "from_email_address",
    ));
    $optionGroup = CRM_Utils_Array::value(
      'option_group_id',
      CRM_Utils_Array::value('params', $apiRequest)
    );
    if (in_array($optionGroup, array($optionGroupId, 'from_email_address'))) {
      $wrappers[] = new CRM_Ode_OdeAPIWrapper();
    }
  }
}
