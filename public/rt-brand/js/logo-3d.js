const hosts = [...document.querySelectorAll('[data-rt-logo-3d]')];
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* Lite-Mode ist nur noch eine bewusste Notfalloption. Touch-Geräte versuchen
   WebGL mit reduzierter Pixeldichte und fallen bei Fehlern sauber auf SVG zurück. */
const liteMode = document.documentElement.classList.contains('lite-mode');
const touchLike = matchMedia('(hover: none), (pointer: coarse)').matches;

let THREE = null;
let GLTFLoader = null;
if (hosts.length && !liteMode) {
    try {
        THREE = await import('../vendor/three/build/three.module.min.js');
        ({ GLTFLoader } = await import('../vendor/three/examples/jsm/loaders/GLTFLoader.js?v=2'));
    } catch (error) {
        console.warn('three.js konnte nicht geladen werden — SVG-Fallback bleibt aktiv.', error);
    }
}

hosts.forEach((host) => {
  if (!THREE || !GLTFLoader) {
    host.classList.add('is-fallback');
    return;
  }
  const canvas = host.querySelector('canvas');
  if (!canvas) return;

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: true,
      alpha: true,
      powerPreference: touchLike ? 'default' : 'high-performance'
    });
  } catch (error) {
    host.classList.add('is-fallback');
    console.warn('Das 3D-Logo konnte nicht initialisiert werden.', error);
    return;
  }

  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, touchLike ? 1.35 : 1.8));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.18;
  renderer.setClearColor(0x000000, 0);

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(34, 1, .01, 100);
  camera.position.set(.08, .035, 2.35);
  scene.add(new THREE.HemisphereLight(0xf7f9ff, 0x15090d, 1.72));

  const key = new THREE.DirectionalLight(0xffffff, 3.1);
  key.position.set(-1.7, 2.4, 3.5);
  scene.add(key);

  const rim = new THREE.DirectionalLight(0xe4002b, 2.25);
  rim.position.set(2.8, -.5, 1.5);
  scene.add(rim);

  const fill = new THREE.DirectionalLight(0x9aa7b8, 1.05);
  fill.position.set(-2.2, -1.4, 1.4);
  scene.add(fill);

  let logo = null;
  let frame = 0;
  let inView = true;
  let pointerX = 0;
  let pointerY = 0;
  let rotationX = -.025;
  let rotationY = 0;
  const logoVariant = host.dataset.logoVariant;
  const fullSpin = ['noir-signal', 'full-spin'].includes(logoVariant);
  const strictFullSpin = logoVariant === 'full-spin';
  const waitForReveal = host.hasAttribute('data-logo-wait-for-reveal');
  const startedAt = performance.now();
  let animationStartedAt = null;
  let destroyed = false;

  const resize = () => {
    const rect = host.getBoundingClientRect();
    const width = Math.max(1, Math.round(rect.width));
    const height = Math.max(1, Math.round(rect.height));
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height, false);
    if (reducedMotion && logo) renderer.render(scene, camera);
  };

  const stop = () => {
    if (!frame) return;
    cancelAnimationFrame(frame);
    frame = 0;
  };

  const onContextLost = (event) => {
    event.preventDefault();
    host.classList.remove('is-ready');
    host.classList.add('is-fallback');
    stop();
  };
  canvas.addEventListener('webglcontextlost', onContextLost, false);

  const tick = (now) => {
    frame = 0;
    if (destroyed || !logo || !inView || document.hidden) return;
    const revealReady = !waitForReveal || host.closest('.rt-hero')?.classList.contains('is-video-logo-visible');
    if (!revealReady) {
      logo.rotation.set(-.025, 0, 0);
      logo.position.y = 0;
      renderer.render(scene, camera);
      frame = requestAnimationFrame(tick);
      return;
    }
    if (animationStartedAt === null) animationStartedAt = now;
    const elapsed = (now - (animationStartedAt ?? startedAt)) / 1000;
    const targetX = -.025 + pointerY * .08;
    rotationX += (targetX - rotationX) * .07;
    if (fullSpin) {
      rotationY = (elapsed * .52) % (Math.PI * 2);
      const yaw = strictFullSpin ? rotationY : rotationY + pointerX * .2;
      logo.rotation.set(rotationX, yaw, Math.sin(elapsed * .72) * .028);
      logo.position.y = Math.sin(elapsed * 1.15) * .022;
    } else {
      const targetY = Math.sin(elapsed * .58) * .19 + pointerX * .24;
      rotationY += (targetY - rotationY) * .055;
      logo.rotation.set(rotationX, rotationY, 0);
      logo.position.y = Math.sin(elapsed * .9) * .012;
    }
    renderer.render(scene, camera);
    frame = requestAnimationFrame(tick);
  };

  const start = () => {
    if (destroyed || !logo || frame || !inView || document.hidden) return;
    if (reducedMotion) {
      logo.rotation.set(-.025, .08, 0);
      renderer.render(scene, camera);
      return;
    }
    frame = requestAnimationFrame(tick);
  };

  host.addEventListener('pointermove', (event) => {
    const rect = host.getBoundingClientRect();
    pointerX = ((event.clientX - rect.left) / rect.width - .5) * 2;
    pointerY = ((event.clientY - rect.top) / rect.height - .5) * 2;
    start();
  }, { passive: true });
  host.addEventListener('pointerleave', () => {
    pointerX = 0;
    pointerY = 0;
  }, { passive: true });

  const resizeObserver = new ResizeObserver(resize);
  resizeObserver.observe(host);
  resize();

  const visibilityObserver = new IntersectionObserver((entries) => {
    inView = entries[0]?.isIntersecting ?? true;
    if (inView) start();
    else stop();
  }, { rootMargin: '120px' });
  visibilityObserver.observe(host);

  const onVisibilityChange = () => {
    if (document.hidden) stop();
    else start();
  };
  document.addEventListener('visibilitychange', onVisibilityChange);

  const modelUrl = host.dataset.modelSrc || new URL('../models/rt-logo.glb', import.meta.url).href;
  new GLTFLoader().load(modelUrl, ({ scene: model }) => {
    if (destroyed) {
      model.traverse((object) => {
        object.geometry?.dispose?.();
        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach((material) => material.dispose?.());
      });
      return;
    }
    logo = model.getObjectByName('RT_Logo') ?? model;
    scene.add(model);
    host.classList.remove('is-fallback');
    host.classList.add('is-ready');
    resize();
    start();
  }, undefined, (error) => {
    host.classList.add('is-fallback');
    console.warn('Das 3D-Logo konnte nicht geladen werden.', error);
  });

  const destroy = () => {
    if (destroyed) return;
    destroyed = true;
    stop();
    resizeObserver.disconnect();
    visibilityObserver.disconnect();
    canvas.removeEventListener('webglcontextlost', onContextLost, false);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('railtime:overview-intro-dispose', onOverviewIntroDispose);
    removeEventListener('pagehide', destroy);
    renderer.dispose();
    renderer.forceContextLoss?.();
  };
  const onOverviewIntroDispose = () => {
    if (host.closest('.intro-screen')) destroy();
  };
  document.addEventListener('railtime:overview-intro-dispose', onOverviewIntroDispose);
  addEventListener('pagehide', destroy, { once: true });
});
