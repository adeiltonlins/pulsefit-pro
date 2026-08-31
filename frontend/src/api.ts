let csrfToken = '';

export type ApiUser = {
  id: number | string; name: string; email: string; role: 'admin' | 'coach' | 'student';
  code?: string; cref?: string; status?: string; mustChangePassword?: number | boolean;
};
export class ApiError extends Error { status:number; constructor(message:string,status:number){ super(message); this.status=status; } }

async function request<T>(path:string, options:RequestInit={}):Promise<T>{
  const headers=new Headers(options.headers||{});
  const isForm=typeof FormData!=='undefined' && options.body instanceof FormData;
  if(options.body && !isForm && !headers.has('Content-Type')) headers.set('Content-Type','application/json');
  if(csrfToken && !['GET','HEAD'].includes((options.method||'GET').toUpperCase())) headers.set('X-CSRF-Token',csrfToken);
  const response=await fetch(`/api${path}`,{...options,headers,credentials:'include'});
  let data:any={}; try{data=await response.json();}catch{}
  if(!response.ok) throw new ApiError(data?.error||`Erro HTTP ${response.status}`,response.status);
  if(data?.csrf) csrfToken=data.csrf; return data as T;
}
const qs=(studentId?:string|number)=>studentId?`?studentId=${encodeURIComponent(String(studentId))}`:'';

export const api={
  health:()=>request<any>('/health'),
  login:(email:string,password:string)=>request<{user:ApiUser;csrf:string}>('/auth/login',{method:'POST',body:JSON.stringify({email,password})}),
  me:()=>request<{user:ApiUser;csrf:string}>('/auth/me'),
  logout:()=>request<any>('/auth/logout',{method:'POST'}),
  forgotPassword:(email:string)=>request<any>('/auth/forgot-password',{method:'POST',body:JSON.stringify({email})}),
  students:()=>request<{students:any[]}>('/students'), coaches:()=>request<{coaches:any[]}>('/coaches'), workouts:()=>request<{workouts:any[]}>('/workouts'),
  dashboard:()=>request<any>('/dashboard'), portal:()=>request<{student:any;workouts:any[]}>('/student/portal'), appointments:()=>request<any>('/appointments'), payments:()=>request<any>('/payments'), notifications:()=>request<any>('/notifications'),
  metrics:(id:string|number)=>request<any>(`/students/${id}/metrics`), saveMetrics:(id:string|number,p:any)=>request<any>(`/students/${id}/metrics`,{method:'POST',body:JSON.stringify(p)}),
  createWorkout:(p:any)=>request<any>('/workouts',{method:'POST',body:JSON.stringify(p)}), createAppointment:(p:any)=>request<any>('/appointments',{method:'POST',body:JSON.stringify(p)}), createPayment:(p:any)=>request<any>('/payments',{method:'POST',body:JSON.stringify(p)}),
  createCoach:(p:any)=>request<any>('/coaches',{method:'POST',body:JSON.stringify(p)}), createStudent:(p:any)=>request<any>('/students',{method:'POST',body:JSON.stringify(p)}),
  updateCoachStatus:(id:string|number,status:string)=>request<any>(`/coaches/${id}/status`,{method:'PATCH',body:JSON.stringify({status})}), updateStudentStatus:(id:string|number,status:string)=>request<any>(`/students/${id}/status`,{method:'PATCH',body:JSON.stringify({status})}),
  duplicateWorkout:(id:string|number)=>request<any>(`/workouts/${id}/duplicate`,{method:'POST'}), workoutAction:(id:string|number,action:'archive'|'publish')=>request<any>(`/workouts/${id}/${action}`,{method:'PATCH'}),
  exerciseLibrary:()=>request<{exercises:any[]}>('/exercise-library'),
  createLibraryExercise:(p:any)=>request<any>('/exercise-library',{method:'POST',body:JSON.stringify(p)}),
  uploadMedia:(file:File)=>{const f=new FormData();f.append('file',file);return request<{ok:boolean;url:string}>('/uploads',{method:'POST',body:f});},
  getAnamnese:(studentId?:string|number)=>request<any>(`/anamnese${qs(studentId)}`), saveAnamnese:(p:any)=>request<any>('/anamnese',{method:'PUT',body:JSON.stringify(p)}),
  messages:(studentId?:string|number)=>request<any>(`/messages${qs(studentId)}`), sendMessage:(text:string,studentId?:string|number)=>request<any>('/messages',{method:'POST',body:JSON.stringify({text,studentId})}),
  progress:(studentId?:string|number)=>request<any>(`/progress${qs(studentId)}`),
  uploadProgressPhoto:(file:File,caption='',studentId?:string|number)=>{const f=new FormData();f.append('file',file);f.append('caption',caption);if(studentId)f.append('studentId',String(studentId));return request<any>('/progress/photos',{method:'POST',body:f});},
  startWorkout:(id:string|number)=>request<any>(`/workouts/${id}/start`,{method:'POST'}),
  logSet:(sessionId:string|number,p:any)=>request<any>(`/workout-sessions/${sessionId}/sets`,{method:'POST',body:JSON.stringify(p)}),
  completeSession:(sessionId:string|number,durationSeconds:number)=>request<any>(`/workout-sessions/${sessionId}/complete`,{method:'PATCH',body:JSON.stringify({durationSeconds})}),
};

export function normalizeStudent(s:any){return {id:String(s.id),name:s.name||'Aluno',email:s.email||'',role:'Athlete',avatar:s.avatar||'',status:s.status==='inactive'?'expired':(s.status==='active'?'active':'review'),programName:s.programName||'Sem programa',phase:s.phase||'Sem fase definida',lastCheckIn:s.lastCheckIn||'Sem check-in',age:Number(s.age||0),height:Number(s.height||0),weight:Number(s.weight||0),bodyFat:Number(s.bodyFat||0),assignedCoachId:s.assignedCoachId?String(s.assignedCoachId):undefined,assignedCoachName:s.assignedCoachName||'',planName:s.planName||'',joinedDate:s.joinedDate||''};}
