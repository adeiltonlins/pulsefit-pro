<?php
// White-label leve por treinador: identidade visual sem quebrar o design-base do PulseFit.
function pf_branding_migrate(PDO $pdo): void {
    $cols=[
      'brand_name'=>'TEXT','brand_logo_url'=>'TEXT','brand_banner_url'=>'TEXT','brand_primary'=>'TEXT NOT NULL DEFAULT "#DFFF00"','brand_secondary'=>'TEXT NOT NULL DEFAULT "#121414"','brand_accent'=>'TEXT NOT NULL DEFAULT "#FFFFFF"','brand_theme'=>'TEXT NOT NULL DEFAULT "dark"','brand_slogan'=>'TEXT','brand_whatsapp'=>'TEXT','brand_instagram'=>'TEXT','brand_website'=>'TEXT'
    ];
    foreach($cols as $name=>$def){
        if(function_exists('pf_schema_add_column'))pf_schema_add_column($pdo,'coach_profiles',$name,$def);
        else{
            $rows=$pdo->query('PRAGMA table_info(coach_profiles)')->fetchAll();$has=false;foreach($rows as $r)if(($r['name']??'')===$name){$has=true;break;}if(!$has)$pdo->exec('ALTER TABLE coach_profiles ADD COLUMN '.$name.' '.$def);
        }
    }
}
pf_branding_migrate($pdo);
