<?php

use CRM_MembershipExtras_Service_ContributionUtilities as ContributionUtilities;

class CRM_MembershipExtras_Job_OverdueMembershipPeriodProcessor {

  /**
   * Starts the scheduled job for disabling overdue membership
   * periods
   * 
   * @return True
   * 
   * @throws \Exception
   */
  public function run() {
    $actions = [
      [
        'action' => 'disable',
        'days' => CRM_MembershipExtras_SettingsManager::getDaysToDisableMemPeriodsWithOverduePayment(),
      ], [
        'action' => 'adjustEndDate',
        'days' => CRM_MembershipExtras_SettingsManager::getDaysToAdjustEndDateForMemPeriodsWithOverduePayment(),
      ]
    ];

    $errors = [];
    $transaction = new CRM_Core_Transaction();
    foreach ($actions as $action) {
      $overdueMembershipPeriodDao = $this->getMemPeriodsWithOverduePayment($action['days']);
      while ($overdueMembershipPeriodDao->fetch()) {
        try {
          $this->updateMembershipPeriod($overdueMembershipPeriodDao->membership_period_id, $action['action']);
        } catch (Exception $e) {
          $errors[] = "An error occurred disabling an overdue membership period with id({$membershipPeriodDao->membership_period_id}): " . $e->getMessage();
        }
      }
    }

    if (count($errors) > 0) {
      $transaction->rollback();
      $message = "Errors found while processing periods: " . implode('; ', $errors);

      throw new Exception($message);
    }
    
    $transaction->commit();

    return TRUE;
  }

  /**
   * @return CRM_Core_DAO
   *  Object that point to result set of IDs of overdue membership periods
   */
  private function getMemPeriodsWithOverduePayment($overdueDays) {
    $contributionStatusesNameMap = ContributionUtilities::getContributionStatusesNameMap();
    $completedContributionStatusID = $contributionStatusesNameMap['Completed'];

    $dateTime = new DateTime();
    $dateTime->sub(new DateInterval("P{$overdueDays}D"));
    $maxReceiveDate = $dateTime->format('Y-m-d H:i:s');

    $query = "
    (
      SELECT mmp.id as membership_period_id
        FROM membershipextras_membership_period mmp
          INNER JOIN civicrm_contribution cc ON (
            mmp.entity_id = cc.id
            AND mmp.payment_entity_table = 'civicrm_contribution'
          )
        WHERE cc.receive_date <= '{$maxReceiveDate}'
          AND cc.contribution_status_id != {$completedContributionStatusID}
          AND mmp.is_active = 1
        GROUP BY membership_period_id
    ) UNION (
      SELECT mmp.id as membership_period_id
        FROM membershipextras_membership_period mmp
          INNER JOIN civicrm_contribution_recur ccr ON (
            mmp.entity_id = ccr.id
            AND mmp.payment_entity_table = 'civicrm_contribution_recur'
          )
          INNER JOIN civicrm_contribution cc ON ccr.id = cc.contribution_recur_id
        WHERE cc.receive_date <= '{$maxReceiveDate}'
          AND ccr.contribution_status_id != {$completedContributionStatusID}
          AND mmp.is_active = 1
        GROUP BY membership_period_id
    )
    ";

    return CRM_Core_DAO::executeQuery($query);
  }

  /**
   * Disables a membership period
   * 
   * @param int $membershipPeriodID 
   * @param string $action
   */
  private function updateMembershipPeriod($membershipPeriodID, $action) {
    $membershipPeriodBao = new CRM_MembershipExtras_BAO_MembershipPeriod();
    $membershipPeriodBao->id = $membershipPeriodID;
    switch ($action) {
      case 'disable':
        $membershipPeriodBao->is_active = 0;
        break;
      case 'adjustEndDate':
        $dateTime = new DateTime();
        $dateTime->add(new DateInterval("P{$overdueDays}D"));
        $newEndDate = $dateTime->format('Y-m-d H:i:s');
        $membershipPeriodBao->end_date = $newEndDate;
        break;
      default:
        break;
    }
    $membershipPeriodBao->save();
  }
}
