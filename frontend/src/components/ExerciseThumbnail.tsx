import React from 'react';

type Props={name:string;category?:string;equipment?:string;src?:string;mediaType?:'image'|'video';className?:string};

function kind(name:string,category=''){
 const s=`${name} ${category}`.toLowerCase();
 if(/agach|hack|leg press|sissy/.test(s))return 'squat';
 if(/afundo|passada|búlgar|subida no banco/.test(s))return 'lunge';
 if(/supino|crucifixo|peito|flexão de braços/.test(s))return 'bench';
 if(/barra fixa|puxada|remada|pulôver|costas/.test(s))return 'pull';
 if(/rosca|bíceps/.test(s))return 'curl';
 if(/tríceps|mergulho/.test(s))return 'triceps';
 if(/desenvolvimento|ombro|elevação lateral|elevação frontal/.test(s))return 'shoulder';
 if(/terra|romeno|semirríg|bom dia/.test(s))return 'hinge';
 if(/pélvica|quadril|glúteo|coice/.test(s))return 'hip';
 if(/panturrilha/.test(s))return 'calf';
 if(/prancha|anti-rotação|copenhague/.test(s))return 'plank';
 if(/abdominal|crunch|roda abdominal|rotação russa|elevação de pernas/.test(s))return 'core';
 if(/corrida|caminhada|esteira|escada|burpee|escalador|corda naval|trenó|salto/.test(s))return 'cardio';
 if(/bicicleta|bike/.test(s))return 'bike';
 if(/mobilidade|alongamento|rotação torácica|elástico|ativação/.test(s))return 'mobility';
 return 'strength';
}

const C='#DFFF00', W='#f4f4f4', G='#596064';
const Head=({x,y}:{x:number;y:number})=><circle cx={x} cy={y} r="5" fill={W}/>;
const L=({x1,y1,x2,y2,w=4}:{x1:number;y1:number;x2:number;y2:number;w?:number})=><line x1={x1} y1={y1} x2={x2} y2={y2} stroke={W} strokeWidth={w} strokeLinecap="round"/>;
const Bar=({x1=12,x2=88,y=28}:{x1?:number;x2?:number;y?:number})=><><line x1={x1} y1={y} x2={x2} y2={y} stroke={C} strokeWidth="3"/><rect x={x1-3} y={y-7} width="4" height="14" fill={C}/><rect x={x2-1} y={y-7} width="4" height="14" fill={C}/></>;

