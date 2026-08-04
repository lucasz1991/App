{{--
    Video-Erfassung (Prototyp): Vollbild-Overlay UEBER dem Ausfuellformular.

    Kamera + Mikrofon einschalten, am Zug entlanggehen: Anschriften werden
    fortlaufend gelesen (TextDetector, wo verfuegbar) und fuellen den
    aktuellen Wagen automatisch — mit Ping und Ansage je Erkennung.
    Spracheingabe ergaenzt Werte, die die Kamera nicht sieht. Aufnahmen
    (Video/Foto) landen komprimiert im privaten Speicher am File-Modell.
--}}
<div
    x-data="wagonVideoCapture(@js([
        'uploadUrl' => $mediaUploadUrl,
        'unsupportedText' => __('app.wagon_video_unsupported'),
        'cameraBlockedText' => __('app.wagon_video_camera_blocked'),
        'uploadErrorText' => __('app.wagon_video_upload_error'),
        'savedLabel' => __('app.wagon_video_saved'),
        'recognizedText' => __('app.wagon_video_recognized'),
        'newWagonText' => __('app.wagon_video_new_wagon'),
        'wagonNumberLabel' => __('app.wagon_number'),
        'categoryLabel' => __('app.category'),
        'lengthLabel' => __('app.length_m'),
        'weightLabel' => __('app.wagon_weight_t'),
        'loadWeightLabel' => __('app.load_weight_t'),
        'speedLabel' => __('app.maximum_speed'),
        'speechLang' => app()->getLocale() === 'de' ? 'de-DE' : 'en-GB',
    ]))"
    x-on:railtime-wagon-video-open.window="openCapture()"
    x-on:keydown.escape.window="if (captureOpen) { $event.preventDefault(); $event.stopPropagation(); closeCapture(); }"
    data-wagon-video-capture
>
    <div
        x-show.important="captureOpen"
        x-cloak
        class="rt-wagon-capture fixed inset-0 z-[70] flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('app.wagon_video_capture') }}"
        data-no-sidebar-swipe
        x-transition:enter="rt-motion-screen-enter"
        x-transition:enter-start="rt-motion-screen-enter-from"
        x-transition:enter-end="rt-motion-screen-enter-to"
        x-transition:leave="rt-motion-screen-leave"
        x-transition:leave-start="rt-motion-screen-leave-from"
        x-transition:leave-end="rt-motion-screen-leave-to"
    >
        <video
            x-ref="captureVideo"
            class="rt-wagon-capture-video"
            autoplay
            playsinline
            muted
        ></video>

        <header class="rt-wagon-capture-top">
            <span class="rt-wagon-capture-chip">
                <i class="far fa-train" aria-hidden="true"></i>
                <span x-text="`${@js(__('app.wagons'))} ${mobileWagon + 1}/${visibleCount}`"></span>
            </span>

            <span
                class="rt-wagon-capture-chip rt-wagon-capture-chip--recording"
                x-show.important="recording"
                x-cloak
            >
                <span class="rt-wagon-capture-record-dot" aria-hidden="true"></span>
                <span class="tabular-nums" x-text="formatRecordTime()"></span>
            </span>

            <button
                type="button"
                class="rt-wagon-capture-icon"
                @click="closeCapture()"
                aria-label="{{ __('app.close') }}"
            >
                <i class="far fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <p class="rt-wagon-capture-error" x-show.important="cameraError" x-cloak x-text="cameraError"></p>

        <p
            class="rt-wagon-capture-fallback"
            x-show.important="cameraReady && !supportsTextDetection"
            x-cloak
        >{{ __('app.wagon_video_no_text_detection') }}</p>

        <ul class="rt-wagon-capture-log" aria-live="polite">
            <template x-for="entry in recognitionLog.slice(0, 5)" :key="entry.at">
                <li>
                    <i class="far" :class="entry.source === 'voice' ? 'fa-microphone' : 'fa-sparkles'" aria-hidden="true"></i>
                    <span x-text="entry.label"></span>
                </li>
            </template>
        </ul>

        <p class="rt-wagon-capture-transcript" x-show.important="voiceActive && voiceTranscript" x-cloak x-text="voiceTranscript"></p>

        <footer class="rt-wagon-capture-controls">
            <button
                type="button"
                class="rt-wagon-capture-icon"
                @click="previousMobileWagon()"
                :disabled="mobileWagon === 0"
                aria-label="{{ __('app.previous') }}"
            >
                <i class="far fa-arrow-left" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                class="rt-wagon-capture-icon"
                @click="capturePhoto()"
                :disabled="!cameraReady"
                aria-label="{{ __('app.wagon_video_photo') }}"
                title="{{ __('app.wagon_video_photo') }}"
            >
                <i class="far fa-camera" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                class="rt-wagon-capture-record"
                :data-recording="recording ? 'true' : 'false'"
                @click="toggleRecording()"
                :disabled="!cameraReady"
                :aria-label="recording ? @js(__('app.wagon_video_stop_recording')) : @js(__('app.wagon_video_start_recording'))"
            >
                <span aria-hidden="true"></span>
            </button>

            <button
                type="button"
                class="rt-wagon-capture-icon"
                :data-active="voiceActive ? 'true' : 'false'"
                @click="toggleVoice()"
                x-show.important="supportsVoice"
                aria-label="{{ __('app.wagon_video_voice') }}"
                title="{{ __('app.wagon_video_voice') }}"
            >
                <i class="far fa-microphone" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                class="rt-wagon-capture-icon"
                @click="nextMobileWagon()"
                :disabled="mobileWagon >= visibleCount - 1"
                aria-label="{{ __('app.next') }}"
            >
                <i class="far fa-arrow-right" aria-hidden="true"></i>
            </button>
        </footer>
    </div>
</div>
