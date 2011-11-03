<?php

/**
 * Generates a list of unique labels and adds them to github
 */
class Labels {
   private static $columnNames = array(
      'component',
      'resolution',
      'priority',
      'component'
   );

   public static function createIfMissing() {
      $labels = array(); 
      foreach(self::$columnNames as $column) {
         $labels = array_merge($labels,
            self::getLabelsForColumn($column));
      }

      foreach ($labels as $label) {

      }
   }

   private static function getUniques($column) {
      $q_unique = "SELECT DISTINCT `$column` FROM tickets;";
      return Trac::query($q_unique);
   }

   private static function getLabelsForColumn($columnName) {
      $values = self::getUniques($columnName);
      foreach($values as $value) {
         $labels[] = call_user_func(array('LabelTransformer', $columnName), $value);
      }
      return $labels;
   }
}

/**
 * Provides functions to transform Trac fields into Gitub Issue Labels
 */
class LabelTransformer {
   public static function component($component) {
      return array(
         'name' => "C-" . $component,
         'color' => "#ddddd"
      );
   }

   public static function priority($priority) {
      return array(
         'name' => "P-" . $priority,
         'color' => "#ddddd"
      );
   }

   public static function resolution($resolution) {
      return array(
         'name' => "C-" . $resolution,
         'color' => "#ddddd"
      );
   }

   public static function type($type) {
      return array(
         'name' => "C-" . $type,
         'color' => "#ddddd"
      );
   }
}
