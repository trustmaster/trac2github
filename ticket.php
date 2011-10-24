<?php

class Ticket {
   public static $github;

   private $id;
   private $attr;

   public static function loadFromTrac($id) {
      $q_select = "SELECT * FROM `ticket` WHERE `id` = $id";
      $result = Trac::query($q_select);
      return $result ? new self($result) : null;
   }

   public __construct($dbAttributes) {
      $this->attr = $dbAttributes;
      $this->id = $this->attr['id'];
   }

   public function toIssueJson() {
      $json = array(
         'title' => $this->attr['summary'],
         'body' => $this->translateDescription(),
         'assignee' => GitUsers::fromTrac($this->attr['owner']) ?: $this->attr['owner'],
         'milestone' => GitMilestones::fromTrac($this->attr['milestone'])
      );

      return $json;
   }

   public function saveToGithub() {
      self::github->add_issue($this->toIssueJson());
      if ($this->$attr['status'] == 'closed') {
         self::github->update_issue($this->id, array('state' => 'closed'));
      }
   }

   private function translateDescription() {
      // more on this later
      return $this->attr['description'];
   }
}
