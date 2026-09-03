import React,{useEffect,useRef,useState} from 'react';
import * as THREE from 'three';

type Angles={
 headTiltDeg?:number; shoulderTiltDeg?:number; pelvicTiltDeg?:number; trunkLeanDeg?:number;
 kneeAlignmentLeftDeg?:number; kneeAlignmentRightDeg?:number;
};

export const PosturalBody3D:React.FC<{angles:Angles;expanded?:boolean}>=({angles,expanded=false})=>{
 const mount=useRef<HTMLDivElement|null>(null);const [error,setError]=useState('');
 useEffect(()=>{
  if(!mount.current)return;const el=mount.current;setError('');let renderer:THREE.WebGLRenderer|null=null;let frame=0;let ro:ResizeObserver|null=null;
  try{
   const scene=new THREE.Scene();scene.background=new THREE.Color('#080909');
   const camera=new THREE.PerspectiveCamera(42,1,0.1,100);camera.position.set(0,0.2,6.4);
   renderer=new THREE.WebGLRenderer({antialias:true,alpha:false,powerPreference:'high-performance'});renderer.setPixelRatio(Math.min(window.devicePixelRatio,2));renderer.setClearColor(0x080909,1);renderer.domElement.style.display='block';renderer.domElement.style.width='100%';renderer.domElement.style.height='100%';el.replaceChildren(renderer.domElement);
   const root=new THREE.Group();scene.add(root);
   const lime=new THREE.MeshStandardMaterial({color:'#DFFF00',roughness:.55,metalness:.05});const white=new THREE.MeshStandardMaterial({color:'#f3f3f3',roughness:.7});const dark=new THREE.MeshStandardMaterial({color:'#343838',roughness:.8});
   const sphere=(r:number,mat=white)=>new THREE.Mesh(new THREE.SphereGeometry(r,24,18),mat);const cylinder=(r:number,len:number,mat=white)=>new THREE.Mesh(new THREE.CylinderGeometry(r,r,len,16),mat);
   const joint=(x:number,y:number,z=0,r=.09)=>{const m=sphere(r,lime);m.position.set(x,y,z);root.add(m);return m};
   const bone=(a:THREE.Vector3,b:THREE.Vector3,r=.055,mat=white)=>{const mid=a.clone().add(b).multiplyScalar(.5);const m=cylinder(r,a.distanceTo(b),mat);m.position.copy(mid);m.quaternion.setFromUnitVectors(new THREE.Vector3(0,1,0),b.clone().sub(a).normalize());root.add(m);return m};
   const headTilt=THREE.MathUtils.degToRad(Number(angles.headTiltDeg||0)),shoulderTilt=THREE.MathUtils.degToRad(Number(angles.shoulderTiltDeg||0)),pelvicTilt=THREE.MathUtils.degToRad(Number(angles.pelvicTiltDeg||0)),trunkLean=THREE.MathUtils.degToRad(Number(angles.trunkLeanDeg||0)),kneeL=THREE.MathUtils.degToRad(Number(angles.kneeAlignmentLeftDeg||0)),kneeR=THREE.MathUtils.degToRad(Number(angles.kneeAlignmentRightDeg||0));
   const hipC=new THREE.Vector3(0,-.55,0),shoulderC=new THREE.Vector3(Math.sin(trunkLean)*.45,.95,0),headC=new THREE.Vector3(shoulderC.x+Math.sin(headTilt)*.16,1.75,0),shoulderL=new THREE.Vector3(shoulderC.x-.62,shoulderC.y-Math.sin(shoulderTilt)*.30,0),shoulderR=new THREE.Vector3(shoulderC.x+.62,shoulderC.y+Math.sin(shoulderTilt)*.30,0),hipL=new THREE.Vector3(-.34,hipC.y-Math.sin(pelvicTilt)*.18,0),hipR=new THREE.Vector3(.34,hipC.y+Math.sin(pelvicTilt)*.18,0),elbowL=new THREE.Vector3(shoulderL.x-.20,.15,.04),elbowR=new THREE.Vector3(shoulderR.x+.20,.15,.04),wristL=new THREE.Vector3(elbowL.x-.05,-.58,.03),wristR=new THREE.Vector3(elbowR.x+.05,-.58,.03),kneeLP=new THREE.Vector3(hipL.x+Math.sin(kneeL)*.28,-1.55,0),kneeRP=new THREE.Vector3(hipR.x-Math.sin(kneeR)*.28,-1.55,0),ankleL=new THREE.Vector3(kneeLP.x-.02,-2.45,0),ankleR=new THREE.Vector3(kneeRP.x+.02,-2.45,0);
   const torso=new THREE.Mesh(new THREE.CapsuleGeometry(.42,1.0,8,16),dark);torso.position.copy(shoulderC.clone().add(hipC).multiplyScalar(.5));torso.rotation.z=-trunkLean;root.add(torso);const head=sphere(.31,white);head.position.copy(headC);head.rotation.z=-headTilt;root.add(head);
   bone(shoulderL,shoulderR,.07,lime);bone(hipL,hipR,.07,lime);bone(shoulderC,hipC,.065,dark);[[shoulderL,elbowL],[elbowL,wristL],[shoulderR,elbowR],[elbowR,wristR],[hipL,kneeLP],[kneeLP,ankleL],[hipR,kneeRP],[kneeRP,ankleR]].forEach(([a,b])=>bone(a as THREE.Vector3,b as THREE.Vector3));[shoulderL,shoulderR,hipL,hipR,kneeLP,kneeRP,ankleL,ankleR].forEach(p=>joint(p.x,p.y,p.z));
   const floor=new THREE.Mesh(new THREE.CircleGeometry(1.7,40),new THREE.MeshBasicMaterial({color:'#161919'}));floor.rotation.x=-Math.PI/2;floor.position.y=-2.55;scene.add(floor);scene.add(new THREE.HemisphereLight(0xffffff,0x111111,2.2));const key=new THREE.DirectionalLight(0xDFFF00,2.4);key.position.set(3,5,5);scene.add(key);
   let rotY=0,drag=false,lastX=0;const down=(e:PointerEvent)=>{drag=true;lastX=e.clientX;renderer?.domElement.setPointerCapture(e.pointerId)},move=(e:PointerEvent)=>{if(!drag)return;rotY+=(e.clientX-lastX)*.012;lastX=e.clientX},up=()=>{drag=false};renderer.domElement.addEventListener('pointerdown',down);renderer.domElement.addEventListener('pointermove',move);renderer.domElement.addEventListener('pointerup',up);
   const resize=()=>{if(!renderer)return;const rect=el.getBoundingClientRect();const w=Math.max(280,Math.floor(rect.width)),h=Math.max(320,Math.floor(rect.height));renderer.setSize(w,h,false);camera.aspect=w/h;camera.updateProjectionMatrix()};resize();ro=new ResizeObserver(resize);ro.observe(el);
   const animate=()=>{frame=requestAnimationFrame(animate);root.rotation.y=rotY;renderer?.render(scene,camera)};animate();
   return()=>{cancelAnimationFrame(frame);ro?.disconnect();renderer?.domElement.removeEventListener('pointerdown',down);renderer?.domElement.removeEventListener('pointermove',move);renderer?.domElement.removeEventListener('pointerup',up);renderer?.dispose();el.replaceChildren()};
  }catch(e){console.error(e);setError('Não foi possível iniciar a visualização 3D neste navegador.');return()=>{cancelAnimationFrame(frame);ro?.disconnect();renderer?.dispose();el.replaceChildren()}}
 },[expanded,angles.headTiltDeg,angles.shoulderTiltDeg,angles.pelvicTiltDeg,angles.trunkLeanDeg,angles.kneeAlignmentLeftDeg,angles.kneeAlignmentRightDeg]);
 return <div className="space-y-2"><div ref={mount} className={`w-full overflow-hidden border border-[#333] bg-[#080909] cursor-grab active:cursor-grabbing ${expanded?'h-[68vh] min-h-[520px] max-h-[780px]':'h-[430px]'}`}>{error&&<div className="h-full grid place-items-center p-6 text-center text-xs text-red-300">{error}</div>}</div><div className="text-[10px] text-gray-500">Arraste o modelo para girar. A visualização representa os dados inseridos pelo profissional e não realiza diagnóstico automático.</div></div>;
};
