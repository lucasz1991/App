<div  wire:loading.class="cursor-wait">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <h1 class="text-2xl font-semibold text-gray-800 dark:text-white mb-6">{{ __('app.messages') }}</h1>
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700">
                <thead class="bg-gray-100 dark:bg-slate-900/50">
                    <tr>
                        <th class="text-left px-6 py-3 border-b border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 font-medium">{{ __('app.subject') }}</th>
                        <th class="text-left px-6 py-3 border-b border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 font-medium">{{ __('app.message') }}</th>
                        <th class="text-left px-6 py-3 border-b border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 font-medium">{{ __('app.from') }}</th>
                        <th class="text-left px-6 py-3 border-b border-gray-200 dark:border-slate-700 text-gray-600 dark:text-slate-400 font-medium">{{ __('app.status') }}</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 dark:text-slate-300">
                    @foreach($messages as $message)
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer" wire:click="showMessage({{ $message->id }})"  wire:key="{{ $message->id }}">
                            <td class="border-b border-gray-200 dark:border-slate-700 px-6 py-4">{{ $message->subject }}</td>
                            <td class="border-b border-gray-200 dark:border-slate-700 px-6 py-4 truncate max-w-xs">{{ $message->message }}</td>
                            <td class="border-b border-gray-200 dark:border-slate-700 px-6 py-4">{{ $message->sender->name }}</td>
                            <td class="border-b border-gray-200 dark:border-slate-700 px-6 py-4">
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
            <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
        </div>

        <div x-show="showMessageModal" class="bg-white dark:bg-slate-800 rounded-lg overflow-hidden transform sm:w-full sm:mx-auto max-w-2xl ">
            <div class="border border-gray-300 dark:border-slate-700 rounded-lg p-4 relative">
                <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 dark:text-slate-500 dark:hover:text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <div>
                    <div class="flex">
                        <span class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">{{ __('app.from') }}: {{ $selectedMessage ? $selectedMessage->sender->name : '' }}</span>
                        <span class="inline-block ml-3 text-xs font-medium text-gray-700 dark:text-green-300 mb-2 bg-green-100 dark:bg-green-500/10 px-2 py-1 rounded-full">{{ $selectedMessage ? $selectedMessage->created_at->diffForHumans() : '' }}</span>
                    </div>

                </div>
                <h3 class="text-xl font-semibold mb-4 border-b dark:border-slate-700 dark:text-white pb-2">{{ $selectedMessage ? $selectedMessage->subject : '' }}</h3>
                <div class="mt-4">
                    <p class="text-gray-800 dark:text-slate-200">{{ $selectedMessage ? $selectedMessage->message : '' }}</p>
                </div>
            </div>

            <div class="flex justify-end mt-4 mb-2">
                <button type="button" class="bg-blue-300 text-white px-4 py-2 rounded-lg hover:bg-blue-400 mr-2">{{ __('app.reply') }}</button>
                <button type="button" @click="showMessageModal = false; $selectedMessage = null;" class="bg-green-300 hover:bg-green-400 text-white px-4 py-2 rounded-lg mr-2">{{ __('app.close') }}</button>
            </div>
        </div>
    </div>
    
</div>