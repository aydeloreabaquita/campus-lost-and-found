<?php
// Live integration test: creates clearly labeled test accounts in the configured project.
declare(strict_types=1);
require dirname(__DIR__).'/app/Firebase.php';
$firebase=new Firebase();
$first=$firebase->authenticate('codex_rules_'.bin2hex(random_bytes(6)).'@example.com',bin2hex(random_bytes(16)),true);
$_SESSION=$first;
$items=new FirebaseLostFoundItem($firebase,$first['user_id']);
$record=['item_name'=>'Rules test','category'=>'Test','item_type'=>'Found','description'=>'Disposable integration check',
    'location'=>'Test desk','date_reported'=>gmdate('Y-m-d'),'status'=>'Open','reporter_name'=>'Integration test'];
$items->add($record);
$saved=$items->search('Rules test')[0];
// Check automatic token refresh before its normal one-hour expiry.
$_SESSION['firebase_expires']=0;
$firebase->token();
if ($_SESSION['firebase_expires']<=time()) throw new RuntimeException('Token refresh failed.');
$ownerSession=$_SESSION;
$second=$firebase->authenticate('codex_rules_other_'.bin2hex(random_bytes(6)).'@example.com',bin2hex(random_bytes(16)),true);
$_SESSION=$second;
$denied=false;
try { $firebase->api('GET',$firebase->documentRoot().'/users/'.$first['user_id'].'/items/'.$saved['id']); }
catch (RuntimeException $e) { $denied=str_contains($e->getMessage(),'denied'); }
if (!$denied) throw new RuntimeException('Cross-user read was not rejected by Firestore.');
$_SESSION=$ownerSession;
$name=$firebase->documentRoot().'/users/'.$first['user_id'].'/items/'.$saved['id'];
$invalidLog=$firebase->documentRoot().'/users/'.$first['user_id'].'/activity/'.bin2hex(random_bytes(12));
$failed=false;
try {
    $firebase->api('POST',$firebase->documentRoot().':commit',['writes'=>[
        ['update'=>['name'=>$name,'fields'=>Firebase::fields([...$record,'item_name'=>'Should not persist','reference_no'=>$saved['reference_no'],'created_at'=>$saved['created_at']])],
            'currentDocument'=>['updateTime'=>$saved['_version']]],
        ['update'=>['name'=>$invalidLog,'fields'=>Firebase::fields(['action'=>'INVALID'])]],
    ]]);
} catch (RuntimeException) { $failed=true; }
if (!$failed || $items->find($saved['id'])['item_name']!=='Rules test') throw new RuntimeException('Atomic failure check failed.');
$items->delete($saved['id']);
echo "PASS: token refresh, direct Firestore cross-user denial, atomic failed-write rollback\n";
