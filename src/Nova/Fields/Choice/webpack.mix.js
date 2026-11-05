let mix = require('laravel-mix')
let NovaExtension = require('laravel-nova-devtool')

mix.extend('nova', new NovaExtension())

// externals: senza, il bundle include una seconda copia di Vue e il campo non renderizza —
// nessun errore, il tab appare vuoto (regola del repo, oc:7546).
mix.webpackConfig({
  externals: {
    vue: 'Vue',
  },
})

mix
  .setPublicPath('dist')
  .js('resources/js/field.js', 'js')
  .vue({ version: 3 })
  .css('resources/css/field.css', 'css')
  .nova('webmapp/choice')
  .version()
