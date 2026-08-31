import React, { useState } from 'react';
import { ScreenView, UserRole, Student } from './types';
import { INITIAL_STUDENTS } from './data/mockData';
import { api, normalizeStudent } from './api';
import { TopBar } from './components/Navigation/TopBar';
import { Sidebar } from './components/Navigation/Sidebar';
import { MobileBottomNav } from './components/Navigation/MobileBottomNav';
import { ImageGalleryModal } from './components/Modals/ImageGalleryModal';
import { MetricsUpdateModal } from './components/Modals/MetricsUpdateModal';

import { LandingScreen } from './components/Screens/LandingScreen';
import { AuthLoginScreen } from './components/Screens/AuthLoginScreen';
import { CoachDashboardScreen } from './components/Screens/CoachDashboardScreen';
import { PrescriptionScreen } from './components/Screens/PrescriptionScreen';
import { StudentHubScreen } from './components/Screens/StudentHubScreen';
import { StudentDailyScreen } from './components/Screens/StudentDailyScreen';
import { WorkoutExecutionScreen } from './components/Screens/WorkoutExecutionScreen';
import { StudentProgressScreen } from './components/Screens/StudentProgressScreen';
import { AnamneseScreen } from './components/Screens/AnamneseScreen';
import { StudentProfileScreen } from './components/Screens/StudentProfileScreen';
import { ChatScreen } from './components/Screens/ChatScreen';
import { AgendaScreen } from './components/Screens/AgendaScreen';
import { AssinaturaScreen } from './components/Screens/AssinaturaScreen';
import { AdminDashboardScreen } from './components/Screens/AdminDashboardScreen';

export default function App() {
  const [currentScreen, setCurrentScreen] = useState<ScreenView>('landing');
  const [userRole, setUserRole] = useState<UserRole>('coach');
  const [selectedStudent, setSelectedStudent] = useState<Student>(INITIAL_STUDENTS[0]);
  const [isImageGalleryOpen, setIsImageGalleryOpen] = useState(false);
  const [isMetricsModalOpen, setIsMetricsModalOpen] = useState(false);

  const handleSelectStudent = (student: Student) => setSelectedStudent(student);

  const handleLoginSuccess = (role: UserRole) => {
    setUserRole(role);
    if (role === 'admin') setCurrentScreen('admin_system');
    else if (role === 'student') setCurrentScreen('student_hub');
    else {
      setCurrentScreen('coach_dashboard');
      api.students().then(r => { if (r.students?.[0]) setSelectedStudent(normalizeStudent(r.students[0]) as Student); }).catch(() => {});
    }
  };

  const handleNavigate = (screen: ScreenView) => {
    setCurrentScreen(screen);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleRoleChange = (role: UserRole) => {
    setUserRole(role);
    if (role === 'coach') setCurrentScreen('coach_dashboard');
    else if (role === 'student') setCurrentScreen('student_hub');
    else if (role === 'admin') setCurrentScreen('admin_system');
    else setCurrentScreen('landing');
  };

  const handleSaveMetrics = async (metrics: { weight:number; bodyFat:number; chest:number; waist:number; biceps:number; thighs:number; }) => {
    try {
      const targetId = userRole === 'student' ? (await api.portal()).student.id : selectedStudent.id;
      await api.saveMetrics(targetId, metrics);
      setSelectedStudent((prev) => ({ ...prev, weight:metrics.weight, bodyFat:metrics.bodyFat }));
      alert('Métricas salvas no SQLite com sucesso.');
    } catch (e:any) { alert(e?.message || 'Não foi possível salvar as métricas.'); }
  };

  const isFullWidthScreen = currentScreen === 'landing' || currentScreen === 'auth_login';

  return (
    <div className="min-h-screen bg-[#0A0A0A] text-[#e2e2e2] flex flex-col selection:bg-[#DFFF00] selection:text-[#0A0A0A]">
      <TopBar currentScreen={currentScreen} onNavigate={handleNavigate} userRole={userRole} onRoleChange={handleRoleChange} onOpenImageGallery={() => setIsImageGalleryOpen(true)} />
      <div className="flex-1 flex w-full">
        {!isFullWidthScreen && <Sidebar currentScreen={currentScreen} onNavigate={handleNavigate} userRole={userRole} />}
        <main className={`flex-1 w-full ${isFullWidthScreen ? 'p-0' : 'p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto pb-24 lg:pb-12'}`}>
          {currentScreen === 'landing' && <LandingScreen onNavigate={handleNavigate} onOpenImageGallery={() => setIsImageGalleryOpen(true)} />}
          {currentScreen === 'auth_login' && <AuthLoginScreen onNavigate={handleNavigate} onLoginSuccess={handleLoginSuccess} />}
          {currentScreen === 'coach_dashboard' && <CoachDashboardScreen onNavigate={handleNavigate} onSelectStudent={handleSelectStudent} />}
          {currentScreen === 'prescription' && <PrescriptionScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} />}
          {currentScreen === 'student_hub' && <StudentHubScreen onNavigate={handleNavigate} />}
          {currentScreen === 'student_daily' && <StudentDailyScreen onNavigate={handleNavigate} />}
          {currentScreen === 'workout_execution' && <WorkoutExecutionScreen onNavigate={handleNavigate} />}
          {currentScreen === 'student_progress' && <StudentProgressScreen onNavigate={handleNavigate} onOpenMetricsModal={() => setIsMetricsModalOpen(true)} selectedStudent={selectedStudent} userRole={userRole} />}
          {currentScreen === 'student_profile' && <StudentProfileScreen onNavigate={handleNavigate} onOpenMetricsModal={() => setIsMetricsModalOpen(true)} />}
          {currentScreen === 'anamnese' && <AnamneseScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole} />}
          {currentScreen === 'chat' && <ChatScreen onNavigate={handleNavigate} selectedStudent={selectedStudent} userRole={userRole} />}
          {currentScreen === 'agenda' && <AgendaScreen onNavigate={handleNavigate} />}
          {currentScreen === 'assinatura' && <AssinaturaScreen onNavigate={handleNavigate} />}
          {currentScreen === 'admin_system' && <AdminDashboardScreen onNavigate={handleNavigate} />}
        </main>
      </div>
      {!isFullWidthScreen && <MobileBottomNav currentScreen={currentScreen} onNavigate={handleNavigate} />}
      <ImageGalleryModal isOpen={isImageGalleryOpen} onClose={() => setIsImageGalleryOpen(false)} />
      <MetricsUpdateModal isOpen={isMetricsModalOpen} onClose={() => setIsMetricsModalOpen(false)} onSave={handleSaveMetrics} initialData={{ weight: selectedStudent.weight, bodyFat: selectedStudent.bodyFat }} />
    </div>
  );
}
