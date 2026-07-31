@section('title', __('app.help'))

<x-ui.page :title="__('app.help')">
    <div class="rt-help-app" data-help-center data-support-experience="help">
        <section class="rt-help-hero" aria-labelledby="help-hero-title" data-support-hero>
            <div class="rt-help-hero__ambient" aria-hidden="true">
                <span class="rt-help-hero__orb rt-help-hero__orb--one"></span>
                <span class="rt-help-hero__orb rt-help-hero__orb--two"></span>
                <svg viewBox="0 0 760 280" preserveAspectRatio="none">
                    <path data-support-route-path d="M-20 246C114 246 119 127 252 142C387 157 407 48 562 78C637 92 683 57 780 15" />
                    <circle data-support-route-node cx="252" cy="142" r="7" />
                    <circle data-support-route-node cx="562" cy="78" r="7" />
                </svg>
            </div>

            <div class="rt-help-hero__copy" data-support-hero-copy>
                <p class="rt-support-kicker rt-support-kicker--inverse">
                    <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                    {{ __('support.help_route_label') }}
                </p>
                <h2 id="help-hero-title">{{ __('support.help_route_title') }}</h2>
                <p class="rt-help-hero__lead">{{ __('support.help_route_description') }}</p>

                <label class="rt-help-search" data-support-search>
                    <span class="sr-only">{{ __('app.search_help') }}</span>
                    <i class="far fa-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="query"
                        inputmode="search"
                        enterkeyhint="search"
                        autocomplete="off"
                        placeholder="{{ __('app.search_help_placeholder') }}"
                    >
                    @if ($query !== '')
                        <button type="button" wire:click="$set('query', '')" aria-label="{{ __('app.reset_search') }}">
                            <i class="far fa-times" aria-hidden="true"></i>
                        </button>
                    @endif
                </label>

                <p class="rt-help-search__status" aria-live="polite" aria-atomic="true">
                    @if ($topics->isEmpty())
                        {{ __('support.help_result_none') }}
                    @elseif ($topics->count() === 1)
                        {{ __('support.help_result_one') }}
                    @else
                        {{ __('support.help_result_many', ['count' => $topics->count()]) }}
                    @endif
                </p>
            </div>

            <aside
                class="rt-help-install-control"
                x-data="railtimePwaInstall(@js([
                    'targets' => [
                        'ios' => '#help-install-ios',
                        'android' => '#help-install-android',
                        'desktop' => '#help-install-desktop',
                        'fallback' => '#help-install-guides',
                    ],
                    'messages' => [
                        'installed' => __('app.help_installed'),
                        'ready' => __('app.help_install_ready'),
                        'manual' => __('app.push_install_manually_description'),
                        'accepted' => __('app.push_install_accepted'),
                        'failed' => __('app.push_install_failed'),
                    ],
                ]))"
                data-help-install-control
                data-support-hero-card
            >
                <div>
                    <div class="rt-help-install-control__icon" aria-hidden="true">
                        <i class="far fa-mobile-alt"></i>
                        <span></span>
                    </div>
                    <p class="rt-help-install-control__label">{{ __('support.help_install_control_label') }}</p>
                    <h3>{{ __('app.help_install_title') }}</h3>
                    <p>{{ __('support.help_install_control_description') }}</p>
                </div>

                <div class="rt-help-install-control__action">
                    <p x-text="statusText()"></p>
                    <button
                        type="button"
                        x-on:click="installApp()"
                        x-bind:disabled="disabled"
                        data-help-install-action
                    >
                        <i class="far" x-bind:class="mode === 'installed' ? 'fa-check' : (busy ? 'fa-circle-notch fa-spin' : 'fa-arrow-to-bottom')" aria-hidden="true"></i>
                        <span>{{ __('app.push_install_app') }}</span>
                    </button>
                    <p class="rt-help-install-control__notice" x-show.important="notice" x-text="notice" aria-live="polite"></p>
                    <p class="rt-help-install-control__error" x-show.important="error" x-text="error" aria-live="assertive"></p>
                </div>
            </aside>
        </section>

        <nav class="rt-help-quicknav" aria-label="{{ __('support.help_route_label') }}" data-support-quicknav>
            <a href="#help-topics">
                <span class="rt-help-quicknav__number">01</span>
                <span>
                    <strong>{{ __('support.help_quick_topics') }}</strong>
                    <small>{{ __('support.help_quick_topics_description') }}</small>
                </span>
                <i class="far fa-arrow-down" aria-hidden="true"></i>
            </a>
            <a href="#help-install-guides">
                <span class="rt-help-quicknav__number">02</span>
                <span>
                    <strong>{{ __('support.help_quick_install') }}</strong>
                    <small>{{ __('support.help_quick_install_description') }}</small>
                </span>
                <i class="far fa-arrow-down" aria-hidden="true"></i>
            </a>
            <a href="#help-faq">
                <span class="rt-help-quicknav__number">03</span>
                <span>
                    <strong>{{ __('support.help_quick_faq') }}</strong>
                    <small>{{ __('support.help_quick_faq_description') }}</small>
                </span>
                <i class="far fa-arrow-down" aria-hidden="true"></i>
            </a>
        </nav>

        <div class="rt-help-workspace" id="help-topics">
            <section
                class="rt-help-topics"
                aria-labelledby="help-topics-heading"
                x-data="{
                    openTopic: null,
                    toggle(key) { this.openTopic = this.openTopic === key ? null : key },
                }"
                data-support-reveal
            >
                <header class="rt-section-heading">
                    <p class="rt-support-kicker">
                        <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                        {{ __('support.help_topics_label') }}
                    </p>
                    <h2 id="help-topics-heading">{{ __('support.help_topics_title') }}</h2>
                    <p>{{ __('support.help_topics_description') }}</p>
                </header>

                <div class="rt-help-topic-groups">
                    @forelse ($topicGroups as $group => $groupTopics)
                        @php $groupKey = \Illuminate\Support\Str::slug((string) $group); @endphp
                        <section class="rt-help-topic-group" wire:key="help-group-{{ $groupKey }}">
                            <header>
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <h3>{{ $group }}</h3>
                                <small>{{ $groupTopics->count() }}</small>
                            </header>

                            <div class="rt-help-topic-group__items">
                                @foreach ($groupTopics as $topic)
                                    @php
                                        $topicKey = \Illuminate\Support\Str::slug(($topic['route'] ?? 'help').'-'.$topic['title']);
                                    @endphp
                                    <article
                                        class="rt-help-topic"
                                        data-help-topic="{{ $topicKey }}"
                                        wire:key="help-topic-{{ $topicKey }}"
                                        :data-open="openTopic === @js($topicKey) ? 'true' : 'false'"
                                    >
                                        <button
                                            type="button"
                                            x-on:click="toggle(@js($topicKey))"
                                            :aria-expanded="(openTopic === @js($topicKey)).toString()"
                                            aria-controls="help-panel-{{ $topicKey }}"
                                        >
                                            <span class="rt-help-topic__index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                            <span class="rt-help-topic__copy">
                                                <strong>{{ $topic['title'] }}</strong>
                                                <small>{{ $topic['summary'] }}</small>
                                            </span>
                                            <span class="rt-help-topic__toggle" aria-hidden="true">
                                                <i class="far fa-plus"></i>
                                            </span>
                                        </button>

                                        <div
                                            id="help-panel-{{ $topicKey }}"
                                            x-show="openTopic === @js($topicKey)"
                                            x-collapse
                                            x-cloak
                                            class="rt-help-topic__panel"
                                        >
                                            <ul>
                                                @foreach ($topic['points'] as $point)
                                                    <li>
                                                        <i class="far fa-check-circle" aria-hidden="true"></i>
                                                        <span>{{ $point }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                            <a href="{{ $topic['href'] }}" wire:navigate>
                                                {{ __('support.help_open_area') }}
                                                <i class="far fa-arrow-right" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @empty
                        <div class="rt-help-empty" data-help-empty>
                            <span><i class="far fa-search" aria-hidden="true"></i></span>
                            <h3>{{ __('app.help_no_results') }}</h3>
                            <p>{{ __('app.search_help_placeholder') }}</p>
                            <button type="button" wire:click="$set('query', '')">{{ __('app.reset_search') }}</button>
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="rt-help-personal" data-support-reveal>
                <div class="rt-help-personal__rail" aria-hidden="true"><span></span></div>
                <p>{{ __('support.help_personal_label') }}</p>
                <h2>{{ __('support.help_personal_title') }}</h2>
                <p>{{ __('support.help_personal_description') }}</p>
                <a href="{{ route('support') }}" wire:navigate>
                    <i class="far fa-headset" aria-hidden="true"></i>
                    {{ __('support.help_personal_action') }}
                </a>
                <small><i class="far fa-shield-check" aria-hidden="true"></i>{{ __('support.help_personal_tip') }}</small>
            </aside>
        </div>

        <section class="rt-help-install" aria-labelledby="install-app-heading" data-support-reveal>
            <header class="rt-help-install__header">
                <div>
                    <p class="rt-support-kicker rt-support-kicker--inverse">
                        <span class="rt-support-kicker__mark" aria-hidden="true"></span>
                        {{ __('app.help_install_eyebrow') }}
                    </p>
                    <h2 id="install-app-heading">{{ __('app.help_install_title') }}</h2>
                    <p>{{ __('app.help_install_description') }}</p>
                </div>
                <span class="rt-help-install__device" aria-hidden="true">
                    <i class="far fa-mobile-android-alt"></i>
                    <i class="far fa-laptop"></i>
                </span>
            </header>

            <details class="rt-help-notifications">
                <summary>
                    <span class="rt-help-notifications__icon"><i class="far fa-bell" aria-hidden="true"></i></span>
                    <span>
                        <strong>{{ __('support.help_notifications_title') }}</strong>
                        <small>{{ __('support.help_notifications_description') }}</small>
                    </span>
                    <span class="rt-help-notifications__action">
                        {{ __('support.help_notifications_open') }}
                        <i class="far fa-chevron-down" aria-hidden="true"></i>
                    </span>
                </summary>
                <div class="rt-help-notifications__body">
                    <livewire:settings.push-settings :show-test="true" :show-install="false" />
                </div>
            </details>

            <div class="rt-help-install__label">{{ __('support.help_install_devices_label') }}</div>
            <div id="help-install-guides" class="rt-help-device-grid">
                <article id="help-install-ios" class="rt-help-device-card">
                    <header><span><i class="fab fa-apple" aria-hidden="true"></i></span><div><small>iOS · iPadOS</small><h3>{{ __('app.help_install_ios_title') }}</h3></div></header>
                    <ol>
                        @foreach ([__('app.help_install_ios_step_1'), __('app.help_install_ios_step_2'), __('app.help_install_ios_step_3'), __('app.help_install_ios_step_4')] as $step)
                            <li><span>{{ $loop->iteration }}</span><p>{{ $step }}</p></li>
                        @endforeach
                    </ol>
                </article>

                <article id="help-install-android" class="rt-help-device-card">
                    <header><span><i class="fab fa-android" aria-hidden="true"></i></span><div><small>Android · Chrome</small><h3>{{ __('app.help_install_android_title') }}</h3></div></header>
                    <ol>
                        @foreach ([__('app.help_install_android_step_1'), __('app.help_install_android_step_2'), __('app.help_install_android_step_3'), __('app.help_install_android_step_4')] as $step)
                            <li><span>{{ $loop->iteration }}</span><p>{{ $step }}</p></li>
                        @endforeach
                    </ol>
                </article>

                <article id="help-install-desktop" class="rt-help-device-card rt-help-device-card--desktop">
                    <header><span><i class="far fa-desktop" aria-hidden="true"></i></span><div><small>Windows · macOS</small><h3>{{ __('app.help_install_desktop_title') }}</h3></div></header>
                    <div class="rt-help-desktop-steps">
                        <div>
                            <h4><i class="fab fa-windows" aria-hidden="true"></i>{{ __('app.help_install_windows_subtitle') }}</h4>
                            <ol>
                                @foreach ([__('app.help_install_windows_step_1'), __('app.help_install_windows_step_2'), __('app.help_install_windows_step_3')] as $step)
                                    <li><span>{{ $loop->iteration }}</span><p>{{ $step }}</p></li>
                                @endforeach
                            </ol>
                        </div>
                        <div>
                            <h4><i class="fab fa-apple" aria-hidden="true"></i>{{ __('app.help_install_mac_subtitle') }}</h4>
                            <ol>
                                @foreach ([__('app.help_install_mac_step_1'), __('app.help_install_mac_step_2'), __('app.help_install_mac_step_3')] as $step)
                                    <li><span>{{ $loop->iteration }}</span><p>{{ $step }}</p></li>
                                @endforeach
                            </ol>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <section id="help-faq" class="rt-help-faq" aria-labelledby="help-faq-heading" data-support-reveal>
            <header class="rt-section-heading">
                <p class="rt-support-kicker"><span class="rt-support-kicker__mark" aria-hidden="true"></span>{{ __('support.help_faq_label') }}</p>
                <h2 id="help-faq-heading">{{ __('support.help_faq_title') }}</h2>
            </header>
            <div class="rt-help-faq__items">
                @foreach ($faqs as $faq)
                    <details>
                        <summary>
                            <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <strong>{{ $faq['question'] }}</strong>
                            <i class="far fa-plus" aria-hidden="true"></i>
                        </summary>
                        <p>{{ $faq['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>
    </div>
</x-ui.page>
