import React from 'react';
import {ScreenView} from '../../types';
import {ApiUser} from '../../api';

interface Props{
  currentScreen:ScreenView;
  onNavigate:(s:ScreenView)=>void;
  user:ApiUser|null;
  onLogout:()=>void;
  onOpenImageGallery:()=>void;
}

const roleLabel=(role:ApiUser['role'])=>role==='admin'?'Administrador':role==='coach'?'Treinador':'Aluno';
const home=(role:ApiUser['role']):ScreenView=>role==='admin'?'admin_system':role==='coach'?'coach_dashboard':'student_hub';

export const TopBar:React.FC<Props>=({onNavigate,user,onLogout,onOpenImageGallery})=>{
 const initials=user?.name?.split(' ').slice(0,2).map(n=>n[0]).join('').toUpperCase()||'PF';
 return <header className="sticky top-0 z-40 bg-[#0a0a0a]/95 backdrop-blur border-b border-[#222222]"><div className="max-w-7xl mx-auto px-3 sm:px-6 h-16 flex items-center justify-between gap-3"><button onClick={()=>onNavigate(user?home(user.role):'landing')} className="flex items-center gap-2"><div className="w-8 h-8 bg-[#DFFF00] text-black font-black font-anybody flex items-center justify-center text-lg">⚡</div><div className="text-left"><span className="font-anybody font-black text-lg tracking-tight uppercase text-white">PULSEFIT<span className="text-[#DFFF00]">.PRO</span></span><span className="text-[9px] font-mono tracking-widest text-[#909378] uppercase block">ELITE PERFORMANCE LAB</span></div></button><div className="flex items-center gap-2">{!user?<><button onClick={onOpenImageGallery} className="hidden md:flex items-center gap-1 px-2 py-1 border border-[#333] hover:border-[#DFFF00] text-[10px] uppercase text-[#DFFF00]"><span className="material-symbols-outlined text-sm">image</span>Galeria</button><button onClick={()=>onNavigate('auth_login')} className="px-4 py-2 bg-[#DFFF00] text-black text-[11px] font-black uppercase">Entrar</button></>:<><div className="hidden sm:block text-right mr-1"><div className="text-[11px] font-anybody font-bold uppercase text-white max-w-44 truncate">{user.name}</div><div className="text-[9px] font-mono text-[#DFFF00] uppercase">{roleLabel(user.role)}</div></div><button onClick={()=>onNavigate(user.role==='student'?'student_profile':home(user.role))} className="w-9 h-9 border border-[#DFFF00] bg-[#151717] text-[#DFFF00] font-anybody font-black text-xs">{initials}</button><button onClick={onLogout} className="px-3 py-2 border border-[#333] hover:border-red-500 text-[10px] font-mono uppercase text-gray-400 hover:text-red-400">Sair</button></>}</div></div></header>;
};
