<?php

/**
 * Post processes membership payments after creation or update.
 */
class CRM_MembershipExtras_Hook_Post_MembershipPayment {

  /**
   * Operation being done on the line item
   *
   * @var string
   */
  private $operation;

  /**
   * ID of the record.
   *
   * @var int
   */
  private $id;

  /**
   * Reference to BAO.
   *
   * @var \CRM_Member_DAO_MembershipPayment
   */
  private $membershipPayment;

  /**
   * Array with the membership's data.
   *
   * @var array
   */
  private $membership;

  /**
   * Array with the contribution's data.
   *
   * @var array
   */
  private $contribution;

  /**
   * Array with the recurring contribution's data.
   *
   * @var array
   */
  private $recurringContribution;

  private static $paymentIds = [];

  private $periodId;

  /**
   * CRM_MembershipExtras_Hook_Post_MembershipPayment constructor.
   *
   * @param $operation
   * @param $objectId
   * @param \CRM_Member_DAO_MembershipPayment $objectRef
   */
  public function __construct($operation, $objectId, CRM_Member_DAO_MembershipPayment $objectRef, $periodId = NULL) {
    $this->operation = $operation;
    $this->id = $objectId;
    self::$paymentIds[] = $objectId;
    $this->membershipPayment = $objectRef;
    $this->periodId = $periodId;

    $this->membership = civicrm_api3('Membership', 'getsingle', [
      'id' => $this->membershipPayment->membership_id,
    ]);

    $this->contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $this->membershipPayment->contribution_id,
    ]);

    if (!empty($this->contribution['contribution_recur_id'])) {
      $this->recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $this->contribution['contribution_recur_id'],
      ]);
    }
  }

  /**
   * Post-processes a membership payment on creation and update.
   */
  public function postProcess() {
    if ($this->operation == 'create') {
      $this->fixRecurringLineItemMembershipReferences();
      $this->createMissingPeriod();
      $this->linkPaymentToMembershipPeriod();
    }
  }

  /**
   * Ugh... There is a bug/feature of CiviCRM where line items for memberships
   * are created with the first membership in a price set where several
   * memberships are used, and then the real membership is set via direct SQL
   * query... So we need to calculate real membership ID for recurring
   * line items, otherwise they will all reference one membership.
   *
   * Bug seen as late as v5.4 of CiviCRM.
   *
   * See: https://github.com/civicrm/civicrm-core/blob/5.4.0/CRM/Member/BAO/MembershipPayment.php#L72-L95
   */
  private function fixRecurringLineItemMembershipReferences() {
    $lineItem = $this->getRelatedRecurringLineItem();
    $entityTable = CRM_Utils_Array::value('entity_table', $lineItem, '');
    $entityID = CRM_Utils_Array::value('entity_id', $lineItem, 0);

    if ($entityID && $entityTable == 'civicrm_membership' && $entityID != $this->membershipPayment->membership_id) {
      $sql = "
        UPDATE civicrm_line_item 
        SET entity_table = 'civicrm_membership', entity_id = %1
        WHERE id = %2
      ";
      CRM_Core_DAO::executeQuery($sql, [
        1 => [$this->membershipPayment->membership_id, 'Integer'],
        2 => [$lineItem['id'], 'Integer'],
      ]);
    }
  }

  /**
   * Obtains recurring line item that matches the membership type of the current
   * payment, by looking at the membership type in the line item's price field
   * value.
   *
   * @return array
   */
  private function getRelatedRecurringLineItem() {
    $membershipTypeID = $this->membership['membership_type_id'];
    $recurringContributionID = $this->contribution['contribution_recur_id'];

    $recurringLineItems = civicrm_api3('ContributionRecurLineItem', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1]
      ],
    ]);

    if ($recurringLineItems['count'] > 0) {
      foreach ($recurringLineItems['values'] as $lineItem) {
        $priceFieldValueID = CRM_Utils_Array::value('price_field_value_id', $lineItem['api.LineItem.getsingle'], 0);
        if (!$priceFieldValueID) {
          continue;
        }

        $priceFieldValueData = civicrm_api3('PriceFieldValue', 'getsingle', [
          'id' => $lineItem['api.LineItem.getsingle']['price_field_value_id'],
        ]);

        if (CRM_Utils_Array::value('membership_type_id', $priceFieldValueData, 0) == $membershipTypeID) {
          return $lineItem['api.LineItem.getsingle'];
        }
      }
    }

    return [];
  }

  private function createMissingPeriod() {
    // todo : explain the purpose of this
    // it was done since the membership payment hook might be called twice on payment creation
    // so it ensure that the period did not get created twice
    $counts = array_count_values(self::$paymentIds);
    $hookCallCountsForId = $counts[$this->id];
    if ($hookCallCountsForId > 1) {
      return;
    }

    $contributionStatus = $this->contribution['contribution_status'];
    if ($contributionStatus == 'Pending') {
      if(!empty($this->recurringContribution) && empty($this->membership['contribution_recur_id'])) {
        // todo : make it this more efficent for payment plans
        $contributionsCount = civicrm_api3('Contribution', 'getcount', [
          'contribution_recur_id' => $this->contribution['contribution_recur_id'],
        ]);
        $isFirstPPContribution = ($contributionsCount == 1);
        if ($isFirstPPContribution) {
          if ($this->periodId) {
            $newPeriodParams['id'] = $this->periodId;
            $newPeriodParams['is_active'] = FALSE;
            CRM_MembershipExtras_BAO_MembershipPeriod::create($newPeriodParams);
          } else {
            $this->createPendingMissingPeriod();
          }
        }
      } else {
        // if the period is already created then just deactivate it since
        // periods are active by default.
        if ($this->periodId) {
          $newPeriodParams['id'] = $this->periodId;
          $newPeriodParams['is_active'] = FALSE;
          CRM_MembershipExtras_BAO_MembershipPeriod::create($newPeriodParams);
        } else {
          $this->createPendingMissingPeriod();
        }
      }
    }
  }

  private function createPendingMissingPeriod() {
    $newPeriodParams = [];
    $newPeriodParams['membership_id'] = $this->membershipPayment->membership_id;

    $membershipId = $this->membershipPayment->membership_id;
    $lastActivePeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getLastActivePeriod($membershipId);
    if (!empty($lastActivePeriod['end_date'])) {
      $today = new DateTime();
      $endOfLastActivePeriod = new DateTime($lastActivePeriod['end_date']);
      $endOfLastActivePeriod->add(new DateInterval('P1D'));
      if ($endOfLastActivePeriod > $today) {
        $newPeriodParams['start_date'] =  $endOfLastActivePeriod->format('Y-m-d');
      } else {
        $newPeriodParams['start_date'] = $today->format('Y-m-d');
      }
    } else {
      $newPeriodParams['start_date'] = $this->membership['join_date'];
    }

    $membershipDetails = civicrm_api3('Membership', 'get', [
      'sequential' => 1,
      'return' => ['membership_type_id.duration_unit', 'membership_type_id.duration_interval'],
      'id' => $membershipId,
    ])['values'][0];
    $currentStartDate = new DateTime($newPeriodParams['start_date']);
    //todo: handle lifetime
    switch ($membershipDetails['membership_type_id.duration_unit']) {
      case 'month':
        $interval = 'P' . $membershipDetails['membership_type_id.duration_interval'] . 'M';
        break;
      case 'day':
        $interval = 'P' . $membershipDetails['membership_type_id.duration_interval'] .'D';
        break;
      case 'year':
        $interval = 'P' . $membershipDetails['membership_type_id.duration_interval'] .'Y';
        break;
    }
    $currentStartDate->add(new DateInterval($interval));
    $currentStartDate->sub(new DateInterval('P1D'));
    $newPeriodParams['end_date'] = $currentStartDate->format('Ymd');

    $newPeriodParams['is_active'] = FALSE;

    CRM_MembershipExtras_BAO_MembershipPeriod::create($newPeriodParams);
  }

  private function linkPaymentToMembershipPeriod() {
    $membershipId = $this->membershipPayment->membership_id;
    $lastMembershipPeriod = CRM_MembershipExtras_BAO_MembershipPeriod::getLastPeriod($membershipId);

    if (empty($lastMembershipPeriod['entity_id'])) {
      if(!empty($this->recurringContribution)) {
        // todo : only perform once for payment plan conts / for perfromace reasons but has not effect on records
        $periodNewParams = [
          'id' => $lastMembershipPeriod['id'],
          'payment_entity_table' => 'civicrm_contribution_recur',
          'entity_id' => $this->recurringContribution['id'],
        ];
      } else {
        $periodNewParams = [
          'id' => $lastMembershipPeriod['id'],
          'payment_entity_table' => 'civicrm_contribution',
          'entity_id' => $this->contribution['id'],
        ];
      }

      $membershipPeriod = new CRM_MembershipExtras_BAO_MembershipPeriod();
      $membershipPeriod::create($periodNewParams);
    }
  }

}
