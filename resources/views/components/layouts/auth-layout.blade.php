<div>
  <div class="lg:grid lg:min-h-[100vh] lg:grid-cols-12">
    {{-- Linke Seite mit Bild und Text --}}
    <section class="relative flex h-32 items-center justify-end lg:col-span-7 lg:h-full ">
      <img
        alt=""
        src="{{ asset('site-images/home-Slider_-_Studenten.jpg') }}"
        class="absolute inset-0 h-full w-full object-cover opacity-80"
      />

    <div class="absolute inset-0 bg-sky-50/60"></div>
    <ul class="bg-bubbles absolute top-0 left-0 w-full h-full overflow-hidden animate-square user-select-none">
        <li class="h-10 w-10 rounded-3xl bg-blue-100/60 absolute left-[10%] "></li>
        <li class="h-28 w-28 rounded-3xl bg-blue-100/60 absolute left-[20%]"></li>
        <li class="h-10 w-10 rounded-3xl bg-blue-100/60 absolute left-[25%]"></li>
        <li class="h-20 w-20 rounded-3xl bg-blue-100/60 absolute left-[40%]"></li>
        <li class="h-24 w-24 rounded-3xl bg-blue-100/60 absolute left-[70%]"></li>
        <li class="h-32 w-32 rounded-3xl bg-blue-100/60 absolute left-[70%]"></li>
        <li class="h-36 w-36 rounded-3xl bg-blue-100/60 absolute left-[32%]"></li>
        <li class="h-20 w-20 rounded-3xl bg-blue-100/60 absolute left-[55%]"></li>
        <li class="h-12 w-12 rounded-3xl bg-blue-100/60 absolute left-[25%]"></li>
        <li class="h-36 w-36 rounded-3xl bg-blue-100/60 absolute left-[90%]"></li>
    </ul>


      <div class="hidden lg:relative lg:block lg:p-12 md:max-w-3xl" >
        <a class="block text-white" href="/">
          <span class="sr-only">Home</span>
          <div class="w-[150px]  opacity-60">
            <x-authentication-card-logo  />
          </div>
        </a>
        <h2 class="mt-6 text-2xl font-bold  sm:text-3xl md:text-4xl text-gray-700">
          {{ $title }}
        </h2>
        <p class="mt-4 text-xl font-bold leading-relaxed text-gray-700">
            {{ $description }}
        </p>
      </div>
    </section>
    {{-- Linke Seite mit Bild und Text --}}
    {{-- Rechte Seite mit Formular --}}
    <main class="flex items-center justify-center px-8 py-8 sm:px-12 lg:col-span-5 lg:px-16 lg:py-12 ">
      <div class="max-w-xl lg:max-w-3xl">
        <div class="relative -mt-16 block lg:hidden">
            <a class="inline-flex p-2 items-center justify-center rounded-full bg-white text-blue-600 sm:size-20" href="/">
                <span class="sr-only">Home</span>
                <div class="w-20 ">
                  <x-authentication-card-logo  />
                </div>
            </a>
            <h1 class="mt-2 text-2xl font-bold text-gray-700 sm:text-3xl md:text-4xl">
              {{ $title }}
            </h1>
            <p class="mt-4 text-xl font-bold leading-relaxed text-gray-700">
            {{ $description }}                
            </p>
        </div>
        {{ $form }}
      </div>
    </main>
    {{-- Rechte Seite mit Formular --}}
  </div>
</div>