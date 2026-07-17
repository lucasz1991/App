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
