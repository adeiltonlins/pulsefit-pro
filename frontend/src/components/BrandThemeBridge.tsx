import React,{useEffect,useState} from 'react';
export const BrandThemeBridge:React.FC<{enabled:boolean}>=({enabled})=>{
 const [brand,setBrand]=useState<any>(null);
 useEffect(()=>{if(!enabled){setBrand(null);return}fetch('/api/brand-kit',{credentials:'include'}).then(r=>r.json()).then(d=>setBrand(d.brand||null)).catch(()=>setBrand(null))},[enabled]);
 if(!brand)return null;
 const p=brand.primaryColor||'#DFFF00';
 return <style>{`
 :root{--pf-brand-primary:${p};}
 [class~="text-[#DFFF00]"]{color:var(--pf-brand-primary)!important;}
 [class~="bg-[#DFFF00]"]{background-color:var(--pf-brand-primary)!important;}
 [class~="border-[#DFFF00]"]{border-color:var(--pf-brand-primary)!important;}
 [class~="border-[#DFFF00]/40"]{border-color:color-mix(in srgb,var(--pf-brand-primary) 40%,transparent)!important;}
 [class~="border-[#DFFF00]/30"]{border-color:color-mix(in srgb,var(--pf-brand-primary) 30%,transparent)!important;}
 `}</style>;
};
