<fieldset class="space-y-2">
    <legend class="text-sm font-semibold">{{ __('app.calls_camera') }}</legend>
    <div class="relative flex aspect-video max-h-48 items-center justify-center overflow-hidden rounded-xl bg-black">
        <i x-show="!cameraPreviewReady || cameraMode === 'off'" class="far fa-video-slash text-slate-400" aria-hidden="true"></i>
        <video x-ref="cameraPreviewVideo" x-show="cameraPreviewReady && cameraMode !== 'off'" class="absolute inset-0 h-full w-full object-contain" playsinline muted autoplay></video>
    </div>
    <label class="block text-sm">
        <span>{{ __('app.calls_camera_view') }}</span>
        <select x-model="cameraMode" x-on:change="refreshCameraPreview()" class="mt-1 block min-h-10 w-full rounded-lg border border-white/25 bg-slate-900 px-3 text-white">
            <option value="off">{{ __('app.calls_without_camera') }}</option>
            <option value="original">{{ __('app.calls_camera_original') }}</option>
            <template x-if="cameraNativeBlurAvailable || cameraMode === 'blur'">
                <option value="blur">{{ __('app.calls_camera_blur') }}</option>
            </template>
        </select>
    </label>
    <label x-show="cameraMode !== 'off' && cameraDevices.length > 1" class="block text-sm">
        <span>{{ __('app.calls_camera_device') }}</span>
        <select x-model="cameraDeviceId" x-on:change="refreshCameraPreview()" class="mt-1 block min-h-10 w-full rounded-lg border border-white/25 bg-slate-900 px-3 text-white">
            <option value="">{{ __('app.calls_camera_default') }}</option>
            <template x-for="(device, index) in cameraDevices" :key="device.deviceId">
                <option :value="device.deviceId" x-text="device.label || @js(__('app.calls_camera')) + ' ' + (index + 1)"></option>
            </template>
        </select>
    </label>
    <p class="text-xs text-slate-300">{{ __('app.calls_camera_preview_paused') }}</p>
    <p x-show="cameraPreviewBusy" role="status" class="text-xs text-slate-300">{{ __('app.calls_camera_preparing') }}</p>
    <div x-show="cameraPreviewMessage" class="space-y-1">
        <p role="status" class="text-sm text-amber-200" x-text="cameraPreviewMessage"></p>
        <button type="button" x-on:click="refreshCameraPreview()" class="min-h-10 text-sm font-semibold underline">{{ __('app.calls_camera_retry') }}</button>
    </div>
</fieldset>
