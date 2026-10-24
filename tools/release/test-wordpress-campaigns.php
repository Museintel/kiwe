<?php
/** Included within the disposable native workspace fixture; no real delivery occurs. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_WORKSPACE_TEST_SITE' ) || true !== KIWE_WORKSPACE_TEST_SITE ) exit(2);

$groupOption=\DSA\Access\User_Group_Service::OPTION;
$draftOption=\DSA\Access\Campaign_Workspace_Service::OPTION;
foreach ( [ $groupOption,$draftOption ] as $option ) {
	if ( ! array_key_exists( $option,$savedContextOptions ) ) $savedContextOptions[$option]=get_option($option,null);
	$before[$option]=hash('sha256',serialize(get_option($option)));
}
wp_set_current_user($users['client']);

$roles=$dispatch('GET','team',null,true,true,['team_view'=>'accounts','team_role'=>'subscriber']);
$roleData=$roles->get_data();
$subscriberChoices=array_values(array_filter((array)($roleData['roleChoices']??[]),static fn($choice)=>($choice['id']??'')==='subscriber'));
$assert($roles->get_status()===200&&count($subscriberChoices)===1&&($subscriberChoices[0]['count']??0)>=1&&in_array($users['visitor'],array_column((array)($roleData['people']??[]),'id'),true),'Users role filter includes subscriber count and native subscriber account');

$list=$dispatch('GET','user-groups');
$assert($list->get_status()===200&&count(array_filter($list->get_data()['groups']??[],static fn($g)=>!empty($g['smart'])))===4,'Four smart audiences are available without manual enrollment');
$assert($dispatch('GET','user-groups',null,false)->get_status()===403&&$dispatch('POST','user-groups',['name'=>'Denied'],true,false)->get_status()===403,'User groups require nonce and explicit same-site mutation');
$one=$dispatch('POST','user-groups',['name'=>'Kiwe QA readers '.$prefix]);
$two=$dispatch('POST','user-groups',['name'=>'Kiwe QA writers '.$prefix]);
$assert($one->get_status()===200&&$two->get_status()===200,'Native user groups are created through the REST boundary');
$g1=$one->get_data();$g2=$two->get_data();
$detailPath='user-groups/'.$g1['id'];
$readerProof=\DSA\Access\User_Group_Service::member_proof($users['visitor']);
$added=$dispatch('POST',$detailPath,['operation'=>'add','expected'=>$g1['expected'],'people'=>[['id'=>$users['visitor'],'expected'=>$readerProof]]]);
$assert($added->get_status()===200&&in_array($g1['id'],\DSA\Access\User_Group_Service::memberships($users['visitor']),true),'Adding a subscriber changes group membership only');
$assert($dispatch('POST',$detailPath,['operation'=>'add','expected'=>$g1['expected'],'people'=>[['id'=>$users['visitor'],'expected'=>$readerProof]]])->get_status()===409,'Stale member proof prevents a silent duplicate membership mutation');
$moved=$dispatch('POST',$detailPath,['operation'=>'move','expected'=>$g1['expected'],'target'=>$g2['id'],'targetExpected'=>$g2['expected'],'people'=>[['id'=>$users['visitor'],'expected'=>\DSA\Access\User_Group_Service::member_proof($users['visitor'])]]]);
$members=\DSA\Access\User_Group_Service::memberships($users['visitor']);
$assert($moved->get_status()===200&&!in_array($g1['id'],$members,true)&&in_array($g2['id'],$members,true)&&in_array('subscriber',(array)get_userdata($users['visitor'])->roles,true),'Moving groups preserves the native WordPress role');
$smartDenied=$dispatch('POST','user-groups/app',['operation'=>'add','expected'=>'','people'=>[['id'=>$users['visitor'],'expected'=>\DSA\Access\User_Group_Service::member_proof($users['visitor'])]]]);
$assert($smartDenied->get_status()===403,'Browser/PWA audience cannot be assigned by an administrator');
$renamed=$dispatch('POST','user-groups/'.$g2['id'],['operation'=>'rename','expected'=>$g2['expected'],'name'=>'Kiwe QA audience '.$prefix]);
$assert($renamed->get_status()===200&&($renamed->get_data()['name']??'')==='Kiwe QA audience '.$prefix,'Group rename readback confirms the saved name');
$groupJson=wp_json_encode($dispatch('GET','user-groups/'.$g2['id'])->get_data());
$assert(!str_contains($groupJson,$prefix.'visitor@example.invalid')&&str_contains($groupJson,$g2['id']),'Group detail exposes membership without contact data');

$campaign=$dispatch('POST','campaigns',['operation'=>'save','name'=>'Kiwe QA newsletter '.$prefix,'type'=>'newsletter','sourceId'=>0,'group'=>$g2['id'],'channels'=>['email'],'copies'=>['email'=>['title'=>'Fixture article','body'=>'Fixture notice for native QA.']],'scheduledAt'=>0]);
$assert($campaign->get_status()===200&&($campaign->get_data()['status']??'')==='draft','Campaign creates a draft without delivery');
$draft=$campaign->get_data();
$assert($dispatch('POST','campaigns',['operation'=>'save','id'=>$draft['id'],'expected'=>str_repeat('0',64),'name'=>'Stale','type'=>'newsletter','sourceId'=>0,'group'=>$g2['id'],'channels'=>['email'],'copies'=>['email'=>['title'=>'Fixture article','body'=>'Fixture notice']],'scheduledAt'=>0])->get_status()===409,'Campaign edit rejects a stale draft proof');
$preview=$dispatch('POST','campaigns',['operation'=>'preview','id'=>$draft['id'],'expected'=>$draft['expected']]);
$pv=$preview->get_data();
$assert($preview->get_status()===200&&isset($pv['expected'],$pv['counts'],$pv['excluded'])&&$pv['count']===0,'Audience review excludes an unverified subscriber without sending');
$assert(!str_contains(wp_json_encode($pv),$prefix.'visitor@example.invalid')&&!str_contains(wp_json_encode($pv),'kiwe_unsubscribe='),'Campaign preview does not expose recipient contact or a live unsubscribe capability');
$rejected=$dispatch('POST','campaigns',['operation'=>'queue','id'=>$draft['id'],'expected'=>$draft['expected'],'audienceExpected'=>$pv['expected'],'confirmed'=>true]);
$assert($rejected->get_status()===409&&($dispatch('GET','campaigns',null,true,true,['id'=>$draft['id']])->get_data()['draft']['status']??'')==='draft','Unreachable audience cannot enter the native delivery queue');
$assert($dispatch('POST','campaigns',['operation'=>'archive','id'=>$draft['id'],'expected'=>str_repeat('0',64)])->get_status()===409,'Archive requires a fresh saved-draft proof');
$archived=$dispatch('POST','campaigns',['operation'=>'archive','id'=>$draft['id'],'expected'=>$draft['expected']]);
$assert($archived->get_status()===200&&($archived->get_data()['archived']??false)===true&&$dispatch('GET','campaigns',null,true,true,['id'=>$draft['id']])->get_status()===409,'Explicit archive removes only the reviewed draft');
$assert(\DSA\Access\Campaign_Workspace_Service::sms_segments('Hello')['segments']===1&&\DSA\Access\Campaign_Workspace_Service::sms_segments(str_repeat('A',161))['segments']===2&&\DSA\Access\Campaign_Workspace_Service::sms_segments('नमस्ते')['encoding']==='Unicode','SMS preview estimates GSM and Unicode segments');
$unsubscribe=\DSA\Notifications\Campaign_Unsubscribe_Service::url($users['visitor'],'','newsletter','email',time()+DAY_IN_SECONDS);
parse_str((string)wp_parse_url($unsubscribe,PHP_URL_QUERY),$stopQuery);
$scope=\DSA\Notifications\Campaign_Unsubscribe_Service::decode((string)($stopQuery['kiwe_unsubscribe']??''));
$assert(($scope['id']??0)===$users['visitor']&&$scope['topic']==='newsletter'&&$scope['channel']==='email'&&false===\DSA\Notifications\Campaign_Unsubscribe_Service::decode(($stopQuery['kiwe_unsubscribe']??'').'a'),'Signed topic/channel unsubscribe rejects tampering');
wp_set_current_user($users['author']);
$assert($dispatch('GET','user-groups')->get_status()===403&&$dispatch('POST','campaigns',['operation'=>'preview','id'=>$draft['id'],'expected'=>$draft['expected']])->get_status()===403,'Editorial author cannot inspect groups or campaign targets');
wp_set_current_user($users['client']);
