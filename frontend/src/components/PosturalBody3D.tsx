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
   const camera=new THREE.PerspectiveCamera(38,1,0.1,100);camera.position.set(0,.05,7.2);
   renderer=new THREE.WebGLRenderer({antialias:true,alpha:false,powerPreference:'high-performance'});renderer.setPixelRatio(Math.min(window.devicePixelRatio,2));renderer.setClearColor(0x080909,1);renderer.outputColorSpace=THREE.SRGBColorSpace;renderer.domElement.style.display='block';renderer.domElement.style.width='100%';renderer.domElement.style.height='100%';el.replaceChildren(renderer.domElement);

   const root=new THREE.Group();scene.add(root);
   const skin=new THREE.MeshStandardMaterial({color:'#d8d9cf',roughness:.72,metalness:.02});
   const body=new THREE.MeshStandardMaterial({color:'#404640',roughness:.82,metalness:.02});
   const bodyLight=new THREE.MeshStandardMaterial({color:'#586055',roughness:.8,metalness:.02});
   const marker=new THREE.MeshStandardMaterial({color:'#DFFF00',roughness:.45,metalness:.04,emissive:'#293000',emissiveIntensity:.35});
   const dark=new THREE.MeshStandardMaterial({color:'#252927',roughness:.88});

   const ellipsoid=(rx:number,ry:number,rz:number,mat=body)=>{const m=new THREE.Mesh(new THREE.SphereGeometry(1,28,22),mat);m.scale.set(rx,ry,rz);return m};
   const sphere=(r:number,mat=skin)=>new THREE.Mesh(new THREE.SphereGeometry(r,28,22),mat);
   const limb=(a:THREE.Vector3,b:THREE.Vector3,r1:number,r2:number,mat=body)=>{const len=a.distanceTo(b);const geo=new THREE.CylinderGeometry(r2,r1,len,18,1,false);const m=new THREE.Mesh(geo,mat);m.position.copy(a.clone().add(b).multiplyScalar(.5));m.quaternion.setFromUnitVectors(new THREE.Vector3(0,1,0),b.clone().sub(a).normalize());root.add(m);return m};
   const joint=(p:THREE.Vector3,r=.075)=>{const m=sphere(r,marker);m.position.copy(p);root.add(m);return m};

   const clamp=(v:unknown)=>Math.max(-45,Math.min(45,Number(v)||0));
   const headTilt=THREE.MathUtils.degToRad(clamp(angles.headTiltDeg));
   const shoulderTilt=THREE.MathUtils.degToRad(clamp(angles.shoulderTiltDeg));
   const pelvicTilt=THREE.MathUtils.degToRad(clamp(angles.pelvicTiltDeg));
   const trunkLean=THREE.MathUtils.degToRad(clamp(angles.trunkLeanDeg));
   const kneeL=THREE.MathUtils.degToRad(clamp(angles.kneeAlignmentLeftDeg));
   const kneeR=THREE.MathUtils.degToRad(clamp(angles.kneeAlignmentRightDeg));

   const hipC=new THREE.Vector3(0,-.52,0);
   const chestC=new THREE.Vector3(Math.sin(trunkLean)*.52,.72,0);
   const shoulderC=new THREE.Vector3(chestC.x+Math.sin(trunkLean)*.15,1.18,0);
   const neckBase=new THREE.Vector3(shoulderC.x,1.39,0);
   const headC=new THREE.Vector3(neckBase.x+Math.sin(headTilt)*.20,1.83,0);
   const shoulderL=new THREE.Vector3(shoulderC.x-.58,shoulderC.y-Math.sin(shoulderTilt)*.30,0);
   const shoulderR=new THREE.Vector3(shoulderC.x+.58,shoulderC.y+Math.sin(shoulderTilt)*.30,0);
   const hipL=new THREE.Vector3(-.30,hipC.y-Math.sin(pelvicTilt)*.16,0);
   const hipR=new THREE.Vector3(.30,hipC.y+Math.sin(pelvicTilt)*.16,0);
   const elbowL=new THREE.Vector3(shoulderL.x-.14,.22,.03),elbowR=new THREE.Vector3(shoulderR.x+.14,.22,.03);
   const wristL=new THREE.Vector3(elbowL.x-.03,-.53,.05),wristR=new THREE.Vector3(elbowR.x+.03,-.53,.05);
   const kneeLP=new THREE.Vector3(hipL.x+Math.sin(kneeL)*.30,-1.52,0),kneeRP=new THREE.Vector3(hipR.x-Math.sin(kneeR)*.30,-1.52,0);
   const ankleL=new THREE.Vector3(kneeLP.x-.025,-2.42,.02),ankleR=new THREE.Vector3(kneeRP.x+.025,-2.42,.02);

   // Tronco anatômico simplificado: tórax, abdômen, pelve e pescoço independentes.
   const torsoGroup=new THREE.Group();torsoGroup.position.copy(hipC);torsoGroup.rotation.z=-trunkLean;root.add(torsoGroup);
   const chest=ellipsoid(.62,.68,.32,bodyLight);chest.position.set(0,1.07,0);torsoGroup.add(chest);
   const ribcage=ellipsoid(.55,.52,.30,body);ribcage.position.set(0,.76,0);torsoGroup.add(ribcage);
   const abdomen=ellipsoid(.39,.48,.27,dark);abdomen.position.set(0,.28,0);torsoGroup.add(abdomen);
   const pelvis=ellipsoid(.48,.30,.31,bodyLight);pelvis.position.set(0,-.02,0);pelvis.rotation.z=-pelvicTilt;torsoGroup.add(pelvis);
   const neck=new THREE.Mesh(new THREE.CylinderGeometry(.15,.18,.38,18),skin);neck.position.copy(neckBase.clone().add(shoulderC).multiplyScalar(.5));neck.rotation.z=-headTilt*.35;root.add(neck);

   const head=ellipsoid(.31,.38,.30,skin);head.position.copy(headC);head.rotation.z=-headTilt;root.add(head);
   const jaw=ellipsoid(.25,.18,.27,skin);jaw.position.set(headC.x,headC.y-.22,headC.z+.015);jaw.rotation.z=-headTilt;root.add(jaw);

   // Braços com volumes diferentes entre braço e antebraço.
   limb(shoulderL,elbowL,.15,.12,bodyLight);limb(elbowL,wristL,.12,.085,skin);
   limb(shoulderR,elbowR,.15,.12,bodyLight);limb(elbowR,wristR,.12,.085,skin);
   const handL=ellipsoid(.105,.17,.07,skin);handL.position.set(wristL.x,wristL.y-.12,.04);root.add(handL);
   const handR=ellipsoid(.105,.17,.07,skin);handR.position.set(wristR.x,wristR.y-.12,.04);root.add(handR);

   // Pernas com coxas e panturrilhas mais robustas.
   limb(hipL,kneeLP,.22,.16,body);limb(kneeLP,ankleL,.16,.105,bodyLight);
   limb(hipR,kneeRP,.22,.16,body);limb(kneeRP,ankleR,.16,.105,bodyLight);
   const footL=ellipsoid(.15,.10,.31,skin);footL.position.set(ankleL.x,ankleL.y-.08,.17);root.add(footL);
   const footR=ellipsoid(.15,.10,.31,skin);footR.position.set(ankleR.x,ankleR.y-.08,.17);root.add(footR);

   // Marcadores de referência postural, discretos mas legíveis.
   [shoulderL,shoulderR,hipL,hipR,kneeLP,kneeRP,ankleL,ankleR].forEach(p=>joint(p));
   const shoulderGuide=limb(shoulderL,shoulderR,.045,.045,marker);shoulderGuide.renderOrder=2;
   const hipGuide=limb(hipL,hipR,.045,.045,marker);hipGuide.renderOrder=2;

   const floor=new THREE.Mesh(new THREE.CircleGeometry(1.65,48),new THREE.MeshBasicMaterial({color:'#151918'}));floor.rotation.x=-Math.PI/2;floor.position.y=-2.55;scene.add(floor);
   scene.add(new THREE.HemisphereLight(0xf5f6ef,0x111311,2.1));
   const key=new THREE.DirectionalLight(0xDFFF00,2.15);key.position.set(3.5,5,5);scene.add(key);
   const fill=new THREE.DirectionalLight(0xffffff,1.35);fill.position.set(-4,2,4);scene.add(fill);
   const rim=new THREE.DirectionalLight(0x8ca3a0,.8);rim.position.set(0,2,-5);scene.add(rim);

   let rotY=0,drag=false,lastX=0;const down=(e:PointerEvent)=>{drag=true;lastX=e.clientX;renderer?.domElement.setPointerCapture(e.pointerId)},move=(e:PointerEvent)=>{if(!drag)return;rotY+=(e.clientX-lastX)*.012;lastX=e.clientX},up=()=>{drag=false};renderer.domElement.addEventListener('pointerdown',down);renderer.domElement.addEventListener('pointermove',move);renderer.domElement.addEventListener('pointerup',up);renderer.domElement.addEventListener('pointercancel',up);
   const resize=()=>{if(!renderer)return;const rect=el.getBoundingClientRect();const w=Math.max(280,Math.floor(rect.width)),h=Math.max(320,Math.floor(rect.height));renderer.setSize(w,h,false);camera.aspect=w/h;camera.updateProjectionMatrix()};resize();ro=new ResizeObserver(resize);ro.observe(el);
   const animate=()=>{frame=requestAnimationFrame(animate);root.rotation.y=rotY;renderer?.render(scene,camera)};animate();
   return()=>{cancelAnimationFrame(frame);ro?.disconnect();renderer?.domElement.removeEventListener('pointerdown',down);renderer?.domElement.removeEventListener('pointermove',move);renderer?.domElement.removeEventListener('pointerup',up);renderer?.domElement.removeEventListener('pointercancel',up);renderer?.dispose();el.replaceChildren()};
  }catch(e){console.error(e);setError('Não foi possível iniciar a visualização 3D neste navegador.');return()=>{cancelAnimationFrame(frame);ro?.disconnect();renderer?.dispose();el.replaceChildren()}}
 },[expanded,angles.headTiltDeg,angles.shoulderTiltDeg,angles.pelvicTiltDeg,angles.trunkLeanDeg,angles.kneeAlignmentLeftDeg,angles.kneeAlignmentRightDeg]);
 return <div className="space-y-2"><div ref={mount} className={`w-full overflow-hidden border border-[#333] bg-[#080909] cursor-grab active:cursor-grabbing ${expanded?'h-[68vh] min-h-[520px] max-h-[780px]':'h-[430px]'}`}>{error&&<div className="h-full grid place-items-center p-6 text-center text-xs text-red-300">{error}</div>}</div><div className="text-[10px] text-gray-500">Arraste o modelo para girar. O corpo é uma representação anatômica simplificada dos dados inseridos pelo profissional; não realiza diagnóstico automático.</div></div>;
};
