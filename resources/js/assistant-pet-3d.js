const PET_STATES = new Set(['idle', 'thinking', 'listening', 'speaking', 'offline']);
const MAX_DEVICE_PIXEL_RATIO = 1.75;
const MAX_FRAMES_PER_SECOND = 30;

export function normalizeAssistantPetState(state) {
    const normalized = String(state ?? '').trim().toLowerCase();

    return PET_STATES.has(normalized) ? normalized : 'idle';
}

export function shouldRenderAssistantPetFrame(lastFrameAt, frameAt, framesPerSecond = MAX_FRAMES_PER_SECOND) {
    if (!Number.isFinite(lastFrameAt) || lastFrameAt <= 0) {
        return true;
    }

    return frameAt - lastFrameAt >= (1000 / framesPerSecond) - 0.5;
}

function applyTransform(object, { position, rotation, scale } = {}) {
    if (position) object.position.set(...position);
    if (rotation) object.rotation.set(...rotation);
    if (scale) object.scale.set(...scale);

    return object;
}

function createPetModel(THREE) {
    const root = new THREE.Group();
    root.name = 'railtime-assistant-organic-baby';

    const bodyMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xf13b55,
        roughness: 0.52,
        metalness: 0,
        clearcoat: 0.2,
        clearcoatRoughness: 0.72,
        sheen: 0.55,
        sheenColor: 0xff9aad,
        emissive: 0x3d020e,
        emissiveIntensity: 0.08,
    });
    const bodyDeepMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xb91636,
        roughness: 0.6,
        metalness: 0,
        clearcoat: 0.12,
        clearcoatRoughness: 0.8,
    });
    const bellyMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xffa2ac,
        roughness: 0.64,
        metalness: 0,
        clearcoat: 0.08,
    });
    const innerEarMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xffc4ca,
        roughness: 0.7,
        metalness: 0,
    });
    const inkMaterial = new THREE.MeshPhysicalMaterial({
        color: 0x251219,
        roughness: 0.28,
        metalness: 0,
        clearcoat: 0.72,
        clearcoatRoughness: 0.18,
    });
    const eyeShineMaterial = new THREE.MeshBasicMaterial({ color: 0xffffff });
    const cheekMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xff7187,
        roughness: 0.72,
        transparent: true,
        opacity: 0.78,
    });
    const glowMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xffe1e5,
        emissive: 0xff3158,
        emissiveIntensity: 1.2,
        roughness: 0.3,
        metalness: 0,
        clearcoat: 0.5,
    });
    const particleMaterial = new THREE.MeshBasicMaterial({
        color: 0xff8da1,
        transparent: true,
        opacity: 0,
        depthWrite: false,
    });
    const signalMaterial = new THREE.MeshBasicMaterial({
        color: 0xffa6b5,
        transparent: true,
        opacity: 0,
        depthWrite: false,
    });

    const body = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 40, 28), bodyMaterial),
        { position: [0, -0.03, 0], scale: [1.08, 1.08, 0.88], rotation: [-0.04, 0, 0] },
    );
    root.add(body);

    const belly = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 30, 20), bellyMaterial),
        { position: [0, -0.35, 0.72], scale: [0.63, 0.55, 0.13] },
    );
    root.add(belly);

    const feet = [-1, 1].map((side) => {
        const foot = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 28, 18), bodyDeepMaterial),
            {
                position: [side * 0.56, -0.94, 0.16],
                rotation: [0.08, side * -0.18, side * -0.08],
                scale: [0.48, 0.24, 0.48],
            },
        );
        root.add(foot);

        return foot;
    });

    const earPivots = [-1, 1].map((side) => {
        const pivot = new THREE.Group();
        pivot.position.set(side * 0.82, 0.5, -0.01);
        pivot.rotation.z = side * -0.52;

        const ear = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 28, 20), bodyMaterial),
            { position: [side * 0.22, 0.08, 0], scale: [0.38, 0.69, 0.24] },
        );
        const innerEar = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 22, 16), innerEarMaterial),
            { position: [side * 0.24, 0.09, 0.2], scale: [0.2, 0.45, 0.055] },
        );
        pivot.add(ear, innerEar);
        root.add(pivot);

        return pivot;
    });

    const eyes = [-1, 1].map((side) => {
        const eye = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 24, 18), inkMaterial),
            { position: [side * 0.38, 0.26, 0.81], scale: [0.16, 0.22, 0.095] },
        );
        eye.userData.baseScaleY = 0.22;

        const shine = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 16, 12), eyeShineMaterial),
            { position: [side * 0.34, 0.34, 0.9], scale: [0.045, 0.06, 0.025] },
        );
        root.add(eye, shine);

        return eye;
    });

    const cheeks = [-1, 1].map((side) => {
        const cheek = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 20, 14), cheekMaterial),
            { position: [side * 0.67, -0.02, 0.76], scale: [0.19, 0.1, 0.045] },
        );
        root.add(cheek);

        return cheek;
    });

    const mouth = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), inkMaterial),
        { position: [0, -0.16, 0.91], scale: [0.17, 0.075, 0.045] },
    );
    mouth.userData.baseScaleY = 0.075;
    const tongue = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 18, 12), cheekMaterial),
        { position: [0, -0.19, 0.95], scale: [0.085, 0.035, 0.018] },
    );
    root.add(mouth, tongue);

    const gem = applyTransform(
        new THREE.Mesh(new THREE.OctahedronGeometry(0.14, 2), glowMaterial),
        { position: [0, 0.78, 0.69], rotation: [0.12, 0, Math.PI / 4], scale: [1, 1.18, 0.7] },
    );
    root.add(gem);

    const tuft = new THREE.Group();
    [-1, 0, 1].forEach((offset) => {
        const tuftLobe = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 18, 14), bodyDeepMaterial),
            {
                position: [offset * 0.14, 0.97 - Math.abs(offset) * 0.035, -0.04],
                rotation: [0, 0, offset * -0.28],
                scale: [0.14, 0.3 - Math.abs(offset) * 0.04, 0.12],
            },
        );
        tuft.add(tuftLobe);
    });
    root.add(tuft);

    const particles = [];
    const particleGroup = new THREE.Group();
    for (let index = 0; index < 7; index += 1) {
        const particle = new THREE.Mesh(new THREE.SphereGeometry(0.045, 12, 10), particleMaterial.clone());
        particle.userData.angle = (Math.PI * 2 * index) / 7;
        particle.userData.radius = 1.32 + (index % 2) * 0.13;
        particleGroup.add(particle);
        particles.push(particle);
    }
    root.add(particleGroup);

    const listeningRings = [-1, 1].map((side) => {
        const ring = applyTransform(
            new THREE.Mesh(new THREE.TorusGeometry(0.33, 0.022, 8, 40), signalMaterial.clone()),
            { position: [side * 1.16, 0.48, 0.05], scale: [0.65, 1, 1] },
        );
        ring.userData.side = side;
        root.add(ring);

        return ring;
    });

    root.rotation.x = -0.03;

    return {
        root,
        body,
        bodyMaterial,
        cheeks,
        earPivots,
        eyes,
        feet,
        gem,
        glowMaterial,
        listeningRings,
        mouth,
        particleGroup,
        particles,
        tongue,
        tuft,
    };
}

