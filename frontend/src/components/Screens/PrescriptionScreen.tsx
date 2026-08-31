import React, { useEffect, useMemo, useState } from 'react';
import { ScreenView, ExerciseItem, Student } from '../../types';
import { APP_IMAGES } from '../../data/mockData';
import { api } from '../../api';

interface Props { onNavigate:(screen:ScreenView)=>void; selectedStudent:Student; }
const toItem=(x:any,i=0):ExerciseItem=>({
  id:String(x.id??`lib-${Date.now()}-${i}`), libraryExerciseId:Number(x.id||0)||undefined, order:String(i+1).padStart(2,'0'),
  name:x.name||'Exercício', category:x.category||'GERAL', type:x.type||'ISOLADO', equipment:x.equipment||'LIVRE',
  thumbnail:x.thumbnail||'', sets:Number(x.sets||3), reps:String(x.reps||'10-12'), load:String(x.load||''), rest:Number(x.rest||60),
  rpe:Number(x.rpe||8), tempo:x.tempo||'2-0-2-0', instructions:x.instructions||'', mediaType:(x.mediaType==='video'?'video':'image')
});

export const PrescriptionScreen:React.FC<Props>=({onNavigate,selectedStudent})=>{
  const [currentDay,setCurrentDay]=useState<'A'|'B'|'C'|'D'>('A');
  const [exercises,setExercises]=useState<ExerciseItem[]>([]);
  const [library,setLibrary]=useState<ExerciseItem[]>([]);
  const [search,setSearch]=useState(''); const [filter,setFilter]=useState('ALL');
  const [loading,setLoading]=useState(true); const [message,setMessage]=useState('');
  const [customOpen,setCustomOpen]=useState(false); const [uploading,setUploading]=useState(false);
  const [custom,setCustom]=useState({name:'',category:'GERAL',type:'ISOLADO',equipment:'LIVRE',thumbnail:'',mediaType:'image',instructions:'',sets:3,reps:'10-12',rest:60,rpe:8,tempo:'2-0-2-0'});

  const loadLibrary=async()=>{setLoading(true);try{const r=await api.exerciseLibrary();setLibrary((r.exercises||[]).map(toItem));}catch(e:any){setMessage(e.message||'Falha ao carregar biblioteca.');}finally{setLoading(false)}};
  useEffect(()=>{loadLibrary()},[]);
  const filtered=useMemo(()=>library.filter(x=>{const q=search.toLowerCase();const match=!q||`${x.name} ${x.category} ${x.equipment}`.toLowerCase().includes(q);if(!match)return false;if(filter==='ALL')return true;if(filter==='LEGS')return /QUAD|POSTERIOR|GLÚTEO|PANTURRILHA/.test(x.category);if(filter==='PUSH')return /PEITO|TRÍCEPS|OMBRO/.test(x.category);if(filter==='PULL')return /COSTAS|BÍCEPS/.test(x.category);return true;}),[library,search,filter]);
  const add=(lib:ExerciseItem)=>setExercises(prev=>[...prev,{...lib,id:`rx-${Date.now()}-${prev.length}`,order:String(prev.length+1).padStart(2,'0')}]);
  const update=(id:string,k:keyof ExerciseItem,v:any)=>setExercises(p=>p.map(x=>x.id===id?{...x,[k]:v}:x));
  const remove=(id:string)=>setExercises(p=>p.filter(x=>x.id!==id).map((x,i)=>({...x,order:String(i+1).padStart(2,'0')})));
  const save=async()=>{setMessage('');if(!selectedStudent?.id||!exercises.length){setMessage('Selecione um aluno real e adicione pelo menos um exercício.');return;}try{await api.createWorkout({studentId:Number(selectedStudent.id),title:`Treino ${currentDay} • ${selectedStudent.programName||'Programa'}`,status:'published',exercises:exercises.map(x=>({libraryExerciseId:x.libraryExerciseId,name:x.name,sets:x.sets,reps:x.reps,load:x.load,rest:x.rest,thumbnail:x.thumbnail,category:x.category,type:x.type,equipment:x.equipment,rpe:x.rpe,tempo:x.tempo,instructions:x.instructions}))});setMessage('Treino publicado para o aluno.');}catch(e:any){setMessage(e.message||'Falha ao salvar.')}};
  const upload=async(file?:File)=>{if(!file)return;setUploading(true);try{const r=await api.uploadMedia(file);setCustom(c=>({...c,thumbnail:r.url,mediaType:file.type.startsWith('video/')?'video':'image'}));}catch(e:any){setMessage(e.message||'Falha no upload.')}finally{setUploading(false)}};
  const createCustom=async()=>{try{await api.createLibraryExercise(custom);setCustomOpen(false);setCustom({name:'',category:'GERAL',type:'ISOLADO',equipment:'LIVRE',thumbnail:'',mediaType:'image',instructions:'',sets:3,reps:'10-12',rest:60,rpe:8,tempo:'2-0-2-0'});await loadLibrary();setMessage('Exercício personalizado salvo na sua biblioteca.');}catch(e:any){setMessage(e.message||'Falha ao criar exercício.')}};

  return <div className="w-full space-y-6">
    {message&&<div className="border border-[#DFFF00]/40 bg-[#121414] px-4 py-3 text-xs font-mono text-[#DFFF00]">{message}</div>}
    <div className="bg-[#121414] border border-[#333535] p-5 flex flex-col md:flex-row gap-4 md:items-center justify-between">
      <div className="flex gap-4 items-center"><img src={selectedStudent.avatar||APP_IMAGES.studentProfileAlex} className="w-14 h-14 object-cover border-2 border-[#DFFF00]"/><div><div className="text-[10px] font-mono text-[#DFFF00]">// PRESCRIÇÃO REAL</div><h1 className="font-anybody text-2xl font-black uppercase text-white">{selectedStudent.name}</h1><p className="text-xs font-mono text-[#909378]">{selectedStudent.programName} • {selectedStudent.phase}</p></div></div>
      <div className="flex flex-wrap gap-2">{(['A','B','C','D'] as const).map(d=><button key={d} onClick={()=>setCurrentDay(d)} className={`px-3 py-2 text-xs font-mono border ${currentDay===d?'bg-[#DFFF00] text-black border-[#DFFF00]':'border-[#333] text-gray-300'}`}>DIA {d}</button>)}<button onClick={save} className="px-5 py-2 bg-[#DFFF00] text-black font-anybody font-black text-xs uppercase">Publicar treino</button></div>
    </div>

    <div className="grid lg:grid-cols-12 gap-6">
      <section className="lg:col-span-8 space-y-3">
        <div className="flex items-center justify-between border-b border-[#222] pb-2"><h2 className="font-anybody font-bold uppercase text-white">Ficha do Dia {currentDay}</h2><span className="text-xs font-mono text-[#DFFF00]">{exercises.length} exercícios</span></div>
        {!exercises.length&&<div className="border border-dashed border-[#333] p-10 text-center text-xs font-mono text-gray-500">Escolha exercícios na biblioteca ao lado.</div>}
        {exercises.map(ex=><div key={ex.id} className="bg-[#121414] border border-[#333535] p-4 space-y-4 hover:border-[#DFFF00]/60">
          <div className="flex items-center gap-3"><span className="text-2xl font-anybody font-black text-[#DFFF00]">{ex.order}</span><div className="w-20 h-16 bg-black border border-[#333] overflow-hidden">{ex.thumbnail?(ex.mediaType==='video'?<video src={ex.thumbnail} className="w-full h-full object-cover" muted/>:<img src={ex.thumbnail} className="w-full h-full object-cover"/>):<div className="h-full grid place-items-center text-gray-600"><span className="material-symbols-outlined">fitness_center</span></div>}</div><div className="flex-1"><h3 className="font-anybody font-bold uppercase text-white">{ex.name}</h3><p className="text-[10px] font-mono text-[#909378]">{ex.category} • {ex.equipment}</p></div><button onClick={()=>remove(ex.id)} className="text-gray-500 hover:text-red-400"><span className="material-symbols-outlined">delete</span></button></div>
          <div className="grid grid-cols-2 sm:grid-cols-6 gap-2">{[
            ['Séries','sets','number'],['Reps','reps','text'],['Carga','load','text'],['Desc.','rest','number'],['RPE','rpe','number'],['Tempo','tempo','text']
          ].map(([label,key,type])=><label key={key} className="text-[10px] font-mono text-gray-500 uppercase">{label}<input type={type} value={(ex as any)[key]??''} onChange={e=>update(ex.id,key as keyof ExerciseItem,type==='number'?Number(e.target.value):e.target.value)} className="mt-1 w-full bg-black border border-[#333] px-2 py-2 text-xs text-white focus:border-[#DFFF00] outline-none"/></label>)}</div>
          <textarea value={ex.instructions||''} onChange={e=>update(ex.id,'instructions',e.target.value)} placeholder="Instruções do treinador..." className="w-full bg-black border border-[#333] px-3 py-2 text-xs font-mono text-white outline-none focus:border-[#DFFF00]"/>
        </div>)}
      </section>

      <aside className="lg:col-span-4 bg-[#121414] border border-[#333535] p-4 space-y-4 h-fit lg:sticky lg:top-20">
        <div className="flex justify-between items-center"><div><div className="text-[10px] font-mono text-[#DFFF00]">// DATABASE</div><h2 className="font-anybody font-bold uppercase text-white">Biblioteca de Exercícios</h2></div><button onClick={()=>setCustomOpen(v=>!v)} className="w-9 h-9 bg-[#DFFF00] text-black"><span className="material-symbols-outlined">add</span></button></div>
        {customOpen&&<div className="border border-[#DFFF00]/40 bg-black p-3 space-y-2"><input value={custom.name} onChange={e=>setCustom({...custom,name:e.target.value})} placeholder="Nome do exercício" className="w-full bg-[#181a1a] border border-[#333] p-2 text-xs text-white"/><div className="grid grid-cols-2 gap-2"><input value={custom.category} onChange={e=>setCustom({...custom,category:e.target.value})} placeholder="Grupo muscular" className="bg-[#181a1a] border border-[#333] p-2 text-xs text-white"/><input value={custom.equipment} onChange={e=>setCustom({...custom,equipment:e.target.value})} placeholder="Equipamento" className="bg-[#181a1a] border border-[#333] p-2 text-xs text-white"/></div><textarea value={custom.instructions} onChange={e=>setCustom({...custom,instructions:e.target.value})} placeholder="Como executar" className="w-full bg-[#181a1a] border border-[#333] p-2 text-xs text-white"/><label className="block border border-dashed border-[#444] p-3 text-center text-[10px] font-mono text-gray-400 cursor-pointer hover:border-[#DFFF00]">{uploading?'Enviando...':'Enviar foto, GIF ou vídeo'}<input type="file" accept="image/*,video/mp4,video/webm" className="hidden" onChange={e=>upload(e.target.files?.[0])}/></label>{custom.thumbnail&&<div className="text-[10px] text-[#DFFF00] font-mono truncate">Mídia: {custom.thumbnail}</div>}<button onClick={createCustom} className="w-full py-2 bg-[#DFFF00] text-black font-anybody font-black text-xs uppercase">Salvar na biblioteca</button></div>}
        <input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Buscar exercício..." className="w-full bg-black border border-[#333] p-2.5 text-xs font-mono text-white outline-none focus:border-[#DFFF00]"/>
        <div className="flex gap-1">{['ALL','LEGS','PUSH','PULL'].map(x=><button key={x} onClick={()=>setFilter(x)} className={`flex-1 py-1.5 text-[9px] font-mono border ${filter===x?'bg-[#DFFF00] text-black':'border-[#333] text-gray-400'}`}>{x}</button>)}</div>
        <div className="space-y-2 max-h-[580px] overflow-y-auto pr-1">{loading?<div className="text-xs font-mono text-gray-500">Carregando...</div>:filtered.map(x=><button key={x.id} onClick={()=>add(x)} className="w-full text-left flex gap-3 p-2 bg-[#181a1a] border border-[#333] hover:border-[#DFFF00] group"><div className="w-14 h-12 bg-black overflow-hidden shrink-0">{x.thumbnail?<img src={x.thumbnail} className="w-full h-full object-cover"/>:<div className="h-full grid place-items-center text-gray-600"><span className="material-symbols-outlined text-lg">fitness_center</span></div>}</div><div className="min-w-0"><div className="font-anybody text-xs font-bold uppercase text-white group-hover:text-[#DFFF00] truncate">{x.name}</div><div className="text-[9px] font-mono text-gray-500">{x.category} • {x.sets}x{x.reps}</div></div></button>)}</div>
      </aside>
    </div>
    <button onClick={()=>onNavigate('coach_dashboard')} className="text-xs font-mono text-gray-500 hover:text-white">← Voltar ao dashboard</button>
  </div>;
};
