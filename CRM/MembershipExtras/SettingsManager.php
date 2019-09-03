<?php

/**
 * Helps manage settings for the extension.
 */
class CRM_MembershipExtras_SettingsManager {
  const COLOUR_SETTINGS_KEY = 'membership_type_colour';

  /**
   * Returns the details of the default payment processor as per payment plan
   * settings, or NULL if it does not exist.
   *
   * @return int
   */
  public static function getDefaultProcessorID() {
    return self::getSettingValue('membershipextras_paymentplan_default_processor');
  }

  /**
   * Returns the 'should auto update overdue membership'
   * setting.
   *
   * @return bool
   */
  public static function shouldUpdateOverdueMembershipPeriod() {
    $autoUpdateOverdueMembershipSettingValue = (bool) self::getSettingValue('membershipextras_membership_period_rules_automatically_update_membership_period_with_overdue_payment');

    return $autoUpdateOverdueMembershipSettingValue;
  }

  /**
   * Returns the 'days to renew in advance'
   * setting.
   *
   * @return int
   */
  public static function getDaysToRenewInAdvance() {
    $daysToRenewInAdvance = self::getSettingValue('membershipextras_paymentplan_days_to_renew_in_advance');
    if (empty($daysToRenewInAdvance)) {
      $daysToRenewInAdvance = 0;
    }

    return $daysToRenewInAdvance;
  }

  /**
   * Returns the 'days to disable membership periods with overdue payment'
   * setting.
   *
   * @return int
   */
  public static function getDaysToUpdateOverdueMembershipPeriods() {
    $daysToDisableMP = (int) self::getSettingValue('membershipextras_membership_period_rules_days_to_act_on_membership_period_with_overdue_payment');

    return $daysToDisableMP;
  }

  /**
   * Returns the action to take on overdue memberships.
   * 0. for ignore, 1. for Deactivate, 2. for Update end date.
   *
   * @return int
   */
  public static function getActionToTakeOnOverdueMembershipPeriods() {
    $daysToDisableMP = (int) self::getSettingValue('membershipextras_membership_period_rules_days_to_act_on_membership_period_with_overdue_payment');

    return $daysToDisableMP;
  }

  /**
   * Returns the admin preferred end date for overdue membership periods.
   * 1. for update the membership period end date to the overdue payment receive date
   * 2. for Today (i.e, the date the membership period became 'overdue').
   *
   * @return int
   */
  public static function getEndDatePreferredForOverdueMembershipPeriods() {
    $endDate = (int) self::getSettingValue('membershipextras_membership_period_rules_update_the_period_end_date_to');

    return $endDate;
  }

  /**
   * Returns the number of days to pad the end date of overdue membership periods with.
   *
   * @return int
   */
  public static function getOffsetToPadOverdueMembershipPeriodsEndDate() {
    $offset = (int) self::getSettingValue('membershipextras_membership_period_rules_update_the_period_end_date_to');

    return $offset;
  }

  public static function getCustomFieldsIdsToExcludeForAutoRenew() {
    $customGroupsIdsToExcludeForAutoRenew = self::getSettingValue('membershipextras_customgroups_to_exclude_for_autorenew');
    if (empty($customGroupsIdsToExcludeForAutoRenew)) {
      return [];
    }

    $customFieldsToExcludeForAutoRenew = civicrm_api3('CustomField', 'get', [
      'return' => ['id'],
      'sequential' => 1,
      'custom_group_id' => ['IN' => $customGroupsIdsToExcludeForAutoRenew],
      'options' => ['limit' => 0],
    ]);
    if (empty($customFieldsToExcludeForAutoRenew['values'])) {
      return [];
    }

    $customFieldsIdsToExcludeForAutoRenew = [];
    foreach($customFieldsToExcludeForAutoRenew['values'] as $customField) {
      $customFieldsIdsToExcludeForAutoRenew[] = $customField['id'];
    }

    return $customFieldsIdsToExcludeForAutoRenew;
  }

  public static function getPaymentMethodsThatAlwaysActivateMemberships() {
    $paymentMethods = self::getSettingValue('membershipextras_paymentmethods_that_always_activate_memberships');
    if (empty($paymentMethods)) {
      return [];
    }

    return $paymentMethods;
  }

  private static function getSettingValue($settingName) {
    return civicrm_api3('Setting', 'get', [
      'sequential' => 1,
      'return' => [$settingName],
    ])['values'][0][$settingName];
  }

  /**
   * Gets the extension configuration fields
   *
   * @return array
   */
  public static function getConfigFields() {
    $allowedConfigFields = self::fetchSettingFields();
    if (!isset($allowedConfigFields) || empty($allowedConfigFields)) {
      $result = civicrm_api3('System', 'flush');
      if ($result['is_error'] == 0){
        $allowedConfigFields =  self::fetchSettingFields();
      }
    }
    return $allowedConfigFields;
  }

  private static function fetchSettingFields() {
    return civicrm_api3('Setting', 'getfields',[
      'filters' =>[ 'group' => 'membershipextras_paymentplan'],
    ])['values'];
  }

  /**
   * Receives a background color in hexadecimal format and determines
   * what the text colour should be based on the intensity of the background
   * colour. Returns black or white in hex format.
   *
   * @param string $hex
   *
   * @return string
   */
  public static function computeTextColor($hex) {
    if ($hex == 'inherit') {
      return 'inherit';
    }

    list($r, $g, $b) = array_map('hexdec', str_split(trim($hex, '#'), 2));
    $uiColours = [$r / 255, $g / 255, $b / 255];
    $c = array_map('self::calcColour', $uiColours);

    $luminance = (0.2126 * $c[0]) + (0.7152 * $c[1]) + (0.0722 * $c[2]);

    return ($luminance > 0.179) ? '#000000' : '#ffffff';
  }

  /**
   * Calculate colour for RGB values.
   *
   * @param string $c
   *
   * @return float|int
   */
  private static function calcColour($c) {
    if ($c <= 0.03928) {
      return $c / 12.92;
    }
    else {
      return pow(($c + 0.055) / 1.055, 2.4);
    }
  }

}
