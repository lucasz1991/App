const PET_STATES = new Set(['idle', 'thinking', 'listening', 'speaking', 'offline']);
const MAX_DEVICE_PIXEL_RATIO = 1.75;
const MAX_FRAMES_PER_SECOND = 30;

const PET_MOTION_PROFILES = Object.freeze({
    idle: Object.freeze({
        baseY: 0,
        baseScale: 1,
        floatAmplitude: 0.05,
        floatSpeed: 1.65,
        breatheAmplitude: 0.014,
        breatheSpeed: 2.05,
        tiltAmplitude: 0.009,
        tiltSpeed: 0.72,
        yawAmplitude: 0.012,
        yawSpeed: 0.58,
        leafSpread: 1,
        leafAmplitude: 0.022,
        leafSpeed: 1.1,
        eyeScale: 1,
        mouthScale: 1,
        mouthPulse: 0,
        mouthSpeed: 1,
        emissive: 0.08,
        emissivePulse: 0,
        emissiveSpeed: 1,
    }),
    thinking: Object.freeze({
        baseY: 0.01,
        baseScale: 1.005,
        floatAmplitude: 0.035,
        floatSpeed: 2.2,
        breatheAmplitude: 0.012,
        breatheSpeed: 2.8,
        tiltAmplitude: 0.04,
        tiltSpeed: 1.55,
        yawAmplitude: 0.032,
        yawSpeed: 1.25,
        leafSpread: 0.92,
        leafAmplitude: 0.06,
        leafSpeed: 2.45,
        eyeScale: 0.94,
        mouthScale: 0.82,
        mouthPulse: 0,
        mouthSpeed: 1,
        emissive: 0.1,
        emissivePulse: 0.045,
        emissiveSpeed: 2.5,
    }),
    listening: Object.freeze({
        baseY: 0.025,
        baseScale: 1.025,
        floatAmplitude: 0.024,
        floatSpeed: 1.3,
        breatheAmplitude: 0.012,
        breatheSpeed: 1.8,
        tiltAmplitude: 0.012,
        tiltSpeed: 0.9,
        yawAmplitude: 0.018,
        yawSpeed: 0.72,
        leafSpread: 0.68,
        leafAmplitude: 0.035,
        leafSpeed: 2.1,
        eyeScale: 1.12,
        mouthScale: 0.72,
        mouthPulse: 0,
        mouthSpeed: 1,
        emissive: 0.13,
        emissivePulse: 0.025,
        emissiveSpeed: 2,
    }),
    speaking: Object.freeze({
        baseY: 0.005,
        baseScale: 1.008,
        floatAmplitude: 0.042,
        floatSpeed: 3.5,
        breatheAmplitude: 0.016,
        breatheSpeed: 4.4,
        tiltAmplitude: 0.018,
        tiltSpeed: 2.8,
        yawAmplitude: 0.016,
        yawSpeed: 2.2,
        leafSpread: 0.9,
        leafAmplitude: 0.04,
        leafSpeed: 3.2,
        eyeScale: 1.02,
        mouthScale: 0.9,
        mouthPulse: 1.2,
        mouthSpeed: 7.4,
        emissive: 0.1,
        emissivePulse: 0.04,
        emissiveSpeed: 7.4,
    }),
    offline: Object.freeze({
        baseY: -0.035,
        baseScale: 0.985,
        floatAmplitude: 0,
        floatSpeed: 1,
        breatheAmplitude: 0,
        breatheSpeed: 1,
        tiltAmplitude: 0,
        tiltSpeed: 1,
        yawAmplitude: 0,
        yawSpeed: 1,
        leafSpread: 1.08,
        leafAmplitude: 0,
        leafSpeed: 1,
        eyeScale: 0.82,
        mouthScale: 0.72,
        mouthPulse: 0,
        mouthSpeed: 1,
        emissive: 0,
        emissivePulse: 0,
        emissiveSpeed: 1,
    }),
});

export function normalizeAssistantPetState(state) {
    const normalized = String(state ?? '').trim().toLowerCase();

    return PET_STATES.has(normalized) ? normalized : 'idle';
}

