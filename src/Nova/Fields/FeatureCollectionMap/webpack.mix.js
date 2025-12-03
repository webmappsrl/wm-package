const mix = require('laravel-mix')

mix
  .setPublicPath('dist')
  .js('resources/js/field.js', 'js')
  .vue({ version: 3 })
  .sass('resources/sass/field.scss', 'css')
  .version()
  .webpackConfig({
    externals: {
      vue: 'Vue',
    },
    output: {
      uniqueName: 'wm/feature-collection-map',
    },
    resolve: {
      extensions: ['.js', '.jsx', '.vue', '.json'],
    },
  })


