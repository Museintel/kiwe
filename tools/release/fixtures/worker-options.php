<?php
/** Deterministic option SQL fixture; never included by runtime code. */
namespace DSA\Notifications {
	function time(): int { return $GLOBALS['worker_clock'] ?? \time(); }
	function microtime(bool $float=false) { return isset($GLOBALS['worker_elapsed']) ? (float)$GLOBALS['worker_elapsed'] : \microtime($float); }
}
namespace {
	if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
	if(!function_exists('wp_cache_delete')){function wp_cache_delete($key,$group=''){return true;}}
	if(!function_exists('wp_clear_scheduled_hook')){function wp_clear_scheduled_hook($hook,$args=[]){$GLOBALS['events']=array_values(array_filter($GLOBALS['events']??[],static fn($e)=>$e[1]!==$hook||$e[2]!==$args));return true;}}
	final class Kiwe_Worker_Test_DB {
		public string $options='wp_options',$prefix='wp_',$last_error='';
		public bool $failRead=false,$failWrite=false,$conflict=false;
		public $beforeWrite=null;
		public array $deviceUpdates=[];
		public function suppress_errors($value=true){return true;}
		public function prepare(string $sql,...$args):array{return[$sql,$args];}
		public function get_row(array $q,$format=null):?array{
			$this->last_error=$this->failRead?'Synthetic read failure':'';if($this->failRead)return null;
			if(str_contains($q[0],'SELECT failure_count'))return['failure_count'=>0];
			$key=$q[1][0];if(!array_key_exists($key,$GLOBALS['options']))return null;
			$v=$GLOBALS['options'][$key];return['option_value'=>is_array($v)?serialize($v):$v];
		}
		public function query(array $q){
			if($this->beforeWrite){$pause=$this->beforeWrite;$this->beforeWrite=null;$pause($q);}
			$this->last_error=$this->failWrite?'Synthetic write failure':'';if($this->failWrite)return false;if($this->conflict)return 0;
			[$sql,$args]=$q;
			if(str_starts_with($sql,'INSERT IGNORE')){[$key,$next]=$args;if(array_key_exists($key,$GLOBALS['options']))return 0;}
			elseif(str_starts_with($sql,'UPDATE ')){[$next,$key,$before]=$args;if(!$this->matches($sql,$key,$before)||$next===$before)return 0;}
			elseif(str_starts_with($sql,'DELETE ')){[$key,$before]=$args;if(!$this->matches($sql,$key,$before))return 0;unset($GLOBALS['options'][$key]);return 1;}
			else throw new \RuntimeException('Unexpected worker SQL');
			$GLOBALS['options'][$key]=unserialize($next,['allowed_classes'=>false]);return 1;
		}
		private function matches(string$sql,string$key,string$before):bool{
			if(!str_contains($sql,'BINARY option_value=BINARY %s'))throw new \RuntimeException('Exact native comparison required');
			if(!array_key_exists($key,$GLOBALS['options']))return false;$v=$GLOBALS['options'][$key];return(is_array($v)?serialize($v):$v)===$before;
		}
		public function get_results(array$q,$format=null):array{
			$this->last_error='';$after=(int)$q[1][0];return array_slice(array_values(array_filter($GLOBALS['push_rows']??[],static fn($r)=>$r['id']>$after)),0,5);
		}
		public function update($table,$values,$where){$this->deviceUpdates[]=['table'=>$table,'values'=>$values,'where'=>$where];return 1;}
	}
	$GLOBALS['wpdb']=new Kiwe_Worker_Test_DB();
	require_once dirname(__DIR__,3).'/wp-content/mu-plugins/dsa/includes/Notifications/Worker_Job.php';
}
