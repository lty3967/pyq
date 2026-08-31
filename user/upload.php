<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
require WM_INC . '/upload.php';
header('Content-Type: application/json; charset=utf-8');
wm_require_post(true); $user=wm_require_user(true); wm_csrf_check(true);
if (!wm_rate_limit('user_upload',100,3600,'user'.(int)$user['id'])) wm_json(false,'上传过于频繁，请稍后再试',[],429);
$type=wm_input('type'); if(!in_array($type,['image','video'],true)||!isset($_FILES['file'])) wm_json(false,'上传参数错误',[],400);
$res=$type==='image'?wm_upload_image($_FILES['file']):wm_upload_video($_FILES['file']);
if(!$res['ok']) wm_json(false,(string)$res['msg'],[],400);
wm_exec('INSERT INTO ' . wm_t('media') . ' (post_id,user_id,type,path,thumb,width,height,size,sort,created_at) VALUES (0,?,?,?,?,?,?,?,0,NOW())', [(int)$user['id'],$type,$res['path'],$res['thumb']??'',$res['width']??0,$res['height']??0,$res['size']]);
$mediaId = wm_insert_id();
wm_json(true,'上传成功',['id'=>$mediaId,'type'=>$type,'path'=>$res['path'],'thumb'=>$res['thumb']??'','width'=>$res['width']??0,'height'=>$res['height']??0,'size'=>$res['size']]);
