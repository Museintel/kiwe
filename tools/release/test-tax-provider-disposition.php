<?php
require __DIR__.'/test-tax-classification.php';
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$actor=3;$caps[3][]='kiwe_manage_context';$taxReadError=false;$providerChecks=[];
function provider_check($ok,$label){$GLOBALS['providerChecks'][]=['label'=>$label,'passed'=>(bool)$ok];check($ok,$label);}
provider_check($tax->provider_disposition()['mode']==='native','Native saved rates have explicit ownership');
$wp_filter=['wc_tax_enabled'=>(object)['callbacks'=>[10=>['synthetic'=>['function'=>static function(){throw new RuntimeException('Provider invoked');}]]]]];
$r=$tax->settings();provider_check($r['provider']['mode']==='external'&&!$r['canEdit']&&!$r['effectiveKnown'],'Custom tax hook produces review-only disposition without invoking it');
$body=['key'=>'enabled','value'=>'yes','expected'=>$r['expected'],'acceptEffect'=>true];$n=$taxWrites;provider_check(is_wp_error($tax->save($body))&&$taxWrites===$n,'Custom provider blocks native global-setting mutation');
provider_check(!$tax->classes()['canEdit']&&is_wp_error($tax->create_class(['name'=>'No provider write','expected'=>$r['expected'],'acceptEffect'=>true])),'Provider guard covers class and rate workspaces');
$wp_filter=[];
if(true){class WC_Connect_TaxJar_Integration {}}
$opts['wc_connect_taxes_enabled']='no';provider_check($tax->provider_disposition()['mode']==='native','Installed but disabled Woo automation does not claim ownership');
$opts['wc_connect_taxes_enabled']='yes';provider_check($tax->provider_disposition()===['mode'=>'external','label'=>'WooCommerce Tax automation'],'Native Woo automation option identifies configured provider');
provider_check(!str_contains(json_encode($tax->settings()['provider']),'token'),'Disposition exposes no provider settings or credentials');
$opts['wc_connect_taxes_enabled']='no';provider_check($tax->provider_disposition()['mode']==='native','Fresh request recomputes ownership after native configuration changes');
echo json_encode(['passed'=>true,'checks'=>$providerChecks])."\n";
