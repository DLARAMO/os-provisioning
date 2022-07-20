const mix = require('laravel-mix');
const path = require('path')

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

/* Allow multiple Laravel Mix applications */
require('laravel-mix-merge-manifest');
mix.mergeManifest();

/* Make alias for main resource/js directory */
mix.alias({
  '@': path.join(__dirname, 'resources/js')
});

mix.js('resources/js/app.js', 'public/js')
  .js('modules/CoreMon/Resources/assets/js/core-mon.js', 'public/js')
  .vue()
  .version()
  .postCss('resources/css/app.css', 'public/css', [
    require('tailwindcss'),
  ])
