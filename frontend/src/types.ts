export type ScreenView = 
  | 'landing'
  | 'auth_login'
  | 'coach_dashboard'
  | 'coach_growth'
  | 'prescription'
  | 'fitness_tools'
  | 'postural_assessment'
  | 'gamification_ranking'
  | 'student_detail'
  | 'student_hub'
  | 'student_daily'
  | 'workout_execution'
  | 'student_progress'
  | 'student_checkin'
  | 'anamnese'
  | 'student_profile'
  | 'chat'
  | 'agenda'
  | 'assinatura'
  | 'admin_system';

export type UserRole = 'coach' | 'student' | 'admin' | 'public';

export interface AuthUser { id:string; name:string; email:string; role:UserRole; avatar:string; codeOrCref:string; tierOrSpecialty:string; }
export interface CoachTeacher { id:string; code:string; name:string; email:string; avatar:string; cref:string; specialty:string; studentsCount:number; adherenceRate:string; monthlyRevenue:string; status:'active'|'review'|'inactive'; rating:number; unit:string; }
export interface ExerciseItem { id:string; order:string; name:string; category:string; type:string; equipment:string; thumbnail:string; sets:number; reps:string; load:string; rest:number; rpe?:number; tempo?:string; completed?:boolean; instructions?:string; libraryExerciseId?:number; mediaType?:'image'|'video'; }
export interface Student {
  id:string; name:string; email?:string; role:string; avatar:string; status:'active'|'expired'|'review'; programName:string; phase:string; lastCheckIn:string; age:number; height:number; weight:number; bodyFat:number; assignedCoachId?:string; assignedCoachName?:string; planName?:string; joinedDate?:string;
  archivedAt?:string|null; lastWorkoutAt?:string|null; lastWeeklyCheckin?:string|null; hasOverduePayment?:boolean; workoutLate?:boolean; checkinPending?:boolean;
}
export interface ChatMessage { id:string; sender:'trainer'|'student'|'system'; senderName:string; time:string; text:string; }
export interface SessionSlot { id:string; time:string; title:string; type:'HIIT'|'LIFT'|'MOBILITY'|'CARDIO'; trainerName:string; trainerAvatar:string; totalSpots:number; takenSpots:number; isBooked?:boolean; }
export interface SystemLog { id:string; type:'Sub_New'|'Anam_Comp'|'Alert_Fail'|'Workout_Done'|'User_Auth'|'Admin_Action'; timeAgo:string; message:string; severity?:'normal'|'error'|'success'; }
