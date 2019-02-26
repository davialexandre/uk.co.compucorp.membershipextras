<?php

class CRM_MembershipExtras_Hook_Post_MembershipCreate {

  private $membership;

  public function __construct($membership) {
    $this->membership = $membership;
  }

  public function process() {
    $this->createMembershipPeriod();
  }

  private function createMembershipPeriod() {
    CRM_MembershipExtras_BAO_MembershipPeriod::createPeriodForMembership($this->membership->id);
  }

}
