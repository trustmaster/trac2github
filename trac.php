<?php

class Trac {

   public static $host;
   public static $user;
   public static $db;

   private static $db;
  
   public static function init() {
      self::$db = new PDO(
         'mysqlhost='.self::$host.
         ';dbname='.self::$db,i
         self::$user,
         self::$password);
   }
}
