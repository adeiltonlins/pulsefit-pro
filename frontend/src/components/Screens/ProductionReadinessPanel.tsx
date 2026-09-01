import React,{useEffect,useState} from 'react';
import {api} from '../../api';

const Flag=({ok,label}:{ok:boolean;label:string})=><div className={`border p-3 ${ok?'border-green-800 bg-green-950/20':'border-yellow-700 bg-yellow-950/20'}`}><div className="text-[9px] uppercase text-gray-500">{label}</div><b className={ok?'text-green-400':'text-yellow-400'}>{ok?'OK':'PENDENTE'}</b></div>;
const Stat=({label,value}:{label:string;value:any})=><div className="bg-black border border-[#333] p-3"><div className="text-[9px] uppercase text-gray-500">{label}</div><b className="text-xl text-[#DFFF00]">{value}</b></div>;

export const ProductionReadinessPanel:React.FC=()=>{
 const [r,setR]=useState<any>(null),[risk,setRisk]=useState<any>({}),[error,setError]=useState('');
 useEffect(()=>{Promise.all([api.adminReadiness(),api.adminRiskDashboard()]).then(([a,b])=>{setR(a);setRisk(b.risks||{})}).catch((e:any)=>setError(e.message))},[]);
 if(error)return <section className="border border-red-900 bg-red-950/20 p-4 text-xs text-red-300">Prontidão operacional: {error}</section>;
 if(!r)return <section className="border border-[#333] p-4 text-xs text-gray-500">Carregando prontidão operacional...</section>;
 const c=r.checks||{},i=r.integrations||{};
 return <section className="bg-[#121414] border border-[#333] p-4 space-y-4"><div className="flex flex-col md:flex-row md:items-end justify-between gap-2"><div><div className="text-[10px] font-mono text-[#DFFF00]">// PRONTIDÃO DE PRODUÇÃO</div><h2 className="font-anybody text-xl font-black uppercase text-white">Saúde operacional</h2></div><span className={`text-xs font-black ${r.ready?'text-green-400':'text-yellow-400'}`}>{r.ready?'BASE PRONTA':'REQUER ATENÇÃO'}</span></div><div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2"><Flag ok={!!c.storageWritable} label="Storage"/><Flag ok={!!c.databaseWritable} label="Banco"/><Flag ok={!!c.recentBackup} label="Backup 48h"/><Flag ok={!!i.https} label="HTTPS"/><Flag ok={!!c.paymentsConfigured} label="Pagamentos"/><Flag ok={!!c.pushReady} label="Push"/><Flag ok={!!c.aiExternalReady} label="Gemini"/></div><div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2"><Stat label="Inativos 7d" value={risk.inactive7d||0}/><Stat label="Inadimplentes" value={risk.overduePayments||0}/><Stat label="Dor alta" value={risk.highPain||0}/><Stat label="Fadiga alta" value={risk.highFatigue||0}/><Stat label="Trial vence 7d" value={risk.trialsExpiring7d||0}/><Stat label="Trials expirados" value={risk.trialsExpired||0}/><Stat label="Past due" value={risk.subscriptionsPastDue||0}/></div>{r.latestBackup&&<div className="text-[10px] text-gray-500">Último backup: {r.latestBackup.name} • {new Date(r.latestBackup.createdAt).toLocaleString('pt-BR')}</div>}</section>;
};
