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
                sans: ['Quicksand', ...defaultTheme.fontFamily.sans],
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
                    canvas: '#f3f6fa',
                    surface: '#ffffff',
                    'surface-muted': '#f8fafc',
                    topbar: '#ffffff',
                    sidebar: '#eef3f8',
                    border: '#dbe3ee',
                    text: '#172033',
                    muted: '#64748b',
                    soft: '#94a3b8',
                    accent: '#e4002b',
                    'accent-soft': '#ffe4e9',
                },
                'rt-dark': {
                    canvas: '#0b1120',
                    surface: '#111827',
                    'surface-muted': '#172033',
                    topbar: '#0f172a',
                    sidebar: '#101827',
                    border: '#273449',
                    text: '#e7edf7',
                    muted: '#a9b6c9',
                    soft: '#75839a',
                    accent: '#ff8295',
                    'accent-soft': '#4a1723',
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
