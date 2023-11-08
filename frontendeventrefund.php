<?php

require_once 'frontendeventrefund.civix.php';
use CRM_Frontendeventrefund_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function frontendeventrefund_civicrm_config(&$config) {
  _frontendeventrefund_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function frontendeventrefund_civicrm_install() {
  _frontendeventrefund_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function frontendeventrefund_civicrm_enable() {
  _frontendeventrefund_civix_civicrm_enable();
}

function frontendeventrefund_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Event_Form_SelfSvcUpdate') {
    // supress alert
    $form->assign('contributionId', NULL);
  }
}

function frontendeventrefund_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Event_Form_SelfSvcUpdate') {
    $params = $form->controller->exportValues();
    if ($params['action'] == 2) {
      // Change Fee
      $participantID = $form->getVar('_participant_id');
      $contributionID = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $participantID, 'contribution_id', 'participant_id');
      $contactID = CRM_Core_DAO::getFieldValue('CRM_Contribute_BAO_Contribution', $contributionID, 'contact_id');
      $priceSetID = CRM_Core_DAO::singleValueQuery("
          SELECT pf.price_set_id
          FROM civicrm_line_item li INNER JOIN civicrm_price_field pf ON li.price_field_id = pf.id AND li.contribution_id = $contributionID
         LIMIT 1");

      $urlParams = "reset=1&cid={$contactID}&selectedChild=contribute";
      $url = CRM_Utils_System::url('civicrm/contact/view', $urlParams);
      $payment = Civi\Payment\System::singleton()->getByName('Moneris', 0);
      try {
        $paymentParams = $payment->refundPayment(array('contribution_id' => $contributionID));
      }
      catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
        CRM_Core_Error::statusBounce($e->getMessage(), $url, ts('Payment Processor Error'));
      }

      $feeBlock = CRM_Price_BAO_PriceSet::getSetDetail($priceSetID, TRUE, FALSE)[$priceSetID]['fields'];
      $lineItems = CRM_Price_BAO_LineItem::getLineItems($participantID, 'participant');
      foreach ($lineItems as $id => $lineItem) {
        $lineItems[$id]['qty'] = $lineItems[$id]['line_total'] = 0;
      }
      $p = [];
      CRM_Price_BAO_LineItem::changeFeeSelections($p, $participantID, 'participant', $contributionID, $feeBlock, $lineItems);
      $paymentDetails = getTransactionDetails($contributionID, 'Completed');
      // Get chapter and fund data for previous trxn.
      $chapterFund = CRM_Core_DAO::executeQuery("SELECT chapter_code, fund_code FROM civicrm_chapter_entity WHERE entity_id = {$paymentDetails['id']} AND entity_table = 'civicrm_financial_trxn'")->fetchAll();
      if (!empty($chapterFund)) {
        $chapterFund = $chapterFund[0];
      }
      unset($paymentDetails['id']);
      $paymentDetails['trxn_date'] = CRM_Utils_Array::value('trxn_date', $paymentDetails, date('YmdHis'));
      /**
      // Submit refund payment
      $contributionDetails = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID]);
      $paymentParams = array_merge([
        'amount' => $paymentDetails['total_amount'],
        'contactID' => $contributionDetails['contact_id'],
      ], $contributionDetails);

      if ($paymentProcessorID = CRM_Utils_Array::value('payment_processor_id', $paymentDetails)) {
        $payment = Civi\Payment\System::singleton()->getByProcessor(CRM_Financial_BAO_PaymentProcessor::getPayment($paymentProcessorID));
        if ($payment->supportsRefund()) {
          $payment->doRefund($paymentParams);
        }
      }
*/
      // Handle financial records
      $paymentDetails = array_merge(
        $paymentDetails,
        [
          'trxn_id' => $paymentParams['trxn_id'],
        ]
      );
      $result = CRM_Contribute_BAO_Contribution::recordAdditionalPayment($contributionID, $paymentDetails, 'refund');
      $params = array('id' => $contributionID);
      $contribution = CRM_Contribute_BAO_Contribution::retrieve($params, $defaults, $params);
      CRM_Contribute_BAO_Contribution::addPayments(array($contribution), 7);
    }
  }
}

function getTransactionDetails($contributionID, $status) {
  return CRM_Core_DAO::executeQuery("SELECT ft.*, cc.contact_id FROM civicrm_financial_trxn ft
    INNER JOIN civicrm_entity_financial_trxn eft ON (eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution')
    INNER JOIN civicrm_contribution cc ON cc.id = eft.entity_id
      WHERE eft.entity_id = %1 AND ft.is_payment = 1 AND ft.status_id = %2
      LIMIT 1
    ", [
      1 => [$contributionID, 'Integer'],
      2 => [CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $status), 'Integer'],
    ])->fetchAll()[0];
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function frontendeventrefund_civicrm_navigationMenu(&$menu) {
  _frontendeventrefund_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _frontendeventrefund_civix_navigationMenu($menu);
} // */
