<?php

use CRM_MembershipExtras_Service_MembershipEndDateCalculator as MembershipEndDateCalculator;

/**
 * Implements hook to be run before a membership is created/edited.
 */
class CRM_MembershipExtras_Hook_Pre_MembershipEdit {

  /**
   * Parameters that will be used to create the membership.
   *
   * @var array
   */
  private $params;

  /**
   * ID of the membership.
   *
   * @var int
   */
  private $id;

  /**
   * The membership payment contribution ID.
   *
   * @var int
   */
  private $paymentContributionID;

  public function __construct($id, &$params, $contributionID) {
    $this->id = $id;
    $this->params = &$params;
    $this->paymentContributionID = $contributionID;
  }

  /**
   * Preprocesses parameters used for Membership operations.
   */
  public function preProcess() {
    if ($this->paymentContributionID) {
      $this->preventExtendingPaymentPlanMembership();
    }

    $isPaymentPlanPayment = $this->isPaymentPlanWithMoreThanOneInstallment();
    $isMembershipRenewal = CRM_Utils_Request::retrieve('action', 'String') & CRM_Core_Action::RENEW;
    if ($isMembershipRenewal && $isPaymentPlanPayment) {
      $this->extendPendingPaymentPlanMembershipOnRenewal();
    }

    $this->updateMembershipPeriod();
  }

  /**
   * Prevents extending offline payment plan Membership.
   *
   * If a membership price will be paid using
   * payment plan then each time an installment get
   * paid the membership will get extended.
   * For example if you have 12 installments for
   * a 1 year membership, then each time an
   * installment get paid the membership will get extended
   * by one year, this method prevent civicrm from doing that
   * so the membership gets only extended once when you renew it.
   */
  public function preventExtendingPaymentPlanMembership() {
    if ($this->isOfflinePaymentPlanMembership()) {
      unset($this->params['end_date']);
    }
  }

  /**
   * Determines if the payment for a membership
   * subscription is offline (pay later) and paid
   * as payment plan.
   *
   * @return bool
   */
  private function isOfflinePaymentPlanMembership() {
    $recContributionID = $this->getPaymentRecurringContributionID();

    if ($recContributionID === NULL) {
      return FALSE;
    }

    return $this->isOfflinePaymentPlanContribution($recContributionID);
  }

