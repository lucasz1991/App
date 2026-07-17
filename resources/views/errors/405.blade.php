<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            405 - Methode nicht erlaubt
        </h2>
    </x-slot>

    <div class="pt-3 md:pt-12  antialiased">
        <div class="max-w-7xl mx-auto py-10 sm:px-6 lg:px-8">
            <div class="text-center">
                <h1 class="text-6xl font-bold text-yellow-500">405</h1>
                <p class="text-xl mt-4">Die angeforderte Methode ist nicht erlaubt.</p>
                <a href="{{ url('/') }}" class="mt-6 inline-block text-blue-500 hover:underline">Zurück zur Startseite</a>
            </div>
        </div>
    </div>
</x-app-layout>
