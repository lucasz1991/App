import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './resources/views/**/*.blade.php',
        './resources/views/**/**/*.blade.php',
        './resources/views/**/**/**/*.blade.php',
        './resources/views/**/**/**/**/*.blade.php',
        './resources/views/**/**/**/**/**/*.blade.php',
        './app/Livewire/**/*.php',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans Variable"', ...defaultTheme.fontFamily.sans],
            },
            // Weiche, getoente Schatten (statt hartem shadow-md/schwarz)
            boxShadow: {
                'rt-xs': '0 1px 2px rgba(15, 23, 42, 0.05)',
                'rt-sm': '0 1px 2px rgba(15, 23, 42, 0.05), 0 8px 24px -12px rgba(15, 23, 42, 0.12)',
                'rt-md': '0 2px 4px rgba(15, 23, 42, 0.05), 0 16px 40px -16px rgba(15, 23, 42, 0.18)',
                'rt-lg': '0 4px 8px rgba(15, 23, 42, 0.06), 0 24px 64px -24px rgba(15, 23, 42, 0.28)',
                'rt-glow': '0 0 0 1px rgba(228, 0, 43, 0.08), 0 8px 32px -12px rgba(228, 0, 43, 0.35)',
            },
            // Federnde Motion-Kurve fuer alle Interaktionen
            transitionTimingFunction: {
                'rt-spring': 'cubic-bezier(0.32, 0.72, 0, 1)',
            },
            // Alle Werte kommen aus resources/css/tokens.css (Kanal-Tripel),
            // damit Opazitaetsmodifikatoren wie bg-rt-accent/10 funktionieren.
            colors: {
                // RailTime CI (siehe Website Layout 3 / rt-brand)
                'rt-red': {
                    DEFAULT: 'rgb(var(--rt-red-rgb) / <alpha-value>)',
                    dark: 'rgb(var(--rt-red-dark-rgb) / <alpha-value>)',
                    light: 'rgb(var(--rt-red-light-rgb) / <alpha-value>)',
                },
                'rt-anthracite': 'rgb(var(--rt-anthracite-rgb) / <alpha-value>)',
                'rt-ink': 'rgb(var(--rt-ink-rgb) / <alpha-value>)',
                // Semantische Oberflaechen- und Textfarben. Diese Klassen
                // werden in Layouts verwendet, damit ein Redesign zentral
                // in tokens.css erfolgen kann.
                rt: {
                    canvas: 'rgb(var(--rt-canvas-rgb) / <alpha-value>)',
                    surface: 'rgb(var(--rt-surface-rgb) / <alpha-value>)',
                    'surface-muted': 'rgb(var(--rt-surface-muted-rgb) / <alpha-value>)',
                    topbar: 'rgb(var(--rt-topbar-rgb) / <alpha-value>)',
                    sidebar: 'rgb(var(--rt-sidebar-rgb) / <alpha-value>)',
                    control: 'rgb(var(--rt-control-rgb) / <alpha-value>)',
                    border: 'rgb(var(--rt-border-rgb) / <alpha-value>)',
                    text: 'rgb(var(--rt-text-rgb) / <alpha-value>)',
                    muted: 'rgb(var(--rt-muted-rgb) / <alpha-value>)',
                    soft: 'rgb(var(--rt-soft-rgb) / <alpha-value>)',
                    accent: 'rgb(var(--rt-accent-rgb) / <alpha-value>)',
                    'accent-soft': 'rgb(var(--rt-accent-soft-rgb) / <alpha-value>)',
                    'nav-hover': 'rgb(var(--rt-nav-hover-rgb) / <alpha-value>)',
                },
                'rt-dark': {
                    canvas: 'rgb(var(--rt-dark-canvas-rgb) / <alpha-value>)',
                    surface: 'rgb(var(--rt-dark-surface-rgb) / <alpha-value>)',
                    'surface-muted': 'rgb(var(--rt-dark-surface-muted-rgb) / <alpha-value>)',
                    topbar: 'rgb(var(--rt-dark-topbar-rgb) / <alpha-value>)',
                    sidebar: 'rgb(var(--rt-dark-sidebar-rgb) / <alpha-value>)',
                    control: 'rgb(var(--rt-dark-control-rgb) / <alpha-value>)',
                    border: 'rgb(var(--rt-dark-border-rgb) / <alpha-value>)',
                    text: 'rgb(var(--rt-dark-text-rgb) / <alpha-value>)',
                    muted: 'rgb(var(--rt-dark-muted-rgb) / <alpha-value>)',
                    soft: 'rgb(var(--rt-dark-soft-rgb) / <alpha-value>)',
                    accent: 'rgb(var(--rt-dark-accent-rgb) / <alpha-value>)',
                    'accent-soft': 'rgb(var(--rt-dark-accent-soft-rgb) / <alpha-value>)',
                    'nav-hover': 'rgb(var(--rt-dark-nav-hover-rgb) / <alpha-value>)',
                    'nav-active': 'rgb(var(--rt-dark-nav-active-rgb) / <alpha-value>)',
                },
            },
        },
    },

    plugins: [],
};
