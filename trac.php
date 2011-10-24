<?php

class Trac {
   private static $db;
  
   public static function init($host, $dbname, $user, $password) {
      self::$db = new PDO(
         'mysql:host='.$host.
         ';dbname='.$dbname,
         $user,
         $password);
   }

   public static function query($statement) {
      $resultSet = self::$db->query($statement);

      return $resultSet->fetchAll();
   }

   public static function queryRow($statement) {
      $resultSet = self::$db->query($statement);

      return $resultSet->fetch();
   }
}
