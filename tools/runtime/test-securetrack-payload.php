<?php
declare(strict_types=1);
/** Executes the actual parser, independently of request admission or IP changes. */
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
$from=strpos($source,'function stp_waf_normalize(');$to=strpos($source,'function stp_request_payload(',$from);
if($from===false||$to===false)throw new RuntimeException('Native payload parser source missing.');
eval(substr($source,$from,$to-$from));
$checks=[];$warnings=[];set_error_handler(static function($level,$message)use(&$warnings){$warnings[]=$message;return true;});
$check=static function(bool $ok,string $why)use(&$checks){$checks[$why]=$ok;if(!$ok)throw new RuntimeException($why);};
try{
 $attacks=[
  'plain union'=>'id=1 UNION SELECT password FROM wp_users',
  'block comments'=>'id=1 UNION/**/ALL/* normal */SELECT password FROM wp_users',
  'executable comment'=>'id=1 /*!50000UNION SELECT password FROM wp_users*/',
  'line comment'=>"id=1 UNION-- comment\nSELECT password FROM wp_users",
  'hash comment'=>"id=1 UNION# comment\nSELECT password FROM wp_users",
  'encoded'=>'id=1%20%55NION%20SELECT%20password%20FROM%20wp_users',
  'double encoded'=>'id=1%2520UNION%2520SELECT%2520password%2520FROM%2520wp_users',
  'unicode'=>'id=1 ＵＮＩＯＮ ＳＥＬＥＣＴ password FROM wp_users',
  'encoded unicode'=>rawurlencode('id=1 ＵＮＩＯＮ ＳＥＬＥＣＴ password FROM wp_users'),
  'zero width'=>"id=1 U\u{200b}NION SELECT password FROM wp_users",
  'encoded zero width'=>rawurlencode("id=1 U\u{200b}NION SELECT password FROM wp_users"),
  'invalid utf8'=>"id=1 \xff UNION SELECT password FROM wp_users",
  'delay expression'=>'id=1 AND SLEEP(5)',
  'subquery delay'=>'id=(SELECT SLEEP(5))',
  'schema'=>'id=1 UNION SELECT name FROM information_schema.tables',
  'php execution'=>'q=system($_GET["cmd"])',
 ];
 foreach($attacks as$name=>$text){$r=stp_scan_payload($text);$check($r['score']>=80,'High-confidence payload remains denied: '.$name);}
 $safe=[
  'editorial'=>'Select your favourite sweets from our range. Select the best products from local makers.',
  'block editor'=>'<!-- wp:paragraph --><p>Select your size from our available products.</p><!-- /wp:paragraph -->',
  'checkout'=>'billing_first_name=Sample&billing_last_name=Shopper&order_comments=Select a suitable day from Monday or Tuesday',
  'contact'=>'name=Sample&message=Please select the right product from the range and call me.',
  'bricks'=>json_encode(['action'=>'bricks_save_post','elements'=>[['name'=>'code','settings'=>['javascriptCode'=>'const sleep = ms => new Promise(resolve => setTimeout(resolve, ms)); document.querySelector(".card").addEventListener("click", () => window.location.href="/shop/");','css'=>'#brxe-card { color: #abc; --card-space: 1rem; }']]]]),
  'plain read expression'=>'Select product from the list.',
  'native identifiers'=>'client_id=c_01234567890123456789012345678901&scope=mcp.read mcp.setup.write',
  'link fragments'=>'https://example.invalid/catalogue/#featured-products',
 ];
 foreach($safe as$name=>$text)$check(stp_scan_payload($text)['score']<80,'Normal workflow remains below default denial: '.$name);
 foreach(['#abc','--card-space','brand--name']as$value)$check(str_contains(stp_waf_normalize($value),$value),'Normal data is retained: '.$value);
 $check(stp_scan_payload('<script>document.cookie</script>')['flags']['semantic_xss']??false,'Existing XSS signal retained');
 $check(stp_scan_payload('../etc/passwd')['flags']['traversal_payload']??false,'Existing traversal signal retained');
 $check(stp_scan_payload('Select product from stock')['score']===25,'Standalone read syntax is a weak signal, not a block');
 $check($warnings===[],'Actual parser emits no regex or invalid-UTF8 warnings');
 echo json_encode(['passed'=>true,'checks'=>$checks,'warnings'=>count($warnings)],JSON_UNESCAPED_SLASHES)."\n";
}finally{restore_error_handler();}
