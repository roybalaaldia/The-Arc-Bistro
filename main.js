(function(){
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $ = (s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];

  function start(){

  /* ---------- smooth scroll ---------- */
  let lenis = null;
  if(!reduce && window.Lenis){
    lenis = new Lenis({duration:1.15, easing:t=>Math.min(1,1.001-Math.pow(2,-10*t))});
    if(window.gsap && window.ScrollTrigger){
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add(t=>lenis.raf(t*1000));
      gsap.ticker.lagSmoothing(0);
    } else {
      const raf=t=>{lenis.raf(t);requestAnimationFrame(raf)};requestAnimationFrame(raf);
    }
  }
  // anchor links
  $$('a[href^="#"]').forEach(a=>a.addEventListener('click',e=>{
    const id=a.getAttribute('href'); const el=id==='#top'?document.body:$(id);
    if(!el) return; e.preventDefault(); closeMenu();
    if(lenis) lenis.scrollTo(el,{offset:id==='#top'?0:-60}); else el.scrollIntoView({behavior:reduce?'auto':'smooth'});
  }));

  /* ---------- header + drawer ---------- */
  const hdr=$('#hdr'), burger=$('#burger'), drawer=$('#drawer');
  const onScroll=()=>hdr.classList.toggle('is-solid', window.scrollY>60);
  window.addEventListener('scroll',onScroll,{passive:true}); onScroll();
  function closeMenu(){document.body.classList.remove('menu-open');burger.setAttribute('aria-expanded','false');drawer.setAttribute('aria-hidden','true');lenis&&lenis.start()}
  burger.addEventListener('click',()=>{
    const open=!document.body.classList.contains('menu-open');
    if(!open) return closeMenu();
    document.body.classList.add('menu-open');burger.setAttribute('aria-expanded','true');drawer.setAttribute('aria-hidden','false');lenis&&lenis.stop();
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});

  /* ---------- occasions highlight cycle ---------- */
  const occ=$$('#occList li'); let oi=0;
  if(!reduce) setInterval(()=>{occ.forEach(l=>l.classList.remove('is-lit'));occ[oi%occ.length].classList.add('is-lit');oi++},1400);

  /* ---------- GSAP motion ---------- */
  if(reduce || !window.gsap || !window.ScrollTrigger) return;
  gsap.registerPlugin(ScrollTrigger);

  // split headings into words
  $$('[data-split]').forEach(h=>{
    const words=h.textContent.trim().split(/\s+/);
    h.setAttribute('aria-label',h.textContent.trim());
    h.innerHTML=words.map(w=>`<span class="w" aria-hidden="true"><span>${w}</span></span>`).join(' ');
  });

  // hero entrance (above the fold: plays on load)
  const heroWords=$$('.hero h1 .w > span');
  const tl=gsap.timeline({defaults:{ease:'power4.out'}});
  tl.from('.hero__bg .media__inner',{scale:1.18,duration:2.2,ease:'power2.out'},0)
    .from(heroWords,{yPercent:110,duration:1.3,stagger:.06},.4)
    .from('.hero .lede, .hero__ctas',{y:24,opacity:0,duration:1.1,stagger:.12},.9)
    .from('.hours__card',{y:120,duration:1.4},.6)
    .from('.hdr',{y:-30,opacity:0,duration:1},.2);

  const inView=el=>el.getBoundingClientRect().top < window.innerHeight*0.9;

  // word reveals for section headings
  $$('[data-split]').forEach(h=>{
    if(h.closest('.hero')) return;
    const w=$$('.w > span',h);
    if(inView(h)) return;
    gsap.from(w,{yPercent:110,duration:1.2,ease:'power4.out',stagger:.05,scrollTrigger:{trigger:h,start:'top 88%'}});
  });

  // fade-up reveals
  $$('[data-reveal]').forEach(el=>{
    if(inView(el)) return;
    gsap.from(el,{y:40,opacity:0,duration:1.2,ease:'power3.out',scrollTrigger:{trigger:el,start:'top 90%'}});
  });

  // parallax inside every framed image
  $$('[data-parallax]').forEach(m=>{
    const inner=$('.media__inner',m); const big=m.dataset.parallax;
    gsap.fromTo(inner,{yPercent:big?-6:-7},{yPercent:big?10:7,ease:'none',
      scrollTrigger:{trigger:m,start:big==='hero'?'top top':'top bottom',end:'bottom top',scrub:true}});
  });
  // arch frames open up (clip from bottom) as they enter
  $$('.about__img, .trio .media').forEach(m=>{
    if(inView(m)) return;
    gsap.from(m,{clipPath:'inset(100% 0 0 0)',duration:1.6,ease:'power4.inOut',scrollTrigger:{trigger:m,start:'top 85%'}});
  });
  // hero content drifts and fades as you scroll away
  gsap.to('.hero__content',{yPercent:-18,opacity:.2,ease:'none',scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:true}});

  window.addEventListener('load',()=>ScrollTrigger.refresh());
  }

  fetch('content.json',{cache:'no-cache'})
    .then(r=>r.ok?r.json():Promise.reject())
    .then(c=>{ if(window.renderContent) window.renderContent(c); })
    .catch(()=>{})
    .then(start);
})();
