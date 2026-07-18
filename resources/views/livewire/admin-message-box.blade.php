<div  wire:loading.class="cursor-wait">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <h1 class="text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text mb-6" data-anim="fade-up">{{ __('app.messages') }}</h1>
        <div class="overflow-x-auto rounded-xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up" data-anim-delay="0.05">
            <table class="min-w-full">
                <thead class="bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                    <tr>
                        <th class="text-left px-6 py-3 border-b border-rt-border/60 dark:border-rt-dark-border/60 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.subject') }}</th>
                        <th class="text-left px-6 py-3 border-b border-rt-border/60 dark:border-rt-dark-border/60 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.message') }}</th>
                        <th class="text-left px-6 py-3 border-b border-rt-border/60 dark:border-rt-dark-border/60 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.from') }}</th>
                        <th class="text-left px-6 py-3 border-b border-rt-border/60 dark:border-rt-dark-border/60 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.status') }}</th>
                    </tr>
                </thead>
                <tbody class="text-slate-700 dark:text-slate-300">
                    @foreach($messages as $message)
                        <tr class="cursor-pointer transition-colors duration-300 ease-rt-spring hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted/60" wire:click="showMessage({{ $message->id }})"  wire:key="{{ $message->id }}">
                            <td class="border-b border-rt-border/60 dark:border-rt-dark-border/60 px-6 py-4">{{ $message->subject }}</td>
                            <td class="border-b border-rt-border/60 dark:border-rt-dark-border/60 px-6 py-4 truncate max-w-xs">{{ $message->message }}</td>
                            <td class="border-b border-rt-border/60 dark:border-rt-dark-border/60 px-6 py-4">{{ $message->sender->name }}</td>
                            <td class="border-b border-rt-border/60 dark:border-rt-dark-border/60 px-6 py-4">
                                @if($message->status == 1)
                                    <span class="text-red-500">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M8.257 3.099c.765-1.36 2.718-1.36 3.483 0l6.514 11.59c.75 1.337-.213 3.011-1.742 3.011H3.486c-1.53 0-2.492-1.674-1.742-3.011l6.514-11.59z" />
                                        </svg>
                                        {{ __('app.unread') }}
                                    </span>
                                @elseif($message->status == 2)
                                    <span class="text-green-500">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-4.707-4.707a1 1 0 011.414-1.414L8.414 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                        {{ __('app.read') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>


    <!-- Modal zum ansehen der Nachricht-->
    <div 
        x-show="showMessageModal" x-cloak 
        x-data="{
            showMessageModal: @entangle('showMessageModal')
        }"
        x-init="() => { $watch('showMessageModal', value => { document.getElementById('main').classList.toggle('overflow-hidden', value); });}"
        class="fixed inset-0 p-6 flex items-center justify-center z-50 modal-container ">

        <div x-show="showMessageModal" class="fixed inset-0 transform" x-on:click="showMessageModal = false">
            <div class="absolute inset-0 bg-slate-900 opacity-60"></div>
        </div>

        <div x-show="showMessageModal" class="bg-rt-surface dark:bg-rt-dark-surface rounded-2xl shadow-rt-lg ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60 overflow-hidden transform sm:w-full sm:mx-auto max-w-2xl ">
            <div class="p-5 relative">
                <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <div>
                    <div class="flex">
                        <span class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">{{ __('app.from') }}: {{ $selectedMessage ? $selectedMessage->sender->name : '' }}</span>
                        <span class="inline-block ml-3 text-xs font-medium text-emerald-700 dark:text-emerald-300 mb-2 bg-emerald-50 dark:bg-emerald-500/10 px-2 py-1 rounded-full">{{ $selectedMessage ? $selectedMessage->created_at->diffForHumans() : '' }}</span>
                    </div>

                </div>
                <h3 class="text-xl font-semibold mb-4 border-b dark:border-slate-700 dark:text-white pb-2">{{ $selectedMessage ? $selectedMessage->subject : '' }}</h3>
                <div class="mt-4">
                    <p class="text-slate-800 dark:text-slate-200">{{ $selectedMessage ? $selectedMessage->message : '' }}</p>
                </div>
            </div>

            <div class="flex justify-end mt-4 mb-2">
                <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 mr-2">{{ __('app.reply') }}</button>
                <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-slate-50 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 mr-2">{{ __('app.close') }}</button>
            </div>
        </div>
    </div>
    
</div>