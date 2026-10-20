<?php
declare(strict_types=1);
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'secure'=>getenv('APP_HTTPS')==='1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),'samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store');
require dirname(__DIR__).'/app/db.php';
require dirname(__DIR__).'/app/LostFoundItem.php';
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(): never { header('Location: /'); exit; }
if (isset($_SESSION['last_seen']) && time()-$_SESSION['last_seen']>1800) { $_SESSION=[]; session_regenerate_id(true); }
$_SESSION['last_seen']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error=''; $edit=null;
try { $pdo=database(); } catch (Throwable $e) { error_log((string)$e); http_response_code(503); exit('Database unavailable. Please try again later.'); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Session expired. Refresh and try again.');
        $action=(string)($_POST['action']??'');
        if ($action==='login' || $action==='register') {
            $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
            if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/',$username) || strlen($password)<8 || strlen($password)>128) throw new RuntimeException('Use a 3–30 character username (letters, numbers, underscore) and an 8–128 character password.');
            if ($action==='register') {
                try { $pdo->prepare('INSERT INTO users (username,password_hash) VALUES (?,?)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]); }
                catch (PDOException $e) { if ($e->getCode()==='23000') throw new RuntimeException('That username is already taken.'); throw $e; }
            }
            $s=$pdo->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$username]); $user=$s->fetch();
            if (!$user || !password_verify($password,$user['password_hash'])) throw new RuntimeException('Incorrect username or password.');
            session_regenerate_id(true); $_SESSION=['user_id'=>(int)$user['id'],'username'=>$user['username'],'csrf'=>bin2hex(random_bytes(32)),'last_seen'=>time()]; redirect();
        }
        if (!isset($_SESSION['user_id'])) throw new RuntimeException('Please sign in first.');
        if ($action==='logout') { $_SESSION=[]; session_destroy(); setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Lax']); redirect(); }
        $items=new LostFoundItem($pdo,(int)$_SESSION['user_id']);
        if ($action==='delete') { $items->delete((int)($_POST['id']??0)); }
        elseif ($action==='save') {
            $data=[];
            foreach (['item_name'=>100,'category'=>50,'item_type'=>10,'description'=>2000,'location'=>100,'date_reported'=>10,'status'=>20,'reporter_name'=>100] as $key=>$max) {
                $data[$key]=trim((string)($_POST[$key]??''));
                if (($key!=='description' && $data[$key]==='') || strlen($data[$key])>$max) throw new RuntimeException('Complete all fields within their length limits.');
            }
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$data['date_reported']);
            if (!$date || $date->format('Y-m-d')!==$data['date_reported'] || !in_array($data['item_type'],['Lost','Found'],true) || !in_array($data['status'],['Open','Matched','Claimed'],true)) throw new RuntimeException('Select a valid date, type, and status.');
            $id=(int)($_POST['id']??0); if ($id) $items->update($id,$data); else $items->add($data);
        } else throw new RuntimeException('Unknown action.');
        $_SESSION['flash']='Record '.($action==='delete'?'deleted':'saved').' successfully.'; redirect();
    } catch (RuntimeException $e) { $error=$e instanceof PDOException?'Unable to save the record. Please try again.':$e->getMessage(); if ($e instanceof PDOException) error_log((string)$e); }
      catch (Throwable $e) { error_log((string)$e); $error='Something went wrong. Please try again.'; }
}
$items=isset($_SESSION['user_id'])?new LostFoundItem($pdo,(int)$_SESSION['user_id']):null;
$search=substr((string)($_GET['search']??''),0,100);
if ($items && isset($_GET['edit'])) { try { $edit=$items->find((int)$_GET['edit']); } catch (RuntimeException $e) { $error='Record not found.'; } }
if ($error && ($_POST['action']??'')==='save') $edit=$_POST;
$records=$items?$items->search($search):[];
$flash=$_SESSION['flash']??''; unset($_SESSION['flash']);
function csrf(): void { echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Campus Lost &amp; Found</title><link rel="stylesheet" href="/style.css"></head><body>
<header><a class="brand" href="/">↗ Campus <span>Lost &amp; Found</span></a><span class="tag">STUDENT AFFAIRS / ITEM DESK</span><?php if ($items): ?><form method="post"><?php csrf(); ?><span><?=h($_SESSION['username'])?></span> <button name="action" value="logout" class="quiet">Sign out</button></form><?php endif; ?></header>
<main><div class="intro"><p class="eyebrow">A LITTLE HELP GOES A LONG WAY</p><h1>Lost something?<br>Let’s bring it home.</h1><p>Keep track of lost and found items across campus, from the first report to a happy reunion.</p></div>
<?php if ($error): ?><p class="notice error" role="alert"><?=h($error)?></p><?php endif; ?><?php if ($flash): ?><p class="notice" role="status"><?=h($flash)?></p><?php endif; ?>
<?php if (!$items): ?><section class="auth card"><p class="eyebrow">YOUR CAMPUS DESK</p><h2>Welcome back.</h2><p>Sign in to manage your reports, or create an account to get started.</p><form method="post"><?php csrf(); ?><label>Username<input name="username" autocomplete="username" minlength="3" maxlength="30" pattern="[a-zA-Z0-9_]+" required></label><label>Password<input type="password" name="password" autocomplete="current-password" minlength="8" maxlength="128" required></label><div class="actions"><button name="action" value="login">Sign in →</button><button class="secondary" name="action" value="register">Create account</button></div><small>Use at least 8 characters for your password. Accounts keep separate item records.</small></form></section>
<?php else: ?><div class="stats"><?php foreach (['Open','Matched','Claimed'] as $state): ?><div><strong><?=count(array_filter($records,fn($r)=>$r['status']===$state))?></strong><span><?=h($state)?> reports<?= $search?' (filtered)':'' ?></span></div><?php endforeach; ?></div>
<div class="workspace"><section class="card editor"><p class="eyebrow"><?= $edit?'UPDATE REPORT':'START A REPORT' ?></p><h2><?= $edit?'Edit item':'What’s the item?' ?></h2><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit['id']??'')?>">
<?php foreach (['item_name'=>'Item name','category'=>'Category','location'=>'Location','reporter_name'=>'Reporter name'] as $key=>$label): ?><label><?=h($label)?><input name="<?=h($key)?>" value="<?=h($edit[$key]??'')?>" maxlength="<?=$key==='category'?50:100?>" required></label><?php endforeach; ?>
<div class="pair"><?php foreach (['item_type'=>['Lost','Found'],'status'=>['Open','Matched','Claimed']] as $key=>$options): ?><label><?=$key==='item_type'?'Report type':'Status'?><select name="<?=h($key)?>"><?php foreach ($options as $option): ?><option <?=($edit[$key]??'')===$option?'selected':''?>><?=h($option)?></option><?php endforeach; ?></select></label><?php endforeach; ?></div>
<label>Date reported<input type="date" name="date_reported" value="<?=h($edit['date_reported']??date('Y-m-d'))?>" required></label><label>Description<textarea name="description" rows="3" maxlength="2000"><?=h($edit['description']??'')?></textarea></label><button>Save report →</button><?php if ($edit): ?> <a href="/">Cancel edit</a><?php endif; ?></form></section>
<section class="reports"><div class="section-head"><div><p class="eyebrow">THE NOTICEBOARD</p><h2>Your item reports <span class="count"><?=count($records)?></span></h2></div></div><form class="search" method="get"><label class="sr" for="search">Search reports</label><input id="search" name="search" placeholder="Search items, places, reference numbers…" value="<?=h($search)?>"><button>Search</button><?php if ($search): ?><a href="/">Clear</a><?php endif; ?></form>
<?php if (!$records): ?><div class="empty card"><span>◎</span><h3><?=$search?'No matching reports.':'A fresh start.'?></h3><p><?=$search?'Try a different item name or location.':'Add your first lost or found item using the report form.'?></p></div><?php endif; ?>
<?php foreach ($records as $r): ?><article class="card item"><div class="item-top"><span class="pill <?=strtolower($r['item_type'])?>"><?=h($r['item_type'])?></span><span class="state"><?=h($r['status'])?></span></div><h3><?=h($r['item_name'])?></h3><p class="description"><?=h($r['description']?:'No description provided.')?></p><dl><div><dt>Location</dt><dd><?=h($r['location'])?></dd></div><div><dt>Reported</dt><dd><?=h($r['date_reported'])?></dd></div><div><dt>Category</dt><dd><?=h($r['category'])?></dd></div><div><dt>Reporter</dt><dd><?=h($r['reporter_name'])?></dd></div></dl><div class="item-footer"><small><?=h($r['reference_no'])?></small><a href="/?edit=<?=(int)$r['id']?>">Edit</a><details><summary>Delete</summary><form method="post"><?php csrf(); ?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><p>Delete this report permanently?</p><button name="action" value="delete" class="danger">Confirm delete</button></form></details></div></article><?php endforeach; ?>
<section class="history"><h2>Recent activity</h2><p>Each change and its activity entry are saved in one database transaction.</p><?php foreach ($items->history() as $log): ?><div><strong><?=h($log['action'])?></strong> <?=h($log['reference_no'])?> <small><?=h($log['created_at'])?> UTC</small></div><?php endforeach; ?></section></section></div><?php endif; ?>
</main><footer>Campus Lost &amp; Found · Aydelore Abaquita · BSIT 3B</footer></body></html>
