<?php
/** Native mysqli adapter for the capture fixture. SQL executes on the real server; WordPress is not booted. */
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
if(!defined('ARRAY_N'))define('ARRAY_N','ARRAY_N');
final class wpdb {
 public string $prefix,$base_prefix,$last_error='';
 private mysqli $connection;
 private bool $suppressed=false;
 public function __construct(array$config,string$prefix){$this->prefix=$this->base_prefix=$prefix;$this->connection=new mysqli($config['host'],$config['username'],$config['password'],$config['database'],$config['port']);$this->connection->set_charset('utf8mb4');$this->connection->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO'");$this->connection->query("SET SESSION time_zone='+05:30'");}
 public function suppress_errors($value=true){$old=$this->suppressed;$this->suppressed=(bool)$value;return$old;}
 public function prepare($sql,...$values){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$values){$v=$values[$i++];return$m[0]==='%d'?(string)(int)$v:"'".$this->connection->real_escape_string((string)$v)."'";},$sql);}
 public function query($sql){try{$r=$this->connection->query($sql);if($r instanceof mysqli_result)$r->free();return$this->connection->affected_rows;}catch(Throwable$e){$this->last_error='Native fixture SQL refused';return false;}}
 public function get_results($sql,$output=null){try{$r=$this->connection->query($sql);if(!$r instanceof mysqli_result)return[];$rows=$r->fetch_all($output===ARRAY_A?MYSQLI_ASSOC:MYSQLI_NUM);$r->free();return$rows;}catch(Throwable$e){$this->last_error='Native fixture SQL refused';return false;}}
 public function get_var($sql){$r=$this->get_results($sql);return$r[0][0]??null;}
 public function close():void{$this->connection->close();}
}
