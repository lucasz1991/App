import defaultTheme from 'tailwindcss/defaultTheme';
import fs from 'fs';
import path from 'path';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        ...getAllCacheFiles('./storage/framework/cache/data/'),
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
            colors: {
                // RailTime CI (siehe Website Layout 3 / rt-brand)
                'rt-red': {
                    DEFAULT: '#e4002b',
                    dark: '#c90026',
                    light: '#f51b3b',
                },
                'rt-anthracite': '#080b10',
                'rt-ink': '#151b24',
                // Semantische Oberflaechen- und Textfarben. Diese Klassen
                // werden in Layouts verwendet, damit ein Redesign zentral
                // an dieser Stelle erfolgen kann.
                rt: {
                    canvas: '#f2f5f9',
                    surface: '#ffffff',
                    'surface-muted': '#f7f9fc',
                    topbar: '#f6f8fb',
                    sidebar: '#edf2f7',
                    control: '#ffffff',
                    border: '#d7e0ea',
                    text: '#182435',
                    muted: '#637188',
                    soft: '#91a0b4',
                    accent: '#e4002b',
                    'accent-soft': '#ffe5ea',
                    'nav-hover': '#e7edf5',
                },
                'rt-dark': {
                    canvas: '#080d16',
                    surface: '#111a27',
                    'surface-muted': '#182435',
                    topbar: '#0c1421',
                    sidebar: '#0d1624',
                    control: '#223249',
                    border: '#2a394d',
                    text: '#edf2f8',
                    muted: '#aeb9c9',
                    soft: '#77869a',
                    accent: '#ff7189',
                    'accent-soft': '#481925',
                    'nav-hover': '#1a293d',
                    'nav-active': '#293f5c',
                },
            },
        },
    },

    plugins: [],
};

function getAllCacheFiles(dir, fileList = []) {
    try {
        const files = fs.readdirSync(dir);
        files.forEach((file) => {
            const filePath = path.join(dir, file);
            if (fs.statSync(filePath).isDirectory()) {
                getAllCacheFiles(filePath, fileList);
            } else {
                fileList.push(filePath);
            }
        });
    } catch (err) {
        // Ignore when cache directory is missing (e.g. fresh install).
    }

    return fileList;
}
