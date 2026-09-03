<?php
// Brand Kit do treinador. Cores validadas e white-label controlado.
function pf_brand_color(string $value,string $fallback):string{
    $v=strtoupper(trim($value));return preg_match('/^#[0-9A-F]{6}$/',$v)?$v:$fallback;
}
function pf_brand_for_coach(PDO $pdo,int $coachId):array{
    $q=$pdo->prepare('SELECT u.id AS coachId,u.name AS coachName,COALESCE(cp.brand_name,"") AS brandName,COALESCE(cp.brand_logo_url,"") AS logoUrl,COALESCE(cp.brand_banner_url,"") AS bannerUrl,COALESCE(cp.brand_primary,"#DFFF00") AS primaryColor,COALESCE(cp.brand_secondary,"#121414") AS secondaryColor,COALESCE(cp.brand_accent,"#FFFFFF") AS accentColor,COALESCE(cp.brand_theme,"dark") AS theme,COALESCE(cp.brand_slogan,"") AS slogan,COALESCE(cp.brand_whatsapp,"") AS whatsapp,COALESCE(cp.brand_instagram,"") AS instagram,COALESCE(cp.brand_website,"") AS website FROM users u LEFT JOIN coach_profiles cp ON cp.user_id=u.id WHERE u.id=:id AND u.role="coach"');
    $q->execute(['id'=>$coachId]);$r=$q->fetch();if(!$r)return [];$r['displayName']=$r['brandName']?:$r['coachName'];return $r;
}
if($method==='GET'&&preg_match('#^/public/brand/([a-z0-9-]+)$#',$route,$m)){
    $q=$pdo->prepare('SELECT coach_id FROM coach_public_profiles WHERE slug=:slug AND public_enabled=1');$q->execute(['slug'=>$m[1]]);$coachId=(int)$q->fetchColumn();if(!$coachId)json_response(['brand'=>null]);json_response(['brand'=>pf_brand_for_coach($pdo,$coachId)]);
}
if($method==='GET'&&$route==='/brand-kit'){
    $u=current_user();$coachId=0;
    if($u['role']==='coach')$coachId=(int)$u['id'];
    elseif($u['role']==='student'){$q=$pdo->prepare('SELECT coach_id FROM students WHERE user_id=:uid');$q->execute(['uid'=>$u['id']]);$coachId=(int)$q->fetchColumn();}
    elseif($u['role']==='admin'&&isset($_GET['coachId']))$coachId=(int)$_GET['coachId'];
    if(!$coachId)json_response(['brand'=>null]);json_response(['brand'=>pf_brand_for_coach($pdo,$coachId)]);
}
if($method==='GET'&&$route==='/coach/brand-kit'){$u=require_role('coach');json_response(['brand'=>pf_brand_for_coach($pdo,(int)$u['id'])]);}
if($method==='PUT'&&$route==='/coach/brand-kit'){
    verify_csrf();$u=require_role('coach');$in=body();
    $theme=in_array(($in['theme']??'dark'),['dark','light'],true)?$in['theme']:'dark';
    $values=[
      'id'=>(int)$u['id'],'brandName'=>mb_substr(trim((string)($in['brandName']??'')),0,80),'logo'=>trim((string)($in['logoUrl']??'')),'banner'=>trim((string)($in['bannerUrl']??'')),'primary'=>pf_brand_color((string)($in['primaryColor']??''),'#DFFF00'),'secondary'=>pf_brand_color((string)($in['secondaryColor']??''),'#121414'),'accent'=>pf_brand_color((string)($in['accentColor']??''),'#FFFFFF'),'theme'=>$theme,'slogan'=>mb_substr(trim((string)($in['slogan']??'')),0,160),'whatsapp'=>mb_substr(trim((string)($in['whatsapp']??'')),0,40),'instagram'=>mb_substr(trim((string)($in['instagram']??'')),0,120),'website'=>mb_substr(trim((string)($in['website']??'')),0,240)
    ];
    $q=$pdo->prepare('INSERT INTO coach_profiles(user_id,brand_name,brand_logo_url,brand_banner_url,brand_primary,brand_secondary,brand_accent,brand_theme,brand_slogan,brand_whatsapp,brand_instagram,brand_website) VALUES(:id,:brandName,:logo,:banner,:primary,:secondary,:accent,:theme,:slogan,:whatsapp,:instagram,:website) ON CONFLICT(user_id) DO UPDATE SET brand_name=:brandName,brand_logo_url=:logo,brand_banner_url=:banner,brand_primary=:primary,brand_secondary=:secondary,brand_accent=:accent,brand_theme=:theme,brand_slogan=:slogan,brand_whatsapp=:whatsapp,brand_instagram=:instagram,brand_website=:website');
    $q->execute($values);audit($pdo,(int)$u['id'],'update','brand_kit',(int)$u['id']);json_response(['ok'=>true,'brand'=>pf_brand_for_coach($pdo,(int)$u['id'])]);
}
