import React from 'react';
import { ScreenView, UserRole } from '../../types';
interface MobileBottomNavProps { currentScreen: ScreenView; onNavigate: (screen: ScreenView) => void; userRole: Exclude<UserRole,'public'>; }
export const MobileBottomNav: React.FC<MobileBottomNavProps> = ({ currentScreen, onNavigate, userRole }) => {
 const studentTabs:{id:ScreenView;label:string;icon:string}[]=[{id:'student_hub',label:'Início',icon:'home'},{id:'student_daily',label:'Treino',icon:'fitness_center'},{id:'student_checkin',label:'Check-in',icon:'fact_check'},{id:'student_progress',label:'Evolução',icon:'trending_up'},{id:'student_profile',label:'Perfil',icon:'person'}];
 const coachTabs:{id:ScreenView;label:string;icon:string}[]=[{id:'coach_dashboard',label:'Início',icon:'dashboard'},{id:'prescription',label:'Alunos',icon:'groups'},{id:'student_progress',label:'Evolução',icon:'monitoring'},{id:'coach_growth',label:'Leads',icon:'trending_up'},{id:'chat',label:'Chat',icon:'chat'}];
 const adminTabs:{id:ScreenView;label:string;icon:string}[]=[{id:'admin_system',label:'Admin',icon:'admin_panel_settings'},{id:'assinatura',label:'Receita',icon:'payments'}];
 const tabs=userRole==='student'?studentTabs:userRole==='coach'?coachTabs:adminTabs;
 return <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#0a0a0a]/95 backdrop-blur border-t-2 border-[#222222] px-2 py-1 flex justify-around items-center">{tabs.map(tab=>{const active=currentScreen===tab.id;return <button key={tab.id} onClick={()=>onNavigate(tab.id)} className={`flex flex-col items-center justify-center py-1.5 px-2 min-w-[56px] ${active?'text-[#DFFF00]':'text-gray-400'}`}><span className="material-symbols-outlined text-xl">{tab.icon}</span><span className="text-[9px] font-anybody font-bold uppercase mt-0.5">{tab.label}</span></button>})}</nav>;
};
