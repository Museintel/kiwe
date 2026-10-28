<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require __DIR__.'/fixtures/phonekey-functions.php';
require __DIR__.'/fixtures/phonekey-webauthn.php';
function home_url($path=''){return'https://credential-fixture.example.invalid/'.$path;}function wp_parse_url($v){return parse_url($v);}function wp_json_encode($v){return json_encode($v);}
$source=file_get_contents(dirname(__DIR__,2).'/wp-content/mu-plugins/dsa/includes/PhoneKey/phonekey-core.php');
$start=strpos($source,'class PK_Spomky_Cbor_ByteStream');$end=strpos($source,'function pk_der_len');
if($start===false||$end===false||$end<=$start)throw new RuntimeException('Actual native CBOR class boundary required');eval(substr($source,$start,$end-$start));
foreach(['pk_b64u','pk_b64u_dec','pk_der_len','pk_der','pk_der_oid','pk_der_int','pk_cose_to_pem','pk_expected_origin','pk_rp_id','pk_parse_registration','pk_verify_client_data']as$name){$code=kiwe_phonekey_function($source,$name);if($code===null)throw new RuntimeException('Actual crypto function missing');eval($code);}
$checks=[];function crypto_check($ok,$label){$GLOBALS['checks'][$label]=(bool)$ok;}
$authenticator=kiwe_pt2_authenticator();$options=['token'=>'synthetic-token','publicKey'=>['challenge'=>pk_b64u(random_bytes(32))]];
$registration=kiwe_pt2_registration($options,$authenticator);$parsed=pk_parse_registration($registration['credential']['response']['attestationObject']);
crypto_check($parsed['credential_id']===pk_b64u($authenticator['id'])&&$parsed['alg']===-7,'Actual native CBOR registration parser preserves the generated credential and ES256 algorithm');
crypto_check(($parsed['flags']&0x45)===0x45,'Synthetic registration carries native user-presence verification and attested-key flags');
$client=pk_verify_client_data($registration['credential']['response']['clientDataJSON'],'webauthn.create',$options['publicKey']['challenge']);
crypto_check(json_decode($client,true)['origin']===pk_expected_origin(),'Actual native registration client-data challenge and origin checks pass');
$assertion=kiwe_pt2_assertion($options,$authenticator,1);$response=$assertion['credential']['response'];$client=pk_verify_client_data($response['clientDataJSON'],'webauthn.get',$options['publicKey']['challenge']);$auth=pk_b64u_dec($response['authenticatorData']);$signed=$auth.hash('sha256',$client,true);$pem=pk_cose_to_pem($parsed['public_cose']);
crypto_check(openssl_verify($signed,pk_b64u_dec($response['signature']),$pem,OPENSSL_ALGO_SHA256)===1,'Actual native COSE public key verifies the fixture assertion signature');
crypto_check(openssl_verify($signed.'tampered',pk_b64u_dec($response['signature']),$pem,OPENSSL_ALGO_SHA256)===0,'Actual native public key rejects a tampered assertion');
$bad=kiwe_pt2_assertion($options,$authenticator,2,'https://unrelated.example.invalid');$denied=false;try{pk_verify_client_data($bad['credential']['response']['clientDataJSON'],'webauthn.get',$options['publicKey']['challenge']);}catch(Throwable$e){$denied=true;}
crypto_check($denied,'Actual native client-data parser rejects a separately signed wrong origin');
$denied=false;try{pk_verify_client_data($response['clientDataJSON'],'webauthn.get','another-challenge');}catch(Throwable$e){$denied=true;}
crypto_check($denied,'Actual native client-data parser rejects the wrong challenge');
crypto_check(strlen($auth)===37&&substr($auth,0,32)===hash('sha256',pk_rp_id(),true)&&unpack('N',substr($auth,33,4))[1]===1,'Synthetic assertion uses native RP hash and signature-counter encoding');
$failed=array_keys(array_filter($checks,fn($v)=>!$v));echo json_encode(['passed'=>!$failed,'checks'=>$checks,'failed'=>$failed,'scope'=>'Actual extracted native crypto methods and in-memory OpenSSL EC/CBOR fixtures; no physical authenticator or account']).PHP_EOL;exit($failed?1:0);