export function railtimeAssistantPet3d(options = {}) {
    return {
        petActiveSlot: null,
        petAnimationLoop: null,
        petCamera: null,
        petCanvas: null,
        petDestroyed: false,
        petDocumentHidden: false,
        petHovered: false,
        petInViewport: false,
        petIntersectionObserver: null,
        petLastFrameAt: 0,
        petLoading: false,
        petLoopRunning: false,
        petModel: null,
        petMutationObserver: null,
        petReactionStartedAt: 0,
        petReactionUntil: 0,
        petReducedMotion: false,
        petReducedMotionQuery: null,
        petRenderer: null,
        petResizeObserver: null,
        petRoot: null,
        petScene: null,
        petSlots: [],
        petState: 'idle',
        petThree: null,
        petWebglFailed: false,

        init() {
            if (typeof window === 'undefined' || typeof document === 'undefined') return;

            this.petRoot = this.$el.closest('[data-railtime-chatbot-root]') ?? this.$el;
            this.petSlots = Array.from(this.petRoot.querySelectorAll('[data-assistant-pet-3d-slot]'));
            this.petDocumentHidden = document.hidden;
            this.petReducedMotionQuery = window.matchMedia?.('(prefers-reduced-motion: reduce)') ?? null;
            this.petReducedMotion = Boolean(this.petReducedMotionQuery?.matches);

            this.petVisibilityHandler = () => {
                this.petDocumentHidden = document.hidden;
                this.petUpdateAnimationLoop();
            };
            this.petReducedMotionHandler = (event) => {
                this.petReducedMotion = Boolean(event.matches);
                this.petUpdateAnimationLoop();
            };
            this.petWindowResizeHandler = () => this.petResize();
            this.petPointerEnterHandler = () => {
                this.petHovered = true;
            };
            this.petPointerLeaveHandler = () => {
                this.petHovered = false;
            };
            this.petPointerDownHandler = () => {
                const now = performance.now();
                this.petReactionStartedAt = now;
                this.petReactionUntil = now + 560;
            };

            document.addEventListener('visibilitychange', this.petVisibilityHandler);
            this.petReducedMotionQuery?.addEventListener?.('change', this.petReducedMotionHandler);
            window.addEventListener('resize', this.petWindowResizeHandler, { passive: true });
            this.petSlots.forEach((slot) => {
                slot.addEventListener('pointerenter', this.petPointerEnterHandler);
                slot.addEventListener('pointerleave', this.petPointerLeaveHandler);
                slot.addEventListener('pointerdown', this.petPointerDownHandler);
            });

            this.petMutationObserver = new MutationObserver(() => this.petSyncFromMarkup());
            this.petMutationObserver.observe(this.$el, {
                attributes: true,
                attributeFilter: ['data-pet-open', 'data-state'],
            });

            if ('ResizeObserver' in window) {
                this.petResizeObserver = new ResizeObserver(() => this.petResize());
            }

            if ('IntersectionObserver' in window) {
                this.petIntersectionObserver = new IntersectionObserver((entries) => {
                    const entry = entries.find((candidate) => candidate.target === this.petActiveSlot);
                    if (!entry) return;

                    this.petInViewport = entry.isIntersecting && entry.intersectionRatio > 0;
                    if (this.petInViewport) this.petEnsureRenderer();
                    this.petUpdateAnimationLoop();
                }, { rootMargin: '96px', threshold: 0.01 });
            }

            this.$nextTick(() => this.petSyncFromMarkup());
        },

        destroy() {
            this.petDestroyed = true;
            this.petMutationObserver?.disconnect();
            this.petIntersectionObserver?.disconnect();
            this.petResizeObserver?.disconnect();
            document.removeEventListener('visibilitychange', this.petVisibilityHandler);
            this.petReducedMotionQuery?.removeEventListener?.('change', this.petReducedMotionHandler);
            window.removeEventListener('resize', this.petWindowResizeHandler);
            this.petSlots.forEach((slot) => {
                slot.removeEventListener('pointerenter', this.petPointerEnterHandler);
                slot.removeEventListener('pointerleave', this.petPointerLeaveHandler);
                slot.removeEventListener('pointerdown', this.petPointerDownHandler);
            });
            this.petDisposeRenderer();
            this.petSlots = [];
            this.petActiveSlot = null;
            this.petRoot = null;
        },

        petSyncFromMarkup() {
            if (this.petDestroyed) return;

            this.petState = normalizeAssistantPetState(this.$el.dataset.state);
            const targetName = this.$el.dataset.petOpen === 'true' ? 'header' : 'launcher';
            const target = this.petSlots.find((slot) => slot.dataset.assistantPet3dSlot === targetName)
                ?? this.petSlots[0]
                ?? null;

            this.petSlots.forEach((slot) => {
                slot.dataset.state = this.petState;
                slot.classList.toggle('is-webgl-failed', this.petWebglFailed);
            });

            if (target !== this.petActiveSlot) {
                this.petIntersectionObserver?.disconnect();
                this.petResizeObserver?.disconnect();
                this.petActiveSlot?.classList.remove('is-webgl-ready', 'is-webgl-loading');
                this.petActiveSlot = target;
                this.petInViewport = !this.petIntersectionObserver;

                if (this.petActiveSlot) {
                    if (this.petCanvas) this.petActiveSlot.appendChild(this.petCanvas);
                    this.petActiveSlot.classList.toggle('is-webgl-ready', Boolean(this.petRenderer));
                    this.petIntersectionObserver?.observe(this.petActiveSlot);
                    this.petResizeObserver?.observe(this.petActiveSlot);
                    this.petResize();
                }
            }

            if (this.petInViewport) this.petEnsureRenderer();
            this.petUpdateAnimationLoop();
        },

        async petEnsureRenderer() {
            if (this.petDestroyed || this.petLoading || this.petRenderer || this.petWebglFailed || !this.petActiveSlot) return;

            this.petLoading = true;
            this.petActiveSlot.classList.add('is-webgl-loading');

            try {
                const loadThree = options.loadThree ?? (() => import('three'));
                const THREE = await loadThree();
                if (this.petDestroyed || !this.petActiveSlot) return;

                this.petThree = THREE;
                this.petCreateRenderer();
                this.petActiveSlot.appendChild(this.petCanvas);
                this.petActiveSlot.classList.add('is-webgl-ready');
                this.petResize();
                this.petRender(performance.now());
                this.petUpdateAnimationLoop();
            } catch (error) {
                this.petWebglFailed = true;
                this.petSlots.forEach((slot) => {
                    slot.classList.add('is-webgl-failed');
                    slot.classList.remove('is-webgl-ready');
                });
                console.warn('RailTime Assist 3D pet could not start; SVG fallback stays active.', error);
                this.petDisposeRenderer(false);
            } finally {
                this.petLoading = false;
                this.petSlots.forEach((slot) => slot.classList.remove('is-webgl-loading'));
            }
        },

        petCreateRenderer() {
            const THREE = this.petThree;
            const canvas = document.createElement('canvas');
            canvas.className = 'rt-assistant-pet-3d__canvas';
            canvas.setAttribute('aria-hidden', 'true');
            canvas.setAttribute('role', 'presentation');

            const renderer = new THREE.WebGLRenderer({
                alpha: true,
                antialias: true,
                canvas,
                powerPreference: 'low-power',
                premultipliedAlpha: true,
            });
            renderer.setClearColor(0x000000, 0);
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_DEVICE_PIXEL_RATIO));
            renderer.outputColorSpace = THREE.SRGBColorSpace;

            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(28, 1, 0.1, 50);
            camera.position.set(0, 0.02, 5.25);
            camera.lookAt(0, -0.03, 0);

            scene.add(new THREE.HemisphereLight(0xfff1f3, 0x6b1025, 2.25));
            const keyLight = new THREE.DirectionalLight(0xffffff, 3.15);
            keyLight.position.set(-3.2, 4.4, 5.2);
            scene.add(keyLight);
            const rimLight = new THREE.PointLight(0xff3158, 18, 8, 2);
            rimLight.position.set(2.5, 1.2, 2.8);
            scene.add(rimLight);

            const model = createPetModel(THREE);
            scene.add(model.root);

            this.petCanvas = canvas;
            this.petRenderer = renderer;
            this.petScene = scene;
            this.petCamera = camera;
            this.petModel = model;
            this.petAnimationLoop = (frameAt) => {
                if (!shouldRenderAssistantPetFrame(this.petLastFrameAt, frameAt)) return;

                this.petLastFrameAt = frameAt;
                this.petRender(frameAt);
            };
            this.petContextLostHandler = (event) => {
                event.preventDefault();
                this.petWebglFailed = true;
                this.petSlots.forEach((slot) => {
                    slot.classList.add('is-webgl-failed');
                    slot.classList.remove('is-webgl-ready');
                });
                this.petDisposeRenderer(false);
            };
            canvas.addEventListener('webglcontextlost', this.petContextLostHandler, false);
        },

        petResize() {
            if (!this.petRenderer || !this.petCamera || !this.petActiveSlot) return;

            const bounds = this.petActiveSlot.getBoundingClientRect();
            const width = Math.max(1, Math.round(bounds.width));
            const height = Math.max(1, Math.round(bounds.height));
            this.petRenderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_DEVICE_PIXEL_RATIO));
            this.petRenderer.setSize(width, height, false);
            this.petCamera.aspect = width / height;
            this.petCamera.updateProjectionMatrix();

            if (!this.petLoopRunning) this.petRender(performance.now());
        },

        petUpdateAnimationLoop() {
            if (!this.petRenderer) return;

            const shouldAnimate = !this.petReducedMotion
                && !this.petDocumentHidden
                && this.petInViewport
                && this.petState !== 'offline';

            if (shouldAnimate && !this.petLoopRunning) {
                this.petLastFrameAt = 0;
                this.petRenderer.setAnimationLoop(this.petAnimationLoop);
                this.petLoopRunning = true;
            } else if (!shouldAnimate && this.petLoopRunning) {
                this.petRenderer.setAnimationLoop(null);
                this.petLoopRunning = false;
                this.petRender(performance.now());
            } else if (!shouldAnimate) {
                this.petRender(performance.now());
            }
        },

        petRender(frameAt) {
            if (!this.petRenderer || !this.petScene || !this.petCamera || !this.petModel) return;

            const state = normalizeAssistantPetState(this.petState);
            const time = frameAt / 1000;
            const motion = this.petReducedMotion ? 0 : 1;
            const model = this.petModel;
            const idleFloat = Math.sin(time * 1.8) * 0.055 * motion;
            const breathing = 1 + Math.sin(time * 2.15) * 0.018 * motion;
            const hoverLift = this.petHovered ? 0.07 : 0;
            const hoverScale = this.petHovered ? 1.045 : 1;
            const reactionDuration = Math.max(1, this.petReactionUntil - this.petReactionStartedAt);
            const reactionProgress = Math.min(1, Math.max(0, (frameAt - this.petReactionStartedAt) / reactionDuration));
            const reactionBounce = frameAt < this.petReactionUntil
                ? Math.sin(reactionProgress * Math.PI) * 0.18 * motion
                : 0;

            let stateLift = idleFloat;
            let stateTilt = 0;
            let stateTurn = 0;
            let stateScale = breathing;
            let eyeOpenness = 1;
            let mouthScale = 1;

            if (state === 'thinking') {
                stateLift += Math.sin(time * 2.8) * 0.045 * motion;
                stateTilt = Math.sin(time * 1.7) * 0.105 * motion;
                stateTurn = Math.sin(time * 1.15) * 0.11 * motion;
                stateScale += 0.012;
            } else if (state === 'listening') {
                stateLift *= 0.35;
                stateTilt = Math.sin(time * 3.2) * 0.025 * motion;
                eyeOpenness = 1.08;
            } else if (state === 'speaking') {
                stateLift += Math.abs(Math.sin(time * 6.4)) * 0.06 * motion;
                stateTilt = Math.sin(time * 4.4) * 0.035 * motion;
                mouthScale = 1.1 + Math.abs(Math.sin(time * 9.2)) * 1.15 * motion;
            } else if (state === 'offline') {
                stateLift = -0.055;
                stateTilt = -0.06;
                stateScale = 0.97;
                eyeOpenness = 0.68;
                mouthScale = 0.68;
            }

            model.root.position.y = stateLift + hoverLift + reactionBounce;
            model.root.rotation.z = stateTilt + (this.petHovered ? -0.035 : 0);
            model.root.rotation.y = stateTurn;
            model.root.scale.setScalar(stateScale * hoverScale);

            const blinkPhase = time % 4.65;
            const blinking = motion && blinkPhase > 4.24 && blinkPhase < 4.43;
            const blinkAmount = blinking
                ? Math.max(0.08, Math.abs(blinkPhase - 4.335) / 0.095)
                : 1;
            model.eyes.forEach((eye) => {
                eye.scale.y = eye.userData.baseScaleY * eyeOpenness * blinkAmount;
            });

            model.mouth.scale.y = model.mouth.userData.baseScaleY * mouthScale;
            model.tongue.visible = state === 'speaking';
            model.body.scale.x = 1.08 * (1 + (state === 'speaking' ? Math.sin(time * 6.4) * 0.015 * motion : 0));
            model.body.scale.y = 1.08 * (1 + (breathing - 1) * 0.8);
            model.glowMaterial.emissiveIntensity = state === 'thinking'
                ? 1.65 + Math.sin(time * 4.2) * 0.7 * motion
                : state === 'listening'
                    ? 1.35 + Math.sin(time * 5.1) * 0.35 * motion
                    : 1.05;
            model.gem.rotation.y = time * 0.72 * motion;

            model.earPivots.forEach((ear, index) => {
                const side = index === 0 ? -1 : 1;
                const baseRotation = side * -0.52;
                const listenWiggle = state === 'listening'
                    ? Math.sin(time * 5.3 + index * Math.PI) * 0.13 * motion
                    : Math.sin(time * 1.55 + index) * 0.025 * motion;
                ear.rotation.z = baseRotation + side * listenWiggle;
                ear.scale.y = state === 'listening' ? 1.08 : 1;
            });

            model.listeningRings.forEach((ring, index) => {
                const signalPhase = (time * 1.7 + index * 0.28) % 1;
                const visible = state === 'listening' && motion;
                const scale = 0.78 + signalPhase * 0.72;
                ring.material.opacity = visible ? (1 - signalPhase) * 0.62 : 0;
                ring.scale.set(0.65 * scale, scale, scale);
            });

            model.particles.forEach((particle, index) => {
                const visible = state === 'thinking' && motion;
                const angle = particle.userData.angle + time * (0.72 + index * 0.025);
                const radius = particle.userData.radius;
                particle.position.set(
                    Math.cos(angle) * radius,
                    Math.sin(angle * 1.18) * 0.78 + 0.12,
                    0.18 + Math.sin(angle * 0.7) * 0.18,
                );
                particle.material.opacity = visible ? 0.34 + Math.sin(time * 4 + index) * 0.2 : 0;
                particle.scale.setScalar(0.74 + Math.sin(time * 3.2 + index) * 0.22);
            });

            model.bodyMaterial.emissiveIntensity = state === 'offline' ? 0 : 0.08;
            this.petRenderer.render(this.petScene, this.petCamera);
        },

        petDisposeRenderer(forceContextLoss = true) {
            if (this.petRenderer) {
                this.petRenderer.setAnimationLoop(null);
                this.petScene?.traverse((object) => {
                    object.geometry?.dispose?.();
                    if (Array.isArray(object.material)) {
                        object.material.forEach((material) => material.dispose?.());
                    } else {
                        object.material?.dispose?.();
                    }
                });
                this.petRenderer.dispose();
                if (forceContextLoss) this.petRenderer.forceContextLoss?.();
            }

            this.petCanvas?.removeEventListener('webglcontextlost', this.petContextLostHandler, false);
            this.petCanvas?.remove();
            this.petSlots.forEach((slot) => slot.classList.remove('is-webgl-ready', 'is-webgl-loading'));
            this.petLoopRunning = false;
            this.petRenderer = null;
            this.petScene = null;
            this.petCamera = null;
            this.petModel = null;
            this.petCanvas = null;
            this.petThree = null;
        },
    };
}
