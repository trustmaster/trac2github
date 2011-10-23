<?php
class Github {
   public $repo;

   private $username;
   private $password;

   public function __construct($username, $password) {
      $this->username = $username;
      $this->password = $password;
   }

   public function post($url, $json, $patch = false) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_USERPWD, "$this->username:$this->password");
      curl_setopt($ch, CURLOPT_URL, "https://api.github.com$url");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_HEADER, false);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
      if ($patch) {
         curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
      }
      $ret = curl_exec($ch);
      if(!$ret) { 
           trigger_error(curl_error($ch)); 
       } 
      curl_close($ch);
      return $ret;
   }

   public function add_milestone($data) {
      return json_decode($this->post("/repos/$this->repo/milestones",
         json_encode($data)), true);
   }

   public function add_issue($data) {
      return json_decode($this->post("/repos/$this->repo/issues",
         json_encode($data)), true);
   }

   public function add_comment($issue, $body) {
      return json_decode($this->post("/repos/$this->repo/issues/$issue/comments",
         json_encode(array('body' => $body))), true);
   }

   public function update_issue($issue, $data) {
      return json_decode($this->post("/repos/$this->repo/issues/$issue",
         json_encode($data), true), true);
   }
}

