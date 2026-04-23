const mix = require('laravel-mix')

mix
  .setPublicPath('dist')
  .js('resources/js/card.js', 'js')
  .vue({ version: 3 })
  .version()
  .webpackConfig({
    externals: { vue: 'Vue' },
    output: { uniqueName: 'wm/api-links-card' },
  })
