<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Sitzung abgelaufen
        </h2>
    </x-slot>

    <div class="pt-3 md:pt-12  antialiased">
        <div class="max-w-3xl mx-auto py-10 sm:px-6 lg:px-8">
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 rounded-md shadow">
                <h2 class="text-2xl font-semibold mb-2">Deine Sitzung ist abgelaufen!</h2>
                <p class="text-lg">
                    Aus Sicherheitsgründen wurdest du automatisch abgemeldet, da deine Sitzung abgelaufen ist. 
                    Bitte melde dich erneut an, um fortzufahren.
                </p>
                <div class="mt-4">
                    <a href="{{ url('/login') }}" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-900 border border-gray-300 rounded-lg bg-white hover:bg-gray-200 focus:ring-4 focus:ring-gray-100">
                        Erneut anmelden
                    </a>
                    <a href="{{ url('/') }}" class="inline-flex items-center justify-center px-5 py-3 text-base font-medium text-center text-gray-900 border border-gray-300 rounded-lg bg-white hover:bg-gray-200 focus:ring-4 focus:ring-gray-100">
                        Zurück zur Startseite
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>