function Pose({type}:{type:string}){
 switch(type){
  case 'squat':return <><Bar y={23}/><Head x={50} y={32}/><L x1={50} y1={38} x2={50} y2={53}/><L x1={50} y1={43} x2={35} y2={28}/><L x1={50} y1={43} x2={65} y2={28}/><L x1={50} y1={52} x2={37} y2={63}/><L x1={37} y1={63} x2={29} y2={78}/><L x1={50} y1={52} x2={65} y2={63}/><L x1={65} y1={63} x2={73} y2={78}/></>;
  case 'lunge':return <><Head x={48} y={25}/><L x1={48} y1={31} x2={48} y2={51}/><L x1={48} y1={38} x2={35} y2={47}/><L x1={48} y1={38} x2={61} y2={47}/><L x1={48} y1={51} x2={31} y2={64}/><L x1={31} y1={64} x2={20} y2={78}/><L x1={48} y1={51} x2={67} y2={61}/><L x1={67} y1={61} x2={81} y2={61}/></>;
  case 'bench':return <><rect x="18" y="62" width="64" height="5" fill={G}/><Head x={31} y={51}/><L x1={36} y1={52} x2={62} y2={55}/><L x1={49} y1={54} x2={39} y2={38}/><L x1={49} y1={54} x2={61} y2={38}/><L x1={62} y1={55} x2={73} y2={66}/><Bar x1={27} x2={74} y={34}/></>;
  case 'pull':return <><Bar x1={18} x2={82} y={17}/><Head x={50} y={32}/><L x1={50} y1={38} x2={50} y2={57}/><L x1={50} y1={42} x2={30} y2={22}/><L x1={50} y1={42} x2={70} y2={22}/><L x1={50} y1={57} x2={39} y2={76}/><L x1={50} y1={57} x2={61} y2={76}/></>;
  case 'curl':return <><Head x={50} y={24}/><L x1={50} y1={30} x2={50} y2={56}/><L x1={50} y1={38} x2={37} y2={48}/><L x1={37} y1={48} x2={34} y2={36}/><L x1={50} y1={38} x2={63} y2={48}/><L x1={63} y1={48} x2={66} y2={36}/><circle cx="32" cy="33" r="5" fill={C}/><circle cx="68" cy="33" r="5" fill={C}/><L x1={50} y1={56} x2={42} y2={78}/><L x1={50} y1={56} x2={58} y2={78}/></>;
  case 'triceps':return <><Head x={50} y={24}/><L x1={50} y1={30} x2={50} y2={56}/><L x1={50} y1={38} x2={37} y2={27}/><L x1={37} y1={27} x2={31} y2={42}/><L x1={50} y1={38} x2={63} y2={27}/><L x1={63} y1={27} x2={69} y2={42}/><L x1={50} y1={56} x2={42} y2={78}/><L x1={50} y1={56} x2={58} y2={78}/></>;
  case 'shoulder':return <><Head x={50} y={31}/><L x1={50} y1={37} x2={50} y2={58}/><L x1={50} y1={42} x2={34} y2={30}/><L x1={34} y1={30} x2={34} y2={16}/><L x1={50} y1={42} x2={66} y2={30}/><L x1={66} y1={30} x2={66} y2={16}/><circle cx="34" cy="12" r="5" fill={C}/><circle cx="66" cy="12" r="5" fill={C}/><L x1={50} y1={58} x2={42} y2={79}/><L x1={50} y1={58} x2={58} y2={79}/></>;
  case 'hinge':return <><Bar x1={22} x2={79} y={67}/><Head x={38} y={31}/><L x1={43} y1={35} x2={59} y2={48}/><L x1={59} y1={48} x2={58} y2={67}/><L x1={52} y1={43} x2={37} y2={63}/><L x1={59} y1={48} x2={72} y2={75}/><L x1={59} y1={48} x2={48} y2={77}/></>;
  case 'hip':return <><rect x="18" y="58" width="30" height="5" fill={G}/><Head x={28} y={48}/><L x1={34} y1={49} x2={56} y2={43}/><L x1={56} y1={43} x2={70} y2={58}/><L x1={70} y1={58} x2={80} y2={75}/><L x1={56} y1={43} x2={62} y2={67}/><line x1="47" y1="40" x2="71" y2="40" stroke={C} strokeWidth="5"/></>;
  case 'calf':return <><Head x={50} y={22}/><L x1={50} y1={28} x2={50} y2={58}/><L x1={50} y1={38} x2={38} y2={48}/><L x1={50} y1={38} x2={62} y2={48}/><L x1={50} y1={58} x2={43} y2={77}/><L x1={50} y1={58} x2={57} y2={77}/><line x1="35" y1="80" x2="65" y2="80" stroke={C} strokeWidth="3"/></>;
  case 'plank':return <><Head x={25} y={48}/><L x1={31} y1={49} x2={60} y2={52}/><L x1={60} y1={52} x2={82} y2={65}/><L x1={40} y1={51} x2={30} y2={65}/><L x1={30} y1={65} x2={18} y2={65}/></>;
  case 'core':return <><Head x={32} y={43}/><L x1={37} y1={46} x2={54} y2={58}/><L x1={54} y1={58} x2={73} y2={70}/><L x1={54} y1={58} x2={68} y2={43}/><L x1={38} y1={48} x2={27} y2={61}/><path d="M18 74 H83" stroke={G} strokeWidth="3"/></>;
  case 'cardio':return <><Head x={49} y={21}/><L x1={49} y1={27} x2={51} y2={49}/><L x1={49} y1={34} x2={35} y2={45}/><L x1={49} y1={34} x2={64} y2={27}/><L x1={51} y1={49} x2={37} y2={65}/><L x1={37} y1={65} x2={25} y2={75}/><L x1={51} y1={49} x2={66} y2={61}/><L x1={66} y1={61} x2={80} y2={64}/></>;
  case 'bike':return <><circle cx="30" cy="68" r="15" fill="none" stroke={C} strokeWidth="3"/><circle cx="72" cy="68" r="15" fill="none" stroke={C} strokeWidth="3"/><Head x={51} y={27}/><L x1={51} y1={33} x2={45} y2={49}/><L x1={45} y1={49} x2={57} y2={62}/><L x1={57} y1={62} x2={72} y2={68}/><L x1={45} y1={49} x2={30} y2={68}/><L x1={47} y1={40} x2={63} y2={43}/></>;
  case 'mobility':return <><Head x={50} y={24}/><L x1={50} y1={30} x2={50} y2={56}/><L x1={50} y1={37} x2={30} y2={24}/><L x1={30} y1={24} x2={20} y2={14}/><L x1={50} y1={37} x2={70} y2={24}/><L x1={70} y1={24} x2={80} y2={14}/><L x1={50} y1={56} x2={38} y2={78}/><L x1={50} y1={56} x2={62} y2={78}/></>;
  default:return <><Head x={50} y={24}/><L x1={50} y1={30} x2={50} y2={57}/><L x1={50} y1={40} x2={34} y2={50}/><L x1={50} y1={40} x2={66} y2={50}/><L x1={50} y1={57} x2={42} y2={78}/><L x1={50} y1={57} x2={58} y2={78}/><Bar x1={27} x2={73} y={49}/></>;
 }
}

export const ExerciseThumbnail:React.FC<Props>=({name,category='',equipment='',src='',mediaType='image',className=''})=>{
 if(src){
  if(mediaType==='video')return <video src={src} className={`w-full h-full object-cover ${className}`} muted playsInline/>;
  return <img src={src} alt={name} className={`w-full h-full object-cover ${className}`}/>;
 }
 const type=kind(name,category);
 return <div className={`w-full h-full bg-[#080909] relative overflow-hidden ${className}`} title={`${name} • ${equipment}`}>
   <svg viewBox="0 0 100 92" className="w-full h-full" aria-label={`Ilustração: ${name}`} role="img"><Pose type={type}/></svg>
   <div className="absolute bottom-0 inset-x-0 bg-black/75 px-1 py-[2px] text-center text-[5px] leading-tight font-black uppercase text-white truncate">{name}</div>
 </div>;
};