export function assistantPetMotionProfile(state) {
    return PET_MOTION_PROFILES[normalizeAssistantPetState(state)];
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
    root.name = 'railtime-assistant-red-baby-creature';

    const bodyMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xef3b53,
        roughness: 0.48,
        metalness: 0,
        clearcoat: 0.24,
        clearcoatRoughness: 0.68,
        sheen: 0.48,
        sheenColor: 0xffa0ad,
        emissive: 0x3d020e,
        emissiveIntensity: 0.06,
    });
    const accentMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xa81331,
        roughness: 0.62,
        metalness: 0,
        clearcoat: 0.12,
        clearcoatRoughness: 0.8,
    });
    const faceMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xffc0c8,
        roughness: 0.58,
        metalness: 0,
        clearcoat: 0.14,
        clearcoatRoughness: 0.72,
    });
    const leafMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xff687b,
        roughness: 0.62,
        metalness: 0,
        clearcoat: 0.1,
    });
    const inkMaterial = new THREE.MeshPhysicalMaterial({
        color: 0x241218,
        roughness: 0.34,
        metalness: 0,
        clearcoat: 0.62,
        clearcoatRoughness: 0.24,
    });
    const eyeShineMaterial = new THREE.MeshBasicMaterial({ color: 0xffffff });
    const cheekMaterial = new THREE.MeshPhysicalMaterial({
        color: 0xf37386,
        roughness: 0.68,
        metalness: 0,
        transparent: true,
        opacity: 0.72,
    });

    const body = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 48, 32), bodyMaterial),
        { position: [0, -0.04, 0], rotation: [-0.025, 0.035, -0.012], scale: [1.12, 0.98, 0.72] },
    );
    root.add(body);

    const face = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 40, 28), faceMaterial),
        { position: [0, 0.01, 0.63], scale: [0.73, 0.57, 0.105] },
    );
    root.add(face);

    const eyes = [-1, 1].map((side) => {
        const eye = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 24, 18), inkMaterial),
            { position: [side * 0.3, 0.16, 0.745], scale: [0.1, 0.14, 0.045] },
        );
        eye.userData.baseScaleY = 0.145;

        const shine = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 16, 12), eyeShineMaterial),
            { position: [side * 0.278, 0.202, 0.785], scale: [0.025, 0.034, 0.012] },
        );
        root.add(eye, shine);

        return eye;
    });

    const mouth = applyTransform(
        new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), inkMaterial),
        { position: [0, -0.2, 0.765], scale: [0.1, 0.035, 0.02] },
    );
    mouth.userData.baseScaleX = 0.1;
    mouth.userData.baseScaleY = 0.035;
    root.add(mouth);

    [-1, 1].forEach((side) => {
        const cheek = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 20, 14), cheekMaterial),
            { position: [side * 0.48, -0.08, 0.724], scale: [0.105, 0.065, 0.018] },
        );
        root.add(cheek);
    });

    const leafPivots = [-1, 1].map((side) => {
        const pivot = new THREE.Group();
        pivot.position.set(side * 0.57, 0.74, -0.08);
        pivot.rotation.z = side * -0.62;

        const leaf = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 28, 18), leafMaterial),
            {
                position: [side * 0.03, 0.22, 0],
                rotation: [0.14, side * -0.16, 0],
                scale: [0.22, 0.38, 0.14],
            },
        );
        const vein = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 18, 12), accentMaterial),
            { position: [side * 0.035, 0.22, 0.125], scale: [0.022, 0.27, 0.012] },
        );
        pivot.add(leaf, vein);
        root.add(pivot);

        return pivot;
    });

    const feet = [-1, 1].map((side) => {
        const foot = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), accentMaterial),
            {
                position: [side * 0.59, -0.9, -0.01],
                rotation: [0.08, side * -0.2, side * -0.24],
                scale: [0.31, 0.13, 0.23],
            },
        );
        root.add(foot);

        return foot;
    });

    [-1, 1].forEach((side) => {
        const arm = applyTransform(
            new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), accentMaterial),
            {
                position: [side * 1.02, -0.05, -0.03],
                rotation: [0.12, side * -0.2, side * -0.48],
                scale: [0.24, 0.1, 0.17],
            },
        );
        root.add(arm);
    });

    root.rotation.x = -0.015;

    return {
        root,
        body,
        bodyMaterial,
        face,
        leafPivots,
        eyes,
        feet,
        mouth,
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
        petInViewport: false,
        petIntersectionObserver: null,
        petLastFrameAt: 0,
        petLoading: false,
        petLoopRunning: false,
        petModel: null,
        petMutationObserver: null,
        petReducedMotion: false,
        petReducedMotionQuery: null,
        petRenderer: null,
        petResizeObserver: null,
        petRoot: null,
        petScene: null,
        petSlots: [],
        petVisualState: 'idle',
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

            document.addEventListener('visibilitychange', this.petVisibilityHandler);
            this.petReducedMotionQuery?.addEventListener?.('change', this.petReducedMotionHandler);
            window.addEventListener('resize', this.petWindowResizeHandler, { passive: true });

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
            this.petDisposeRenderer();
            this.petSlots = [];
            this.petActiveSlot = null;
            this.petRoot = null;
        },

        petSyncFromMarkup() {
            if (this.petDestroyed) return;

            this.petVisualState = normalizeAssistantPetState(this.$el.dataset.state);
            const targetName = this.$el.dataset.petOpen === 'true' ? 'header' : 'launcher';
            const target = this.petSlots.find((slot) => slot.dataset.assistantPet3dSlot === targetName)
                ?? this.petSlots[0]
                ?? null;

            this.petSlots.forEach((slot) => {
                slot.dataset.state = this.petVisualState;
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
            const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 50);
            camera.position.set(0, 0.02, 5.55);
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
                && this.petVisualState !== 'offline';

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

            const state = normalizeAssistantPetState(this.petVisualState);
            const profile = assistantPetMotionProfile(state);
            const time = frameAt / 1000;
            const motion = this.petReducedMotion || state === 'offline' ? 0 : 1;
            const model = this.petModel;
            const floating = Math.sin(time * profile.floatSpeed) * profile.floatAmplitude * motion;
            const breathing = profile.baseScale
                + Math.sin(time * profile.breatheSpeed) * profile.breatheAmplitude * motion;

            model.root.position.y = profile.baseY + floating;
            model.root.rotation.z = Math.sin(time * profile.tiltSpeed) * profile.tiltAmplitude * motion;
            model.root.rotation.y = Math.sin(time * profile.yawSpeed) * profile.yawAmplitude * motion;
            model.root.scale.setScalar(breathing);

            const blinkPhase = time % 4.65;
            const blinking = motion && blinkPhase > 4.24 && blinkPhase < 4.43;
            const blinkAmount = blinking
                ? Math.max(0.08, Math.abs(blinkPhase - 4.335) / 0.095)
                : 1;
            model.eyes.forEach((eye) => {
                eye.scale.y = eye.userData.baseScaleY * profile.eyeScale * blinkAmount;
            });

            model.leafPivots.forEach((leaf, index) => {
                const side = index === 0 ? -1 : 1;
                const baseRotation = side * -0.62 * profile.leafSpread;
                const stateWiggle = Math.sin(time * profile.leafSpeed + index * 0.7)
                    * profile.leafAmplitude
                    * motion;
                leaf.rotation.z = baseRotation + side * stateWiggle;
            });

            const mouthPulse = Math.abs(Math.sin(time * profile.mouthSpeed))
                * profile.mouthPulse
                * motion;
            model.mouth.scale.x = model.mouth.userData.baseScaleX * (1 - mouthPulse * 0.08);
            model.mouth.scale.y = model.mouth.userData.baseScaleY * (profile.mouthScale + mouthPulse);
            model.bodyMaterial.emissiveIntensity = profile.emissive
                + Math.abs(Math.sin(time * profile.emissiveSpeed)) * profile.emissivePulse * motion;
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