  /**
   * Determines if the recurring contribution
   * is offline (pay later) and is for
   * a payment plan.
   *
   * @param $recurringContributionID
   * @return bool
   */
  private function isOfflinePaymentPlanContribution($recurringContributionID) {
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContributionID,
    ])['values'][0];

    $isPaymentPlanRecurringContribution = !empty($recurringContribution['installments']);

    $manualPaymentProcessors = CRM_MembershipExtras_Service_ManualPaymentProcessors::getIDs();
    $isOfflineContribution = empty($recurringContribution['payment_processor_id']) ||
      in_array($recurringContribution['payment_processor_id'], $manualPaymentProcessors);

    if ($isOfflineContribution && $isPaymentPlanRecurringContribution) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets the associated recurring contribution ID for
   * the membership payment(contribution) if it does exist.
   *
   * @return int|null
   *   The recurring contribution ID or NULL
   *   if no recurring contribution exist.
   */
  private function getPaymentRecurringContributionID() {
    $paymentContribution = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $this->paymentContributionID,
      'return' => ['id', 'contribution_recur_id'],
    ]);

    if (empty($paymentContribution['values'][0]['contribution_recur_id'])) {
      return NULL;
    }

    return $paymentContribution['values'][0]['contribution_recur_id'];
  }

  /**
   * Determines if the membership is paid using payment plan option using more
   * than one installment or not.
   *
   * @return bool
   */
  private function isPaymentPlanWithMoreThanOneInstallment() {
    $installmentsCount = CRM_Utils_Request::retrieve('installments', 'Int');
    $isSavingContribution = CRM_Utils_Request::retrieve('record_contribution', 'Int');
    $contributionIsPaymentPlan = CRM_Utils_Request::retrieve('contribution_type_toggle', 'String') === 'payment_plan';

    if ($isSavingContribution && $contributionIsPaymentPlan && $installmentsCount > 1) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Extends the membership at renewal if the selected
   * payment status is pending.
   *
   * When renewing a membership through civicrm and selecting
   * the payment status as pending, then the membership will not
   * get extended unless you marked the first payment as complete,
   * So this method make sure it get extended without the need to
   * complete the first payment.
   */
  public function extendPendingPaymentPlanMembershipOnRenewal() {
    $pendingStatusValue =  civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'contribution_status',
      'name' => 'Pending',
    ]);
    $isPaymentPending = (CRM_Utils_Request::retrieve('contribution_status_id', 'String') === $pendingStatusValue);
    if (!$isPaymentPending) {
      return;
    }

    $this->params['end_date'] = MembershipEndDateCalculator::calculate($this->id);
  }

  private function updateMembershipPeriod() {
    $membershipData = civicrm_api3('Membership', 'get', [
      'id' => $this->id,
      'sequential' => 1,
    ]);

    if (empty($this->params['join_date'])) {
      $membershipJoinDate = date('Ymd', strtotime($membershipData['values'][0]['join_date']));
    } else {
      $membershipJoinDate = date('Ymd', strtotime($this->params['join_date']));
    }

    if (!empty($this->params['end_date'])) {
      $membershipEndDate = date('Ymd', strtotime($this->params['end_date']));
    }
    elseif(!empty($this->params['membership_end_date'])) {
      $membershipEndDate = date('Ymd', strtotime($this->params['membership_end_date']));
    }
    else {
      $membershipEndDate = date('Ymd', strtotime($membershipData['values'][0]['end_date']));
    }

    $lastActivatedPeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getLastActivePeriod($this->id);

    if (empty($lastActivatedPeriod)) {
      $membershipStatuses = CRM_Member_PseudoConstant::membershipStatus();
      $pendingStatusId = array_search('Pending', $membershipStatuses);
      $cancelledStatusId = array_search('Cancelled', $membershipStatuses);

      $currentMembershipStatusId = civicrm_api3('Membership', 'getvalue', [
        'return' => 'status_id',
        'id' => $this->id,
      ]);
      $newMembershipStatusId = $this->params['status_id'];

      if (in_array($currentMembershipStatusId, [$pendingStatusId, $cancelledStatusId]) && !empty($newMembershipStatusId) && !in_array($newMembershipStatusId, [$pendingStatusId, $cancelledStatusId])) {
        $lastPeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getLastPeriod($this->id);
       CRM_MembershipExtras_BAO_MembershipPeriod::create(['id' => $lastPeriod['id'], 'is_active' => TRUE]);
      }
    }

    $firstActivatedPeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getFirstActivePeriod($this->id);
    $lastActivatedPeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getLastActivePeriod($this->id);
    if (empty($firstActivatedPeriod) || empty($lastActivatedPeriod)) {
      return;
    }


    $firstActivatedPeriodStartDate = date('Ymd', strtotime($firstActivatedPeriod['start_date']));
    $firstActivatedPeriodEndDate =  date('Ymd', strtotime($firstActivatedPeriod['end_date']));
    $lastActivatedPeriodStartDate =  date('Ymd', strtotime($lastActivatedPeriod['start_date']));
    $lastActivatedPeriodEndDate =  date('Ymd', strtotime($lastActivatedPeriod['end_date']));

    if ($membershipEndDate < $lastActivatedPeriodStartDate) {
      throw new CRM_Core_Exception('Wrong Date 1');
    }

    if ($membershipJoinDate > $firstActivatedPeriodEndDate) {
      throw new CRM_Core_Exception('Wrong Date 2');
    }

    if ($membershipEndDate > $lastActivatedPeriodStartDate && $membershipEndDate < $lastActivatedPeriodEndDate) {
      $lastActivatedPeriodParams = [];
      $lastActivatedPeriodParams['id'] = $lastActivatedPeriod['id'];
      $lastActivatedPeriodParams['end_date'] = $membershipEndDate;
      CRM_MembershipExtras_BAO_MembershipPeriod::create($lastActivatedPeriodParams);
    }

    if ($membershipJoinDate < $firstActivatedPeriodEndDate && $membershipJoinDate > $firstActivatedPeriodStartDate) {
      $firstActivatedPeriodParams = [];
      $firstActivatedPeriodParams['id'] = $firstActivatedPeriod['id'];
      $firstActivatedPeriodParams['start_date'] = $membershipJoinDate;
      CRM_MembershipExtras_BAO_MembershipPeriod::create($firstActivatedPeriodParams);
    }

    if ($membershipEndDate > $lastActivatedPeriodEndDate) {
      $newPeriodParams = [];
      $newPeriodParams['membership_id'] = $this->id;

      $endOfLastActivePeriod = new DateTime($lastActivatedPeriod['end_date']);
      $endOfLastActivePeriod->add(new DateInterval('P1D'));
      $endOfLastActivePeriodDate = $endOfLastActivePeriod->format('Y-m-d');
      $todayDate = (new DateTime())->format('Y-m-d');
      $newPeriodStartDate = $endOfLastActivePeriodDate;
      $renewalDate = CRM_Utils_Request::retrieve('renewal_date', 'String');
      if ($renewalDate) {
        $renewalDate = (new DateTime($renewalDate))->format('Y-m-d');
      } else {
        $renewalDate = $todayDate;
      }

      if ($renewalDate > $endOfLastActivePeriodDate) {
        $newPeriodStartDate = $renewalDate;
      }

      $newPeriodParams['start_date'] = $newPeriodStartDate;

      $newPeriodParams['end_date'] = $membershipEndDate;

      $membershipStatuses = CRM_Member_PseudoConstant::membershipStatus();
      $pendingStatusId = array_search('Pending', $membershipStatuses);
      $cancelledStatusId = array_search('Cancelled', $membershipStatuses);
      $statusId = $this->params['status_id'];
      $isPeriodActivated = TRUE;
      if (!empty($statusId) && in_array($statusId, [$pendingStatusId, $cancelledStatusId])) {
        $isPeriodActivated = FALSE;
      }
      $newPeriodParams['is_active'] = $isPeriodActivated;

      if ($this->paymentContributionID) {
        $paymentContribution = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'id' => $this->paymentContributionID,
          'return' => ['id', 'contribution_recur_id'],
        ]);

        if (!empty($paymentContribution['values'][0]['contribution_recur_id'])) {
          $newPeriodParams['payment_entity_table'] = 'civicrm_contribution_recur';
          $newPeriodParams['entity_id'] = $paymentContribution['values'][0]['contribution_recur_id'];
        } else {
          $newPeriodParams['payment_entity_table'] = 'civicrm_contribution';
          $newPeriodParams['entity_id'] = $this->paymentContributionID;
        }
      }

      CRM_MembershipExtras_BAO_MembershipPeriod::create($newPeriodParams);
    }

    if ($membershipJoinDate < $firstActivatedPeriodStartDate) {
      $newPeriodParams = [];
      $newPeriodParams['membership_id'] = $this->id;
      $newPeriodParams['start_date'] = $membershipJoinDate;

      $startOfFirstActivePeriod = new DateTime($firstActivatedPeriod['start_date']);
      $startOfFirstActivePeriod->sub(new DateInterval('P1D'));
      $newPeriodParams['end_date'] = $startOfFirstActivePeriod->format('Y-m-d'); // todo : does not work, fix

      $newPeriodParams['is_active'] = TRUE; // TODO : I don't think in this case the period can be inactive

      if ($this->paymentContributionID) {
        $paymentContribution = civicrm_api3('Contribution', 'get', [
          'sequential' => 1,
          'id' => $this->paymentContributionID,
          'return' => ['id', 'contribution_recur_id'],
        ]);

        if (!empty($paymentContribution['values'][0]['contribution_recur_id'])) {
          $newPeriodParams['payment_entity_table'] = 'civicrm_contribution_recur';
          $newPeriodParams['entity_id'] = $paymentContribution['values'][0]['contribution_recur_id'];
        } else {
          $newPeriodParams['payment_entity_table'] = 'civicrm_contribution';
          $newPeriodParams['entity_id'] = $this->paymentContributionID;
        }
      }


      CRM_MembershipExtras_BAO_MembershipPeriod::create($newPeriodParams);
    }
  }

}
