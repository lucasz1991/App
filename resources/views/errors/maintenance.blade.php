{{-- maintenance.blade.php --}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Wartung – CBW Schulnetz</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#2563eb',
                        secondary: '#0ea5e9',
                        accent: '#22c55e',
                    }
                }
            }
        };
    </script>

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        
        [x-cloak] { display: none !important; }

        /* angenehm + sanft */
        @keyframes floatSlow {
            0%   { transform: translateY(0px); }
            50%  { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        @keyframes shimmerSoft {
            0%   { transform: translateX(-30%) rotate(8deg); opacity: .0; }
            20%  { opacity: .25; }
            50%  { opacity: .12; }
            100% { transform: translateX(130%) rotate(8deg); opacity: .0; }
        }

        .anim-float { animation: floatSlow 6.5s ease-in-out infinite; }
        .anim-shimmer { animation: shimmerSoft 7.5s ease-in-out infinite; }
        @media (prefers-reduced-motion: reduce) {
            .anim-float, .anim-shimmer { animation: none !important; }
        }
    </style>
</head>

<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="relative min-h-screen overflow-hidden">
        {{-- Background --}}
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute -left-20 -top-24 h-80 w-80 rounded-full bg-gradient-to-br from-blue-200 to-emerald-200 blur-3xl opacity-60 anim-float"></div>
            <div class="absolute right-0 top-10 h-96 w-96 rounded-full bg-gradient-to-bl from-blue-300 via-emerald-200 to-white blur-3xl opacity-50 anim-float" style="animation-delay: -1.6s;"></div>
            <div class="absolute inset-x-10 bottom-0 h-32 bg-gradient-to-r from-blue-50 via-white to-emerald-50 blur-xl opacity-80"></div>
        </div>

        <main class="relative min-h-screen flex items-center justify-center px-5 md:px-10 py-14">
            <div class="w-full max-w-2xl">
                <div class="rounded-3xl p-[1px] bg-gradient-to-br from-blue-500 via-emerald-400 to-blue-200 shadow-[0_18px_60px_-40px_rgba(15,23,42,0.35)]">
                    <div class="rounded-3xl bg-white border border-white/60 overflow-hidden relative">
                        {{-- Soft shimmer overlay --}}
                        <div class="pointer-events-none absolute -inset-10 opacity-20">
                            <div class="anim-shimmer absolute top-0 -left-1/3 h-full w-1/2 bg-gradient-to-r from-transparent via-white to-transparent blur-xl"></div>
                        </div>

                        <div class="p-6 md:p-10 relative">
                            {{-- Logo --}}
                            <div class="flex items-center justify-between">
                                <a href="/" class="inline-flex items-center gap-3">
                                    <div class="w-[160px] opacity-90">
                                        {{-- bevorzugt: eure bestehende Logo-Komponente --}}
                                        @if (View::exists('components.authentication-card-logo'))
                                            <x-authentication-card-logo />
                                        @else
                                            {{-- Fallback: Textlogo, falls Komponente nicht existiert --}}
                                            <div class="text-lg font-semibold tracking-tight text-slate-900">
                                                CBW<span class="text-blue-600"> Schulnetz</span>
                                            </div>
                                        @endif
                                    </div>
                                </a>

                                <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-100">
                                    Wartung
                                </span>
                            </div>

                            <div class="mt-8 flex items-start gap-4">
                                <div class="shrink-0 h-12 w-12 rounded-2xl bg-gradient-to-br from-blue-600 to-emerald-600 text-white flex items-center justify-center shadow-sm anim-float">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.274c.063.374.313.686.659.87.347.184.76.227 1.136.115l1.257-.377a1.125 1.125 0 011.366.806l.65 2.598a1.125 1.125 0 01-.505 1.26l-1.091.655c-.333.2-.533.564-.523.954l.036 1.437c.01.417.237.801.597 1.008l1.193.686c.47.27.707.809.57 1.324l-.65 2.598a1.125 1.125 0 01-1.366.806l-1.257-.377c-.376-.112-.79-.069-1.136.115-.346.184-.596.496-.659.87l-.213 1.274c-.09.542-.56.94-1.11.94h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.274c-.063-.374-.312-.686-.659-.87-.347-.184-.76-.227-1.136-.115l-1.256.377a1.125 1.125 0 01-1.367-.806l-.65-2.598a1.125 1.125 0 01.57-1.324l1.194-.686c.36-.207.587-.59.597-1.008l.036-1.437c.01-.39-.19-.754-.524-.954l-1.09-.655a1.125 1.125 0 01-.506-1.26l.65-2.598a1.125 1.125 0 011.366-.806l1.257.377c.376.112.79.069 1.136-.115.346-.184.596-.496.659-.87l.213-1.274z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-blue-700">Geplante Wartung</p>

                                    <h1 class="mt-2 text-3xl md:text-4xl font-semibold tracking-tight text-slate-900">
                                        Wir sind gleich wieder da
                                    </h1>

                                    <p class="mt-3 text-base text-slate-600 leading-relaxed">
                                        Das CBW Schulnetz wird gerade aktualisiert. Bitte versuchen Sie es in kurzer Zeit erneut.
                                    </p>

                                    @if(!empty($lastUpdated))
                                        <p class="mt-4 text-xs text-slate-500">
                                            Letzte Aktualisierung:
                                            <span class="font-semibold text-slate-700">
                                                {{ \Carbon\Carbon::parse($lastUpdated)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr
                                            </span>
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- Actions (minimal) --}}
                            <div class="mt-8 flex flex-col sm:flex-row gap-3">
                                <a
                                    href="{{ route('admin.login') }}"
                                    class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-blue-600 to-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:brightness-110 transition"
                                >
                                    Admin Login
                                    <svg xmlns="http://www.w3.org/2000/svg" class="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>

                                <a
                                    href="mailto:info@cbw-weiterbildung.de"
                                    class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm hover:shadow transition"
                                >
                                    Support kontaktieren
                                </a>
                            </div>

                            <div class="mt-10 text-xs text-slate-500 flex items-center justify-between">
                                <span>CBW Schulnetz</span>
                                <span>maintenance</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
