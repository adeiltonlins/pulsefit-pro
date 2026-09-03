import React, { useEffect, useMemo, useState } from 'react';
import { ScreenView, UserRole, Student } from './types';
import { api, ApiError, ApiUser, normalizeStudent } from './api';
import { TopBar } from './components/Navigation/TopBar';
import { Sidebar } from './components/Navigation/Sidebar';
import { MobileBottomNav } from './components/Navigation/MobileBottomNav';
import { MetricsUpdateModal } from './components/Modals/MetricsUpdateModal';
import { ForcePasswordChangeModal } from './components/Modals/ForcePasswordChangeModal';
import { LandingScreen } from './components/Screens/LandingScreen';
import { AuthLoginScreen } from './components/Screens/AuthLoginScreen';
import { CoachDashboardScreen } from './components/Screens/CoachDashboardScreen';
import { GrowthScreen } from './components/Screens/GrowthScreen';
import { PublicCoachScreen } from './components/Screens/PublicCoachScreen';
import { PrescriptionScreen } from './components/Screens/PrescriptionScreen';
import { FitnessToolsScreen } from './components/Screens/FitnessToolsScreen';
import { PosturalAssessmentScreen } from './components/Screens/PosturalAssessmentScreen';
import { GamificationRankingScreen } from './components/Screens/GamificationRankingScreen';
import { StudentDetailScreen } from './components/Screens/StudentDetailScreen';
import { StudentReportProPanel } from './components/Screens/StudentReportProPanel';
import { StudentHubScreen } from './components/Screens/StudentHubScreen';
import { StudentDailyScreen } from './components/Screens/StudentDailyScreen';
import { WorkoutExecutionScreen } from './components/Screens/WorkoutExecutionScreen';
import { StudentProgressScreen } from './components/Screens/StudentProgressScreen';
import { StudentCheckinScreen } from './components/Screens/StudentCheckinScreen';
import { AnamneseScreen } from './components/Screens/AnamneseScreen';
import { StudentProfileScreen } from './components/Screens/StudentProfileScreen';
import { ChatScreen } from './components/Screens/ChatScreen';
import { AgendaScreen } from './components/Screens/AgendaScreen';
import { AssinaturaScreen } from './components/Screens/AssinaturaScreen';
import { AdminDashboardScreen } from './components/Screens/AdminDashboardScreen';
import { ProductionReadinessPanel } from './components/Screens/ProductionReadinessPanel';
import { AdminMasterCenter } from './components/Screens/AdminMasterCenter';

const EMPTY_STUDENT: Student = {id:'',name:'Selecione um aluno',email:'',role:'Athlete',avatar:'',status:'review',programName:'Sem programa',phase:'Sem fase definida',lastCheckIn:'Sem check-in',age:0,height:0,weight:0,bodyFat:0};
const HOME_BY_ROLE: Record<'admin'|'coach'|'student',ScreenView>={admin:'admin_system',coach:'coach_dashboard',student:'student_hub'};
const ACCESS:Record<'admin'|'coach'|'student',ScreenView[]>={
 admin:['landing','admin_system','assinatura','student_detail'],
 coach:['landing','coach_dashboard','coach_growth','prescription','fitness_tools','postural_assessment','gamification_ranking','student_detail','student_progress','anamnese','chat','agenda','assinatura'],
 student:['landing','student_hub','student_daily','workout_execution','fitness_tools','postural_assessment','gamification_ranking','student_progress','student_checkin','student_profile','anamnese','chat','agenda','assinatura']
};

