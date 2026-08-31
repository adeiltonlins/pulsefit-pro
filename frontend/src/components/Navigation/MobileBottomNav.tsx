import React from 'react';
import { ScreenView } from '../../types';

interface MobileBottomNavProps { currentScreen: ScreenView; onNavigate: (screen: ScreenView) => void; }
export const MobileBottomNav: React.FC<MobileBottomNavProps> = ({ currentScreen, onNavigate }) => {
  const tabs: { id: ScreenView; label: string; icon: string }[] = [
    { id: 'student_daily', label: 'Diário', icon: 'fitness_center' },
    { id: 'prescription', label: 'Rotina', icon: 'list_alt' },
    { id: 'workout_execution', label: 'Treinar', icon: 'play_circle' },
    { id: 'student_progress', label: 'Evolução', icon: 'trending_up' },
    { id: 'student_profile', label: 'Perfil', icon: 'person' }
  ];
  return <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#0a0a0a]/95 backdrop-blur border-t-2 border-[#222222] px-2 py-1 flex justify-around items-center">{tabs.map(tab=>{const active=currentScreen===tab.id;return <button key={tab.id} onClick={()=>onNavigate(tab.id)} className={`flex flex-col items-center justify-center py-1.5 px-3 min-w-[56px] transition-colors ${active?'text-[#DFFF00]':'text-gray-400 hover:text-white'}`}><span className="material-symbols-outlined text-xl">{tab.icon}</span><span className={`text-[10px] font-anybody font-bold uppercase tracking-tight mt-0.5 ${active?'text-[#DFFF00]':'text-gray-400'}`}>{tab.label}</span></button>})}</nav>;
};
