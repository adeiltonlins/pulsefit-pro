<?php
// Normaliza nomes legados da biblioteca de sistema para português do Brasil.
function pf_exercise_svg(string $name,string $category):string{
    $safeName=htmlspecialchars(mb_strtoupper(mb_substr($name,0,24)),ENT_QUOTES|ENT_XML1,'UTF-8');
    $safeCat=htmlspecialchars(mb_strtoupper(mb_substr($category,0,16)),ENT_QUOTES|ENT_XML1,'UTF-8');
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="320" height="220" viewBox="0 0 320 220"><rect width="320" height="220" fill="#0A0A0A"/><rect x="10" y="10" width="300" height="200" rx="16" fill="#121414" stroke="#333"/><g stroke="#DFFF00" stroke-width="9" stroke-linecap="round"><line x1="95" y1="85" x2="225" y2="85"/><line x1="75" y1="65" x2="75" y2="105"/><line x1="55" y1="72" x2="55" y2="98"/><line x1="245" y1="65" x2="245" y2="105"/><line x1="265" y1="72" x2="265" y2="98"/></g><text x="160" y="145" fill="#FFFFFF" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" font-weight="700">'.$safeName.'</text><text x="160" y="172" fill="#DFFF00" text-anchor="middle" font-family="Arial,sans-serif" font-size="11">'.$safeCat.'</text></svg>';
    return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
}
function pf_translate_exercise_library(PDO $pdo): void {
    $map=[
      'Chest press convergente'=>'Supino convergente na máquina','Face pull'=>'Puxada para o rosto no cabo','Arnold press'=>'Desenvolvimento Arnold','Hack squat'=>'Agachamento no hack','Step-up'=>'Subida no banco','Sissy squat'=>'Agachamento sissy','Nordic curl'=>'Flexão nórdica de joelhos','Good morning'=>'Bom dia com barra','Glute ham raise'=>'Elevação de posterior e glúteo','Hip thrust com barra'=>'Elevação pélvica com barra','Pull through'=>'Extensão de quadril no cabo','Frog pump'=>'Elevação pélvica com plantas dos pés unidas','Panturrilha donkey'=>'Panturrilha inclinada','Abdominal crunch'=>'Abdominal tradicional','Crunch no cabo'=>'Abdominal no cabo','Ab wheel'=>'Roda abdominal','Dead bug'=>'Inseto morto','Bird dog'=>'Extensão alternada de braço e perna','Pallof press'=>'Pressão anti-rotação no cabo','Russian twist'=>'Rotação russa de tronco','Farmer walk'=>'Caminhada do fazendeiro','Farmer carry'=>'Transporte do fazendeiro','Copenhagen plank'=>'Prancha de Copenhague','Power clean'=>'Levantamento olímpico de força','Hang clean'=>'Levantamento olímpico suspenso','Push press'=>'Desenvolvimento com impulso','Thruster'=>'Agachamento com desenvolvimento','Kettlebell swing'=>'Balanço com kettlebell','Turkish get-up'=>'Levantada turca','Mountain climber'=>'Escalador','Battle rope ondas alternadas'=>'Ondas alternadas na corda naval','Sled push'=>'Empurrar trenó','Sled pull'=>'Puxar trenó','Box jump'=>'Salto na caixa','Medicine ball slam'=>'Arremesso da bola medicinal ao chão','Wall ball'=>'Agachamento com arremesso na parede','Bear crawl'=>'Caminhada do urso','Air bike'=>'Bicicleta de braços e pernas','Wall slide'=>'Deslizamento de braços na parede','Band pull-apart'=>'Abertura com elástico','Monster walk'=>'Caminhada lateral com elástico','Scapular push-up'=>'Flexão escapular','Rosca Bayesian'=>'Rosca unilateral no cabo atrás do corpo','Peck deck'=>'Crucifixo na máquina','Pulldown unilateral'=>'Puxada unilateral no cabo','Pullover no cabo'=>'Puxada de braços estendidos no cabo','Pullover com halter'=>'Pulôver com halter','Crossover alto'=>'Crucifixo no cabo de cima para baixo','Crossover médio'=>'Crucifixo no cabo na linha do peito','Crossover baixo'=>'Crucifixo no cabo de baixo para cima','Leg press 45°'=>'Leg press inclinado 45°','Stiff com barra'=>'Levantamento terra com pernas semirrígidas com barra','Stiff com halteres'=>'Levantamento terra com pernas semirrígidas com halteres'
    ];
    $find=$pdo->prepare('SELECT id FROM exercise_library WHERE is_system=1 AND lower(name)=lower(:name) LIMIT 1');
    $update=$pdo->prepare('UPDATE exercise_library SET name=:new WHERE id=:id');
    $delete=$pdo->prepare('DELETE FROM exercise_library WHERE id=:id');
    foreach($map as $old=>$new){
        $find->execute(['name'=>$old]);$oldId=(int)($find->fetchColumn()?:0);if(!$oldId)continue;
        $find->execute(['name'=>$new]);$newId=(int)($find->fetchColumn()?:0);
        if($newId&&$newId!==$oldId)$delete->execute(['id'=>$oldId]);else $update->execute(['new'=>$new,'id'=>$oldId]);
    }
    $equip=['AIR BIKE'=>'BICICLETA DE BRAÇOS E PERNAS','BIKE'=>'BICICLETA','SLED'=>'TRENÓ','MEDICINE BALL'=>'BOLA MEDICINAL','REMO'=>'ERGÔMETRO DE REMO'];
    $q=$pdo->prepare('UPDATE exercise_library SET equipment=:new WHERE is_system=1 AND equipment=:old');foreach($equip as $old=>$new)$q->execute(['new'=>$new,'old'=>$old]);
    $pdo->exec("UPDATE exercise_library SET instructions='Ajuste amplitude, carga e execução conforme o objetivo, o nível e a condição do aluno.' WHERE is_system=1");

    // Remove apenas as antigas placas SVG automáticas. Mídia real (arquivo, URL, GIF ou vídeo) permanece intacta.
    $pdo->exec("UPDATE exercise_library SET media_url=NULL WHERE is_system=1 AND media_url LIKE 'data:image/svg+xml%'");
}