export default function App(){
 const publicCoachSlug=new URLSearchParams(window.location.search).get('coach')||'';
 const [currentScreen,setCurrentScreen]=useState<ScreenView>('landing');const [user,setUser]=useState<ApiUser|null>(null);const [booting,setBooting]=useState(!publicCoachSlug);const [selectedStudent,setSelectedStudent]=useState<Student>(EMPTY_STUDENT);const [isMetricsModalOpen,setIsMetricsModalOpen]=useState(false);const userRole:UserRole=user?.role||'public';
 useEffect(()=>{if(publicCoachSlug)return;api.me().then(({user:sessionUser})=>{setUser(sessionUser);setCurrentScreen(HOME_BY_ROLE[sessionUser.role]);if(sessionUser.role==='coach'&&!sessionUser.mustChangePassword)api.students().then(r=>{if(r.students?.[0])setSelectedStudent(normalizeStudent(r.students[0]) as Student)}).catch(()=>{})}).catch((e:unknown)=>{if(!(e instanceof ApiError)||e.status!==401)console.error(e);setUser(null)}).finally(()=>setBooting(false))},[publicCoachSlug]);
 const allowedScreens=useMemo(()=>user?ACCESS[user.role]:['landing','auth_login'] as ScreenView[],[user]);
 const handleSelectStudent=(student:Student)=>setSelectedStudent(student);
 const handleLoginSuccess=(authenticatedUser:ApiUser)=>{setUser(authenticatedUser);setCurrentScreen(HOME_BY_ROLE[authenticatedUser.role]);if(authenticatedUser.role==='coach'&&!authenticatedUser.mustChangePassword)api.students().then(r=>{if(r.students?.[0])setSelectedStudent(normalizeStudent(r.students[0]) as Student)}).catch(()=>{})};
 const handleNavigate=(screen:ScreenView)=>{if(user?.mustChangePassword)return;if(!allowedScreens.includes(screen)){setCurrentScreen(user?HOME_BY_ROLE[user.role]:'auth_login');return}setCurrentScreen(screen);window.scrollTo({top:0,behavior:'smooth'})};
 const handleLogout=async()=>{try{await api.logout()}catch{}setUser(null);setSelectedStudent(EMPTY_STUDENT);setCurrentScreen('auth_login')};
 const handlePasswordChanged=(updated:ApiUser)=>{setUser(updated);setCurrentScreen(HOME_BY_ROLE[updated.role]);if(updated.role==='coach')api.students().then(r=>{if(r.students?.[0])setSelectedStudent(normalizeStudent(r.students[0]) as Student)}).catch(()=>{})};
 const handleSaveMetrics=async(metrics:{weight:number;bodyFat:number;chest:number;waist:number;biceps:number;thighs:number;})=>{try{const targetId=userRole==='student'?(await api.portal()).student.id:selectedStudent.id;if(!targetId)throw new Error('Selecione um aluno antes de salvar as métricas.');await api.saveMetrics(targetId,metrics);setSelectedStudent(prev=>({...prev,weight:metrics.weight,bodyFat:metrics.bodyFat}));alert('Métricas salvas com sucesso.')}catch(e:any){alert(e?.message||'Não foi possível salvar as métricas.')}};
 if(publicCoachSlug)return <PublicCoachScreen slug={publicCoachSlug}/>;
 if(booting)return <div className="min-h-screen bg-[#0A0A0A] text-[#DFFF00] grid place-items-center font-mono text-xs uppercase tracking-[.25em]">Validando sessão...</div>;
 const full=currentScreen==='landing'||currentScreen==='auth_login';
 return <div className="min-h-screen bg-[#0A0A0A] text-[#e2e2e2] flex flex-col selection:bg-[#DFFF00] selection:text-[#0A0A0A]"><TopBar currentScreen={currentScreen} onNavigate={handleNavigate} user={user} onLogout={handleLogout}/><div className="flex-1 flex w-full">{!full&&user&&<Sidebar currentScreen={currentScreen} onNavigate={handleNavigate} user={user} onLogout={handleLogout}/>}<main className={`flex-1 w-full ${full?'p-0':'p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto pb-24 lg:pb-12'}`}>
 {currentScreen==='landing'&&<LandingScreen onNavigate={handleNavigate}/>} {currentScreen==='auth_login'&&<AuthLoginScreen onNavigate={handleNavigate} onLoginSuccess={handleLoginSuccess}/>} {user?.role==='coach'&&currentScreen==='coach_dashboard'&&<CoachDashboardScreen onNavigate={handleNavigate} onSelectStudent={handleSelectStudent}/>} {user?.role==='coach'&&currentScreen==='coach_growth'&&<GrowthScreen onNavigate={handleNavigate}/>} {user?.role==='coach'&&currentScreen==='prescription'&&<PrescriptionScreen onNavigate={handleNavigate} selectedStudent={selectedStudent}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='fitness_tools'&&<FitnessToolsScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='postural_assessment'&&<PosturalAssessmentScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='gamification_ranking'&&<GamificationRankingScreen onNavigate={handleNavigate} userRole={userRole}/>} {(user?.role==='coach'||user?.role==='admin')&&currentScreen==='student_detail'&&<><StudentDetailScreen student={selectedStudent} onNavigate={handleNavigate}/>{selectedStudent.id&&<StudentReportProPanel studentId={selectedStudent.id}/>}</>} {user?.role==='student'&&currentScreen==='student_hub'&&<StudentHubScreen onNavigate={handleNavigate}/>} {user?.role==='student'&&currentScreen==='student_daily'&&<StudentDailyScreen onNavigate={handleNavigate}/>} {user?.role==='student'&&currentScreen==='workout_execution'&&<WorkoutExecutionScreen onNavigate={handleNavigate}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='student_progress'&&<StudentProgressScreen onNavigate={handleNavigate} onOpenMetricsModal={()=>setIsMetricsModalOpen(true)} selectedStudent={selectedStudent} userRole={userRole}/>} {user?.role==='student'&&currentScreen==='student_checkin'&&<StudentCheckinScreen onNavigate={handleNavigate}/>} {user?.role==='student'&&currentScreen==='student_profile'&&<StudentProfileScreen onNavigate={handleNavigate} onOpenMetricsModal={()=>setIsMetricsModalOpen(true)}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='anamnese'&&<AnamneseScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='chat'&&<ChatScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole}/>} {(user?.role==='coach'||user?.role==='student')&&currentScreen==='agenda'&&<AgendaScreen onNavigate={handleNavigate} userRole={userRole}/>} {user&&currentScreen==='assinatura'&&<AssinaturaScreen onNavigate={handleNavigate} userRole={userRole}/>} {user?.role==='admin'&&currentScreen==='admin_system'&&<><AdminMasterCenter/><div className="mt-5"><ProductionReadinessPanel/></div><div className="mt-5"><AdminDashboardScreen onNavigate={handleNavigate} onSelectStudent={handleSelectStudent}/></div></>} </main></div>{!full&&user&&<MobileBottomNav currentScreen={currentScreen} onNavigate={handleNavigate} userRole={user.role}/>}<MetricsUpdateModal isOpen={isMetricsModalOpen} onClose={()=>setIsMetricsModalOpen(false)} onSave={handleSaveMetrics} initialData={{weight:selectedStudent.weight,bodyFat:selectedStudent.bodyFat}}/>{user?.mustChangePassword&&<ForcePasswordChangeModal user={user} onChanged={handlePasswordChanged}/>}</div>;
}
