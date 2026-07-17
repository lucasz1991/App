@props(['status'])
<span title="{{ $status ? 'Aktiv' : 'Inaktiv' }}" class=" rounded-full flex items-center justify-center {{ $status ? 'bg-green-400' : 'bg-red-400' }}" >    
                        @php
                            $status = $status; // z. B. 'pending', 'rated', ...
                        @endphp

                    <div class="flex items-center" title="Status: {{ ucfirst($status) }}">
                        <div class=" w-max rounded-full flex items-center  space-x-2 justify-center px-2 py-1 pr-3 text-white
                            @switch($status)
                                @case('pending') bg-yellow-600 @break
                                @case('rating') bg-blue-600  @break
                                @case('rated') bg-green-600 @break
                                @case('approved') bg-green-600 @break
                                @case('rejected') bg-red-600 @break
                                @default bg-gray-600
                            @endswitch
                        ">
                            @switch($status)
                                @case('rated')
                                    <!-- Haken -->
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 min-w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <span>Bewertet</span>
                                    @break
                                @case('rating')
                                    <!-- Spinner / Ladeanimation -->
                                    <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="white" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                    </svg>
                                    <span>Analysiert...</span>
                                    @break
                                @case('approved')
                                    <!-- Haken -->
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 min-w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <span>Approved</span>
                                    @break

                                @case('rejected')
                                    <!-- X -->
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    <span>rejected</span>
                                    @break

                                @default
                                    <!-- Nur farbiger Punkt ohne Icon -->
                            @endswitch
                        </div>

                    </div>

                    </span>