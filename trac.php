<?php

class Trac {
   public static $host;
   public static $user;
   public static $db;

   private static $db;
  
   public static function init($host, $dbname, $user, $password) {
      self::$db = new PDO(
         'mysqlhost='.$host.
         ';dbname='.$dbname,
         $user,
         $password);
   }

   public static function query($statement) {
      $resultSet = self::$db->query($statement);

      return $resultSet->fetchAll();
   }
}
