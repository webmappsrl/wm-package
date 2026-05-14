# Changelog

## [1.6.0](https://github.com/webmappsrl/wm-package/compare/v1.5.0...v1.6.0) (2026-05-14)


### Features

* **command:** ✨ add config option to wm:backup-run command ([45df1ba](https://github.com/webmappsrl/wm-package/commit/45df1ba980ef9b1764afcbdd8c4692765c5f9fd1))
* **config:** ✨ add 'from' and 'to' fields to wm-elasticsearch configuration ([#214](https://github.com/webmappsrl/wm-package/issues/214)) ([fd77c39](https://github.com/webmappsrl/wm-package/commit/fd77c3956f296bd44d855d8d2913bb242db0b896))
* **config:** ✨ add supervisor-dem configuration to wm-horizon ([d14655a](https://github.com/webmappsrl/wm-package/commit/d14655a55ca0aba26aa7652e30881899edcf070b))
* **config:** ✨ update AWS configuration with environment variables ([77a4cde](https://github.com/webmappsrl/wm-package/commit/77a4cde30e958e51d1bdbac1e5c8e9dc45593b85))
* **localization:** 🌐 add new tile layer selection description OC:7806 ([#211](https://github.com/webmappsrl/wm-package/issues/211)) ([92b6464](https://github.com/webmappsrl/wm-package/commit/92b6464d1a39bfc7fefb1c2cfc3ccf6c3cb96905))


### Bug Fixes

* **AppTiles:** 🔧 update tile URL for improved resource access ([c4f1a55](https://github.com/webmappsrl/wm-package/commit/c4f1a55481eb58e17696546795089579b356350d))
* **config:** 🐛 resolve URL fallback logic for wmfeUrl OC:7896 ([#216](https://github.com/webmappsrl/wm-package/issues/216)) ([6f6bdb7](https://github.com/webmappsrl/wm-package/commit/6f6bdb7df93fb6e19883b3b53ac958904f43c3c5))
* **config:** 🛠️ add visibility setting to wm-s3 filesystem ([6547351](https://github.com/webmappsrl/wm-package/commit/65473514ee5c18091350349a387f288905d39a82))
* **typo:** 🐛 correct misspellings of 'searchable' in method and tab name OC:7833 ([#213](https://github.com/webmappsrl/wm-package/issues/213)) ([6028446](https://github.com/webmappsrl/wm-package/commit/6028446a35e724067fb385c7d66182f1091a222a))


### Miscellaneous Chores

* **dependencies:** 🔄 update marshmallow/nova-tiptap version constraint ([ff34028](https://github.com/webmappsrl/wm-package/commit/ff3402845c252ad0000e996ba41bf621ba56b031))
* **dependencies:** 🔧 update illuminate/contracts and laravel/sanctum version constraints ([d2418b3](https://github.com/webmappsrl/wm-package/commit/d2418b308f84d338532e447a2738bb3ee4f9d14f))

## [1.5.0](https://github.com/webmappsrl/wm-package/compare/v1.4.0...v1.5.0) (2026-04-30)


### Features

* **ApiLinksCard:** ✨ add new component for displaying API links ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **AppTiles:** ✨ add CAI tile configuration with SVG icon ([09bda3f](https://github.com/webmappsrl/wm-package/commit/09bda3f7af9ebaae1a7404947ddd0d0408c9038e))
* **config:** 🔧 update AppConfigService for feature collection overlays ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **factory:** 🔨 create factory for FeatureCollection ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **filter:** ✨ add dynamic app_id filtering with UGC fallback OC:6075 ([#209](https://github.com/webmappsrl/wm-package/issues/209)) ([e74f997](https://github.com/webmappsrl/wm-package/commit/e74f997d3b991859c4c98075912f3d586a1dc7ad))
* **geometry:** ✨ add support for complex and point geometries in FeatureCollectionMap OC:7283 ([eb508d6](https://github.com/webmappsrl/wm-package/commit/eb508d6253fd550932ce2543f882c5510ba1c6c7))
* **geometry:** ✨ add support for complex and point geometries in FeatureCollectionMap OC:7283 ([#185](https://github.com/webmappsrl/wm-package/issues/185)) ([eb508d6](https://github.com/webmappsrl/wm-package/commit/eb508d6253fd550932ce2543f882c5510ba1c6c7))
* **geometry:** ✨ enhance FeatureCollectionMap with geometry kind detection and support ([eb508d6](https://github.com/webmappsrl/wm-package/commit/eb508d6253fd550932ce2543f882c5510ba1c6c7))
* **import-export:** ✨ add enhanced Excel import/export functionality and remove legacy import command OC:7237 ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **import-export:** ✨ add enhanced Excel import/export functionality and remove legacy import command OC:7237 ([#181](https://github.com/webmappsrl/wm-package/issues/181)) ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **import-export:** ✨ add Excel import/export functionality for EcTracks and EcPois ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **import-export:** ✨ enhance POI and track import/export functionality ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **jobs:** 🏗️ add GenerateFeatureCollectionJob for async processing ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **layer-observer:** ✨ regenerate feature collections on taxonomy changes ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **localization:** add new download options for Tracks and Pois in English and Italian ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **map-upload:** ✨ add CSV support for point geometry uploads OC:7283 ([eb508d6](https://github.com/webmappsrl/wm-package/commit/eb508d6253fd550932ce2543f882c5510ba1c6c7))
* **models:** ✨ add configurable foreign key to EcTrack's ecPois relationship oc:7739 ([dfd8d62](https://github.com/webmappsrl/wm-package/commit/dfd8d62d5e6c4c6e885aea1e520b136d04c25005))
* **models:** 🏗️ implement FeatureCollection model and relations ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **nova:** ✨ add export tracks to GeoJSON action OC:7561 ([c3401f1](https://github.com/webmappsrl/wm-package/commit/c3401f18965aa8512208c147f056b4da3764a2bf))
* **nova:** ✨ improve taxonomy import and sync process ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **nova:** 🎨 add Nova resources for FeatureCollection management ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **nova:** add horizontal scroll config options OC:7498 ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **observers:** 👀 trigger regeneration on relevant changes ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **order-list:** ✨ add color support to OrderList field ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **order-list:** ✨ implement event and job for order list reordering ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **PBFGeneratorService:** ✨ enhance PBF generation with processed geometries and track layer ranking ([#208](https://github.com/webmappsrl/wm-package/issues/208)) ([371f9c6](https://github.com/webmappsrl/wm-package/commit/371f9c61b6dcfc64f330c12cc78cc666ce56cde0))
* **resource:** 🚀 implement DEM priority logic in `EcTrackResource` ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **service:** 🛠️ add FeatureCollectionService for geojson generation ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **slope-chart:** ✨ add slope chart functionality to FeatureCollectionMap ([#189](https://github.com/webmappsrl/wm-package/issues/189)) ([eb508d6](https://github.com/webmappsrl/wm-package/commit/eb508d6253fd550932ce2543f882c5510ba1c6c7))
* **slope-chart:** ✨ integrate Vitest for testing and enhance slope chart functionality ([5d5e014](https://github.com/webmappsrl/wm-package/commit/5d5e0144b0ad2c304055a444956c9620cc8b863d))
* **storage:** 💾 implement storage for FeatureCollection geojson ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **track-color:** ✨ introduce TrackColor field for Nova ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **validation:** ✨ add zoom level validation rules OC:7548 ([#199](https://github.com/webmappsrl/wm-package/issues/199)) ([7826ea9](https://github.com/webmappsrl/wm-package/commit/7826ea944c00c266e1ca1ce0315ea4de6e9d0d0d))


### Bug Fixes

* **Controller:** handle validation failure for non-existent UGC ([#187](https://github.com/webmappsrl/wm-package/issues/187)) ([b6feba7](https://github.com/webmappsrl/wm-package/commit/b6feba7e892dde02c36c90a4e0d92d9e67c10681))
* **feature-collection-service:** 🐛 handle storage failure with exception ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **FeatureCollectionMap:** 🐛 correct slope chart enabling logic and improve coordinate handling ([433f73c](https://github.com/webmappsrl/wm-package/commit/433f73c8c5bf2667abc2c189c1ea5b174e5424fc))
* **LayerFeatureController:** 🐛 resolve morph type using relation morph map ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **Nova:** 🐛 correct string concatenation in ReindexAppScoutAction ([38f4037](https://github.com/webmappsrl/wm-package/commit/38f40374f63449701eb06350ab9c0e519777dc09))
* **services:** 🐛 correct logging in GeometryComputationService ([5e93752](https://github.com/webmappsrl/wm-package/commit/5e93752670277d24608c790a2ec95cac682c7593))
* **useFeatures:** 🐛 handle null feature name case oc: 7745 ([4f3b467](https://github.com/webmappsrl/wm-package/commit/4f3b46772fd4be88746249f3d787d42350244f93))

## [1.4.0](https://github.com/webmappsrl/wm-package/compare/v1.3.1...v1.4.0) (2026-03-23)


### Features

* **acquisition:** ✨ add default POI and track acquisition forms ([#102](https://github.com/webmappsrl/wm-package/issues/102)) ([0d704d1](https://github.com/webmappsrl/wm-package/commit/0d704d1c7d91be92720162c5b98fa16bcb509690))
* **actions:** ✨ add RegenerateEcPoiTaxonomyWhere action ([05fedd0](https://github.com/webmappsrl/wm-package/commit/05fedd0c6b92bd304b49ea65f181b0a72f1ef733))
* add ids parameter to elasticsearch controller ([90560c2](https://github.com/webmappsrl/wm-package/commit/90560c2d5c9b8d8c5430a2cac2c3b6d68942227e))
* add media on App model ([43d01c4](https://github.com/webmappsrl/wm-package/commit/43d01c4911b55dd6b7abdb51f27fe796ac4d97ee))
* add password reset route redirect ([d61b02c](https://github.com/webmappsrl/wm-package/commit/d61b02c48b8fc61a4d2bdca53392a842b68758f3))
* add setGeometryAttribute method to convert geometry to 3D and improve code styling ([b76aa49](https://github.com/webmappsrl/wm-package/commit/b76aa49ad4c93686ed35806d3bc99f01ac087674))
* add user relationship to AbstractGeometryResource and Layer models ([673e7d1](https://github.com/webmappsrl/wm-package/commit/673e7d14d7b664bd12ee4e7d46532fee4f8d8a93))
* add user relationship to EcPoi, EcTrack, and Layer models ([0340de7](https://github.com/webmappsrl/wm-package/commit/0340de7f60d1593ea83fbcf518de163afc4bd9a7))
* add user_id field to EcPoi, EcTrack, and Layer models for user relationship enhancement ([6e05c6a](https://github.com/webmappsrl/wm-package/commit/6e05c6a181791f11cec3e2f957077c5e51423d04))
* add user_id field to Media model for user association ([44dc138](https://github.com/webmappsrl/wm-package/commit/44dc1389bc9e92f1195e06392d26ae79b5563bb1))
* add WordPress integration support oc: 7029 ([#171](https://github.com/webmappsrl/wm-package/issues/171)) ([d60570a](https://github.com/webmappsrl/wm-package/commit/d60570aa585dec87b3a5981c3a0778298cb5bb69))
* **analytics:** ✨ add Posthog integration options for webapp and app ([#172](https://github.com/webmappsrl/wm-package/issues/172)) ([2d43f54](https://github.com/webmappsrl/wm-package/commit/2d43f540d665b091b96fb3f44b38e86e655a5b1d))
* **analytics:** ✨ add Posthog tracking options and localization updates ([3850fcb](https://github.com/webmappsrl/wm-package/commit/3850fcb3cf912d5e621ba2f6e2d175ff3e30de06))
* **analytics:** ✨ add webapp tracking option to Posthog localization ([bf0b4fc](https://github.com/webmappsrl/wm-package/commit/bf0b4fc940e4e3a14351930aea14c8781458b674))
* **analytics:** ✨ enhance Posthog configuration options in app settings oc:7090 ([#175](https://github.com/webmappsrl/wm-package/issues/175)) ([90402d9](https://github.com/webmappsrl/wm-package/commit/90402d9baba50307c7025fe0358651f7b787d5ce))
* **api:** ✨ add endpoint for app version information ([394ddb7](https://github.com/webmappsrl/wm-package/commit/394ddb7c2941a3cd4fa57b4a0b59104cff5053fd))
* **api:** ✨ add media deletion feature with user validation oc:5879 ([#104](https://github.com/webmappsrl/wm-package/issues/104)) ([2002854](https://github.com/webmappsrl/wm-package/commit/20028544ffbf0c660391e0f3ab58a92db95df424))
* **app:** ✨ add filters tab with customizable filter options ([#122](https://github.com/webmappsrl/wm-package/issues/122)) ([87f370b](https://github.com/webmappsrl/wm-package/commit/87f370bb5c092a7b3907e84405aac1930691ec0c))
* **App:** ✨ add geohub_id accessor and update media observer ([4acc3d5](https://github.com/webmappsrl/wm-package/commit/4acc3d5e77526a979567b9286ab25d6fbca5e765))
* **app:** ✨ add options for download tracks and tiles oc:6581 ([#147](https://github.com/webmappsrl/wm-package/issues/147)) ([a78d84e](https://github.com/webmappsrl/wm-package/commit/a78d84e66bd1a00db137c5e395eb68e23dd66058))
* **app:** ✨ add searchable tab with multi-select options ([30bfa0f](https://github.com/webmappsrl/wm-package/commit/30bfa0fe63a4200460dc49f4239cb1414a4dadf7))
* **auth:** ✨ add user update functionality and properties field oc:6255 ([#141](https://github.com/webmappsrl/wm-package/issues/141)) ([1978ce5](https://github.com/webmappsrl/wm-package/commit/1978ce5309256977b9c891edd4107cd563045bd5))
* **auth:** ✨ normalize email case in authentication ([f6a39e3](https://github.com/webmappsrl/wm-package/commit/f6a39e3f169665d72ab69a5584526db388fd1eed))
* **command:** 🚀 enhance database connection handling in WmRestoreDbCommand ([2503d62](https://github.com/webmappsrl/wm-package/commit/2503d624083e8c7eac8cc972f7c4db894c01d641))
* **commands:** ✨ add optimized PBF generation options ([8011b8c](https://github.com/webmappsrl/wm-package/commit/8011b8c41b67458c7a7151071e8c2a72667e5d8a))
* **commands:** ✨ add optimized PBF generation options ([618045e](https://github.com/webmappsrl/wm-package/commit/618045eb02f5974cbf75cb64b3a6b5c41208d40a))
* **config:** ✨ add data transformer for name field ([3e17622](https://github.com/webmappsrl/wm-package/commit/3e1762288a89e91680b355eb15d1cafa18b65c75))
* **config:** ✨ add geohub_id field to wm-ec-track-schema ([4f8d434](https://github.com/webmappsrl/wm-package/commit/4f8d4341584eb25094a35366ae383afdb4d1a310))
* **config:** ✨ add MinIO configuration file ([4960b63](https://github.com/webmappsrl/wm-package/commit/4960b63c410885065a89cbaf92d6e5d8a4a10c83))
* **config:** ✨ add new layer-schema configuration file ([1503e95](https://github.com/webmappsrl/wm-package/commit/1503e9593c5e0a4cdce59d28bafd1b055b9ece4a))
* **config:** ✨ add staging environment configuration to wm-horizon ([dc07957](https://github.com/webmappsrl/wm-package/commit/dc079570031cff31e705f67bd32fa2638a24112d))
* **config:** ✨ add taxonomy_where field to wm-ec-track-schema ([d1a7fc3](https://github.com/webmappsrl/wm-package/commit/d1a7fc362466755a7eb5242573d2835bfe545ddf))
* **config:** ✨ add UGC schema configuration and properties panel integration ([ea43d9c](https://github.com/webmappsrl/wm-package/commit/ea43d9c0537c605c3d1c78f8af2e5f6fcc8d5aa3))
* **config:** ✨ enhance Elasticsearch and Kibana configurations ([e03f64d](https://github.com/webmappsrl/wm-package/commit/e03f64dd0bf21d33dcc59e7740e8c371fdf492bb))
* **controller:** ✨ prioritize app-id header over properties ([0d8b942](https://github.com/webmappsrl/wm-package/commit/0d8b9422516f504826e98ebab501515184bddf02))
* Custom-policies OC:5436 ([5da6726](https://github.com/webmappsrl/wm-package/commit/5da672633dfb1758a599aa6f65c1fa460f0d3e34))
* **database:** ✨ add database restore functionality ([7fbd6e3](https://github.com/webmappsrl/wm-package/commit/7fbd6e3b539c04137d2e62a4d908c069f578bed8))
* **database:** 🗃️ add icon column to taxonomy tables and user_id index to layers table ([#146](https://github.com/webmappsrl/wm-package/issues/146)) ([5ee3d1f](https://github.com/webmappsrl/wm-package/commit/5ee3d1febf87a956f606fc291a1b94bd602116a4))
* db download from web interface OC:5651 ([#100](https://github.com/webmappsrl/wm-package/issues/100)) ([174f4e3](https://github.com/webmappsrl/wm-package/commit/174f4e30fd488db86c0605e9a1a124c76ac74add))
* **db-restore:** ✨ enhance database connection management before restoration ([f4b045b](https://github.com/webmappsrl/wm-package/commit/f4b045bb8dbe0a10658a0ad9e41e1ea20ed3294d))
* **docs, scripts:** ✨ add automated Apache configuration for Kibana ([42e9f6a](https://github.com/webmappsrl/wm-package/commit/42e9f6a369692098463b6ad6a588f6ec781810a3))
* **docs:** ✨ add setup guides for Kibana and MinIO with Apache reverse proxy ([15036b8](https://github.com/webmappsrl/wm-package/commit/15036b8e487fa165877d142e00348e43ea2cdeb7))
* **ec-pivot:** ✨ enhance EcPoiEcTrack model with dynamic table and FK resolution ([#180](https://github.com/webmappsrl/wm-package/issues/180)) ([b5b61f5](https://github.com/webmappsrl/wm-package/commit/b5b61f5a4e25dac2a76c1fd767a79d505734f335))
* **EcTrack:** ✨ add GeoJSON feature collection generation ([c071e7d](https://github.com/webmappsrl/wm-package/commit/c071e7d2a402ac48f97c24091463e85c68b8fe2f))
* **EcTrack:** ✨ add GeoJSON feature collection generation ([f9c0c2a](https://github.com/webmappsrl/wm-package/commit/f9c0c2aaa9de71d12df4bc61a998ee12872ba2d9))
* **elasticsearch, models:** ✨ add taxonomyIcons support oc:6383 ([#126](https://github.com/webmappsrl/wm-package/issues/126)) ([f9cecaf](https://github.com/webmappsrl/wm-package/commit/f9cecaff5e01c53e1f9db47b5e547cf8f7d8abe1))
* **elasticsearch:** ✨ add layers aggregation with size parameter oc: 5629 ([868af26](https://github.com/webmappsrl/wm-package/commit/868af2647877295e6c084b7afb057a8b86a2346a))
* **elasticsearch:** ✨ normalize aggregations structure for consistent format oc: 5629 ([6c6ca2d](https://github.com/webmappsrl/wm-package/commit/6c6ca2d7874db20bc6ac1250af65cb0602ee05cc))
* enhance BaseImportJob and GeohubImportService to associate user_id with app_id and assign Administrator role to new users ([d2bff36](https://github.com/webmappsrl/wm-package/commit/d2bff36c91e81ce46c540931abaf6ce227e59b90))
* enhance UgcTrackFactory and UgcObserver with user_id assignment and geometry normalization ([18a5c69](https://github.com/webmappsrl/wm-package/commit/18a5c69de97e00995417688ad9ef6ef2df3191d1))
* **feature-collection-map:** ✨ add automatic map screenshot functionality ([2680a0d](https://github.com/webmappsrl/wm-package/commit/2680a0d59e74d014d0b506e66b04c38b2ecda0a5))
* **FeatureCollectionMap:** ✨ add html2canvas dependency ([e9034c5](https://github.com/webmappsrl/wm-package/commit/e9034c590f86aa93ff7df23d699fd94b6fe5d0e1))
* **FeatureCollectionMap:** ✨ add support for additional point styles via callback ([c4e0ba7](https://github.com/webmappsrl/wm-package/commit/c4e0ba7eb4da427c1fc99eeb8e5de2d251faab60))
* fix pbf generation ([56d4b4e](https://github.com/webmappsrl/wm-package/commit/56d4b4e909f80f47e4b3dd751f85b59cf1537725))
* format media on ugc controllers ([74d7383](https://github.com/webmappsrl/wm-package/commit/74d73833da1a2f0a627f458d1cc6cf804a565916))
* **geohub:** ✨ add config_home update for apps oc:6307 ([#129](https://github.com/webmappsrl/wm-package/issues/129)) ([cc4d0cb](https://github.com/webmappsrl/wm-package/commit/cc4d0cbfce3369fbe3e6d58894f82e461387924b))
* **geojson:** ✨ add feature image and image gallery to GeoJson properties ([e0b13d0](https://github.com/webmappsrl/wm-package/commit/e0b13d07917b5daae166f59a42fd047d4342d8fc))
* **geometry:** ✨ add convex hull option for bounding box computation ([d2ea6c6](https://github.com/webmappsrl/wm-package/commit/d2ea6c6599ffad221f4dd04e8bb655fb09c53ac5))
* **geometry:** ✨ add GeoJSON to PostGIS geometry conversion OC:6698 ([#164](https://github.com/webmappsrl/wm-package/issues/164)) ([bbd225c](https://github.com/webmappsrl/wm-package/commit/bbd225c65a001b3ce69cdb4ea13333cdb87c3946))
* **global-file-upload:** ✨ add global file upload feature ([ccdfa9a](https://github.com/webmappsrl/wm-package/commit/ccdfa9a946b9c0a4d117efa8430d0bd75909515f))
* **global-file-upload:** ✨ add global file upload feature ([228ffb3](https://github.com/webmappsrl/wm-package/commit/228ffb39f88761c41aa117e7e38a82b72f9b1372))
* **iconSelect:** add icon select nova component ([30923b3](https://github.com/webmappsrl/wm-package/commit/30923b3224c6e8d999f2474823b82af85805afe8))
* **iconSelect:** add icon select nova component ([002101b](https://github.com/webmappsrl/wm-package/commit/002101ba74ac4bdc24e0da30e2945f5731083ffd))
* implement index query for user-specific resource access in AbstractEcResource ([69e6f77](https://github.com/webmappsrl/wm-package/commit/69e6f778b1cb191c1f9b70a0d1a43bc9f683e02e))
* **import, observer:** ✨ implement taxonomy synchronization and automated track assignment ([65c380d](https://github.com/webmappsrl/wm-package/commit/65c380d42b40bc4e853c58527a8cbf35a3a9c2e3))
* **import:** ✨ add configurable dependencies and enhance track schema ([6228318](https://github.com/webmappsrl/wm-package/commit/6228318be584ace53afd2b2e4c6ea02109f54a14))
* **import:** ✨ add configurable dependencies and enhance track schema ([b7c1284](https://github.com/webmappsrl/wm-package/commit/b7c12848f98a1abfa695dc162165e430f4f3a421))
* **import:** ✨ add ec_media import functionality ([f679e6c](https://github.com/webmappsrl/wm-package/commit/f679e6c92ae6b9226629e7cc3001275443d7cf90))
* **import:** ✨ add layer feature image association ([a45cf3f](https://github.com/webmappsrl/wm-package/commit/a45cf3fdcedd4b767f7472074596be0381950757))
* **import:** ✨ add SVG to name icon transformation ([#116](https://github.com/webmappsrl/wm-package/issues/116)) ([31cdf40](https://github.com/webmappsrl/wm-package/commit/31cdf40e56c72db0838618f29a8669d2cf3cea91))
* **import:** ✨ add taxonomy POI type import functionality oc:6202 ([#117](https://github.com/webmappsrl/wm-package/issues/117)) ([26191d7](https://github.com/webmappsrl/wm-package/commit/26191d7ddedbbfe1bc7355e600f30c9302583d8e))
* **jobs:** ✨ add debouncing mechanism for UpdateLayerGeometryJob ([aba2769](https://github.com/webmappsrl/wm-package/commit/aba27693b3b74c05fde6cc3f1dd80d74a094cc79))
* **jobs:** ✨ add UpdateAppConfigJob for asynchronous app config updates ([#135](https://github.com/webmappsrl/wm-package/issues/135)) ([0dac940](https://github.com/webmappsrl/wm-package/commit/0dac94060044810aebfcf8e0bee3499a2040551e))
* **jobs:** ✨ implement new jobs for optimized PBF generation ([8011b8c](https://github.com/webmappsrl/wm-package/commit/8011b8c41b67458c7a7151071e8c2a72667e5d8a))
* **jobs:** ✨ implement new jobs for optimized PBF generation ([618045e](https://github.com/webmappsrl/wm-package/commit/618045eb02f5974cbf75cb64b3a6b5c41208d40a))
* **layer:** ✨ add AppFilter to filters method OC:6106 ([#112](https://github.com/webmappsrl/wm-package/issues/112)) ([13f49d3](https://github.com/webmappsrl/wm-package/commit/13f49d3a40de8b4a05312848f5e16e20a0184b5b))
* **LayerFeatures:** ✨ add pagination and view mode handling ([ee8a555](https://github.com/webmappsrl/wm-package/commit/ee8a5551dc664546c266221e3a473b617caa77ea))
* **localization:** ✨ add JSON translation loading OC:6890 ([#169](https://github.com/webmappsrl/wm-package/issues/169)) ([624cac3](https://github.com/webmappsrl/wm-package/commit/624cac302d55cb3e2e78e867d8dff3565b0c8658))
* luoghi3 ([#162](https://github.com/webmappsrl/wm-package/issues/162)) ([c0b57b5](https://github.com/webmappsrl/wm-package/commit/c0b57b51c2607dcae8f94815deca9582e9dd4424))
* **map:** ✨ add 'id' to feature properties for validation ([1793f17](https://github.com/webmappsrl/wm-package/commit/1793f17c82c5fa05f831d80f5d1ee5fe4648c39d))
* **map:** ✨ add 'ref' property for feature collection ([fa4c71c](https://github.com/webmappsrl/wm-package/commit/fa4c71c83192040d21814afaa8a681406bd02ca7))
* **map:** ✨ add loading overlay and spinner for GeoJSON data ([8237293](https://github.com/webmappsrl/wm-package/commit/8237293b5e948541d58303837decc630bf7ee792))
* **map:** ✨ add multicolored concentric circles for feature points ([223aa56](https://github.com/webmappsrl/wm-package/commit/223aa565fd5ef4ea92c92c9c78007d83c0d1d7c8))
* **map:** ✨ enhance feature collection map interaction ([b7ce3d0](https://github.com/webmappsrl/wm-package/commit/b7ce3d03ba7b6a4eb906f3bf2cc7a7e5fef8829f))
* **map:** 🔥 remove SignagePopup component ([d8e365d](https://github.com/webmappsrl/wm-package/commit/d8e365da648637a103a950ec89548390b55ecb5f))
* **menu:** ✨ add Kibana and Horizon menu items ([caddbf3](https://github.com/webmappsrl/wm-package/commit/caddbf3b1777d93db8a91a09b3dfaaf21747928a))
* **metrics:** ✨ add new UGC distribution metrics OC:6879 ([#168](https://github.com/webmappsrl/wm-package/issues/168)) ([8ddf00f](https://github.com/webmappsrl/wm-package/commit/8ddf00f6082f6d0f8658b22d52ac9828f3034ea8))
* **metrics:** ✨ add user filtering option in TopUgcCreators OC 6091 ([#111](https://github.com/webmappsrl/wm-package/issues/111)) ([3e0d8c0](https://github.com/webmappsrl/wm-package/commit/3e0d8c070f7ec32d86586ffcf503b7beb339313d))
* **migrations:** ✨ add 'created_by' column to UGC tables ([cdf690c](https://github.com/webmappsrl/wm-package/commit/cdf690cf6c2b11d03c61cb940c437e4cbc60ecd4))
* **migrations:** ✨ add 'global' column to ec_pois table and update language files oc:7158 ([#178](https://github.com/webmappsrl/wm-package/issues/178)) ([ff902fe](https://github.com/webmappsrl/wm-package/commit/ff902fe21b8a4d6ef87cf0de7ec929543ca7897d))
* **model:** ✨ ensure translatable fields are arrays after retrieval oc:6267 ([7c7512b](https://github.com/webmappsrl/wm-package/commit/7c7512b98f5c7beb8a5c7c153531a889e12c5f2f))
* **model:** ✨ set default properties in EcTrack model ([134fd26](https://github.com/webmappsrl/wm-package/commit/134fd26a1f811e8e66fc3100ba7bda1f416cc211))
* **models:** ✨ add 2D to 3D geometry conversion in EcPoi OC:6368 ([#139](https://github.com/webmappsrl/wm-package/issues/139)) ([5db525b](https://github.com/webmappsrl/wm-package/commit/5db525be279f9822c1c48d410c44e4a8fc02b7cf))
* **models:** ✨ add acquisition form methods to App model ([b8df08e](https://github.com/webmappsrl/wm-package/commit/b8df08e50ca8d425f58475e574435b4d2d7401f1))
* **models:** ✨ add multilingual support for layer names ([196d36f](https://github.com/webmappsrl/wm-package/commit/196d36f9da6da73b0b6b46688acb4832dbcbe811))
* **models:** ✨ add new integer attributes to App model ([1352fed](https://github.com/webmappsrl/wm-package/commit/1352fed83987f3cf7b74a086fc7c25b61c147e50))
* **models:** ✨ add TaxonomyTheme and TaxonomyWhere models ([2281658](https://github.com/webmappsrl/wm-package/commit/22816589d5bee4a6d0f62ad7e5e902d21b243625))
* **models:** ✨ add TaxonomyTheme and TaxonomyWhere models ([642db84](https://github.com/webmappsrl/wm-package/commit/642db84f1112db7e422508814708db94d18cee22))
* **models:** ✨ add translatable property for accessibility message oc:7298 ([d572b30](https://github.com/webmappsrl/wm-package/commit/d572b30c154d42742797116ee5c876649e91edf4))
* **models:** ✨ set default properties for Layer on creation ([d425e91](https://github.com/webmappsrl/wm-package/commit/d425e9101ed2ab6b339b0bf6204c0a8c3fa9e234))
* **models:** ✨ synchronize 'name' with 'properties-&gt;name' ([d726e00](https://github.com/webmappsrl/wm-package/commit/d726e00885be956df3a9a92871a0c3a87b3d6921))
* **navigation:** ✨ add navigation interceptor for resource detail pages ([593d2e4](https://github.com/webmappsrl/wm-package/commit/593d2e44d38fa8d8ec12eeb0a4f266ee9a2902e1))
* **nova-fields:** ✨ add FeatureCollectionGrid field ([bfd1772](https://github.com/webmappsrl/wm-package/commit/bfd1772acfb0977f72113bb513dc7b9261de848f))
* **Nova:** ✨ add 'Details' tab with 'Not Accessible' field to EcTrack ([b46f94a](https://github.com/webmappsrl/wm-package/commit/b46f94af7d421c5002e9463ce7a4805c27138643))
* **nova:** ✨ add app field for user resource index oc:6614 ([641f175](https://github.com/webmappsrl/wm-package/commit/641f1756b6a3ae95e0fd7544e89b7c58b99c2a62))
* **nova:** ✨ add AppFilter and FormSchemaFilter to AbstractUgcResource ([b8df08e](https://github.com/webmappsrl/wm-package/commit/b8df08e50ca8d425f58475e574435b4d2d7401f1))
* **nova:** ✨ add AppFilter to AbstractUserResource OC:6123 ([#113](https://github.com/webmappsrl/wm-package/issues/113)) ([0cc1a84](https://github.com/webmappsrl/wm-package/commit/0cc1a84cc222ce58229845a7d63de9bf90b5afc7))
* **nova:** ✨ add default collapsed state to properties panel ([9a3ec3f](https://github.com/webmappsrl/wm-package/commit/9a3ec3f05e380f3e23a7b03b4fe96264d4f969aa))
* **Nova:** ✨ add default values for 'App' and 'User' in AbstractGeometryResource ([b6c6af1](https://github.com/webmappsrl/wm-package/commit/b6c6af1bffc929c72560c6d17c4dfa4abfbdcfed))
* **nova:** ✨ add DEM tab fields to AbstractGeometryResource ([011ad42](https://github.com/webmappsrl/wm-package/commit/011ad429dbdab4253bacf42966a707f12d572f1a))
* **nova:** ✨ add detailed information tabs to EcPoi resource ([b569838](https://github.com/webmappsrl/wm-package/commit/b569838029fdf899ae5de5f9212b0de5246a2800))
* **Nova:** ✨ add dynamic column check for user_id in query OC:6552 ([801af73](https://github.com/webmappsrl/wm-package/commit/801af73b7872b474874b1d8be6d13a20c912b14f))
* **nova:** ✨ add ExecuteEcTrackDataChainAction with batch processing ([6527bf5](https://github.com/webmappsrl/wm-package/commit/6527bf5dfe1d1384344678ff7a7b22d1494e8fd8))
* **nova:** ✨ add FeatureCollectionMap field to Nova ([7e73651](https://github.com/webmappsrl/wm-package/commit/7e73651ebac37539d080dfab57b9d5953ff8416b))
* **nova:** ✨ add HasMediaFilter to AbstractUgcResource OC:6795 ([#163](https://github.com/webmappsrl/wm-package/issues/163)) ([cd18505](https://github.com/webmappsrl/wm-package/commit/cd18505b4b07a1726416774a0044dd17c63a113e))
* **nova:** ✨ add hidden 'Properties' field to EcTrack ([b0837fd](https://github.com/webmappsrl/wm-package/commit/b0837fda9e7544525ec473cc929a80d713568dd8))
* **nova:** ✨ add label and singularLabel methods to Media class OC 6040 ([#108](https://github.com/webmappsrl/wm-package/issues/108)) ([c278edd](https://github.com/webmappsrl/wm-package/commit/c278edd7b6c9fd698f0cefcc2f32fc3f6c663084))
* **nova:** ✨ add language configuration for app OC_7068 ([#174](https://github.com/webmappsrl/wm-package/issues/174)) ([f32eb24](https://github.com/webmappsrl/wm-package/commit/f32eb24c25ddee94ec41496659a3729c3a8773c7))
* **nova:** ✨ add map functionality with tiles and data controls ([9462845](https://github.com/webmappsrl/wm-package/commit/9462845cc46437bc009b0d7c93182e95d201e8a3))
* **nova:** ✨ add media count display to resource index OC:6794 ([#165](https://github.com/webmappsrl/wm-package/issues/165)) ([563d04e](https://github.com/webmappsrl/wm-package/commit/563d04e34504679d2e6bbbf42cbde85ed3167f71))
* **Nova:** ✨ add MorphToMany field for taxonomy POI types ([d8cb9b6](https://github.com/webmappsrl/wm-package/commit/d8cb9b6f2d5dc2d2c2cb6f6f5b2cc3b4e012aae5))
* **nova:** ✨ add MorphToMany fields to Taxonomy resources ([ff10b33](https://github.com/webmappsrl/wm-package/commit/ff10b3369b6ed0476616e1328f79478461f8ec22))
* **nova:** ✨ add new action and filter for EcPoi resource ([1351c8e](https://github.com/webmappsrl/wm-package/commit/1351c8eecb5e7a71f253b34736b3e0bc516f6c0b))
* **nova:** ✨ add region filter to EcPoi resource ([850d2fa](https://github.com/webmappsrl/wm-package/commit/850d2fa124b922e2dceba43b3d0a3901ffb7279b))
* **nova:** ✨ add singular labels and labels for EcPoi, EcTrack, UgcPoi, and UgcTrack ([05be246](https://github.com/webmappsrl/wm-package/commit/05be24645ef7a0707c9bedd0a57abbf5eeb4c524))
* **Nova:** ✨ add slug and external URL layout configurations oc:6306 ([#124](https://github.com/webmappsrl/wm-package/issues/124)) ([6cfe24f](https://github.com/webmappsrl/wm-package/commit/6cfe24f1ef4bbb06a695c711f350c1dd2c77f326))
* **nova:** ✨ add TaxonomyPoiType resource ([2281658](https://github.com/webmappsrl/wm-package/commit/22816589d5bee4a6d0f62ad7e5e902d21b243625))
* **nova:** ✨ add TaxonomyPoiType resource ([642db84](https://github.com/webmappsrl/wm-package/commit/642db84f1112db7e422508814708db94d18cee22))
* **nova:** ✨ add TopUgcCreators metric to AbstractUgcResource OC:6077 ([#109](https://github.com/webmappsrl/wm-package/issues/109)) ([5b3d0d0](https://github.com/webmappsrl/wm-package/commit/5b3d0d034e5862b2439b979e72da8b8918340904))
* **nova:** ✨ add UGC POIs and UGC Tracks relations to AbstractUserResource OC:6589 ([#150](https://github.com/webmappsrl/wm-package/issues/150)) ([ad35b91](https://github.com/webmappsrl/wm-package/commit/ad35b917574cde72fddad71cd33196a52524ce0c))
* **nova:** ✨ enhance MultiLinestringResourceTrait with new geometry handling ([5aabaf2](https://github.com/webmappsrl/wm-package/commit/5aabaf21be10b4446be2b9b410eab0ee9af3d4c5))
* **nova:** ✨ enhance properties panel with additional collapsible sections ([70abab5](https://github.com/webmappsrl/wm-package/commit/70abab5cd0b8ef5dcf8c1534dd691f3e8fcada17))
* **nova:** ✨ implement AppFilter for better app-specific filtering ([b8df08e](https://github.com/webmappsrl/wm-package/commit/b8df08e50ca8d425f58475e574435b4d2d7401f1))
* **nova:** ✨ update image field visibility oc:6521 ([3d19312](https://github.com/webmappsrl/wm-package/commit/3d19312c9e65a93f327cc8a027aab08d6afd9179))
* **observer:** ✨ add Artisan queue for building POIs GeoJSON oc:6286 ([#119](https://github.com/webmappsrl/wm-package/issues/119)) ([6cd5e36](https://github.com/webmappsrl/wm-package/commit/6cd5e36c3e3140f97b3817825c6099b01f731bac))
* **observer:** ✨ add UpdateAppConfigJob to LayerObserver ([0dac940](https://github.com/webmappsrl/wm-package/commit/0dac94060044810aebfcf8e0bee3499a2040551e))
* **observer:** ✨ dispatch geojson update job in TaxonomyPoiTypeablesObserver ([0897d6f](https://github.com/webmappsrl/wm-package/commit/0897d6f993f66ad6d39db75f3efc59f8181fd48b))
* **observer:** ✨ enhance EcPoiObserver and TaxonomyPoiTypeablesObserver functionality ([9cdfedd](https://github.com/webmappsrl/wm-package/commit/9cdfedd4e639ec9bbea8ffa9e8e99727e1e12a74))
* **observer:** ✨ ensure layer geometry updates on save ([0967a13](https://github.com/webmappsrl/wm-package/commit/0967a13b1e14ee61ad272b2a806c9a81f0bad394))
* **observers:** ✨ add TaxonomyPoiTypeablesObserver for icon management ([26191d7](https://github.com/webmappsrl/wm-package/commit/26191d7ddedbbfe1bc7355e600f30c9302583d8e))
* **observers:** ✨ enhance track and layerable deletion handling ([4cd6c77](https://github.com/webmappsrl/wm-package/commit/4cd6c7786a181afb65077794ffaa7c38c8517ebd))
* **PBFGeneratorService:** ✨ extract 'name' property from geometry ([1390fce](https://github.com/webmappsrl/wm-package/commit/1390fce67ae02e5518f6dfd26c6715e0beb7d8ab))
* **PBFGeneratorService:** ✨ extract 'name' property from geometry ([0ac3ac6](https://github.com/webmappsrl/wm-package/commit/0ac3ac6a102246308451c7e0c40d2d50f93af5ea))
* **PBFGeneratorService:** ✨ include entity name in valid geometries and simplified geometries ([3a5d24b](https://github.com/webmappsrl/wm-package/commit/3a5d24b2a0b643b765c4548c1c6ae0aac81ee9a3))
* **PBFGeneratorService:** ✨ include entity name in valid geometries and simplified geometries ([69f1d15](https://github.com/webmappsrl/wm-package/commit/69f1d155de91192eda0c826f4f4767e4ad580d04))
* **pois:** ✨ add POIs tab with customizable fields ([80c1d2b](https://github.com/webmappsrl/wm-package/commit/80c1d2b32bb38fbb993c8827a056e833b7cca497))
* **restore:** ✨ add migration step post-database restore ([ffa48c5](https://github.com/webmappsrl/wm-package/commit/ffa48c5dd3f81be719ab86fbbc810052bdba90f3))
* **schema:** ✨ add POI schema configuration and icon generation command oc:7133 ([#177](https://github.com/webmappsrl/wm-package/issues/177)) ([bd64f0a](https://github.com/webmappsrl/wm-package/commit/bd64f0a3e32de0b58464e7b5f6eab23a7f471f01))
* **Service:** ✨ add initDataChain method to EcTrackService ([da6e2b4](https://github.com/webmappsrl/wm-package/commit/da6e2b4d307b2e171dfbcce13f5e44da1c413de1))
* **service:** ✨ add new batch generation step in EcTrackService ([f9cecaf](https://github.com/webmappsrl/wm-package/commit/f9cecaff5e01c53e1f9db47b5e547cf8f7d8abe1))
* **services:** ✨ add regeneratePbfsForLayer method in PBFGeneratorService ([c672b01](https://github.com/webmappsrl/wm-package/commit/c672b0132d659393e3322af882932a12ef1b3084))
* **services:** ✨ enhance GeometryComputationService for optimized tile generation ([8011b8c](https://github.com/webmappsrl/wm-package/commit/8011b8c41b67458c7a7151071e8c2a72667e5d8a))
* **services:** ✨ enhance GeometryComputationService for optimized tile generation ([618045e](https://github.com/webmappsrl/wm-package/commit/618045eb02f5974cbf75cb64b3a6b5c41208d40a))
* sostituito il campo 'Welcome' con un campo 'Code' e migliorata la gestione del layer nel resolver ConfigHomeResolver ([f6b85ff](https://github.com/webmappsrl/wm-package/commit/f6b85ff8068bd1686aa4732db6c3afc30bd1fc48))
* **storage:** ✨ add deleteTrack method to StorageService ([373eef5](https://github.com/webmappsrl/wm-package/commit/373eef50aa653acd89849d56751818426c054e5d))
* **traits:** ✨ add tile URL configuration to MapMultiLinestring ([a56e110](https://github.com/webmappsrl/wm-package/commit/a56e1107a2016d2b9569660d147919a4e1b0aaa2))
* **traits:** ✨ update TaxonomyAbleModel to include new taxonomy relations ([2281658](https://github.com/webmappsrl/wm-package/commit/22816589d5bee4a6d0f62ad7e5e902d21b243625))
* **traits:** ✨ update TaxonomyAbleModel to include new taxonomy relations ([642db84](https://github.com/webmappsrl/wm-package/commit/642db84f1112db7e422508814708db94d18cee22))
* **translation:** 🌐 add translation support using OpenAI API ([2df2000](https://github.com/webmappsrl/wm-package/commit/2df20009a4e5566f3d985884d093785d65bf7c82))
* **ugc:** ✨ add created_by attribute to UGC models OC:6363 ([#130](https://github.com/webmappsrl/wm-package/issues/130)) ([d26a9a7](https://github.com/webmappsrl/wm-package/commit/d26a9a7eae732eba389d6357cd0f2176132e4414))
* update AbstractAuthorableObserver to handle user association on model creation and improve user determination logic ([8e0a5a0](https://github.com/webmappsrl/wm-package/commit/8e0a5a0038c7b10b8fd43e189bbb9ccbc6a2fcf4))
* update app pbfs on layer delete ([a887a12](https://github.com/webmappsrl/wm-package/commit/a887a1253478e587a1f7481d3ecde0f2f96c2928))
* **useFeatures:** ✨ add loading and no-rows overlay handling ([0ad9e50](https://github.com/webmappsrl/wm-package/commit/0ad9e50309e3f304399283323d784e8f6440830e))


### Bug Fixes

* add ids parameter to elasticsearch controller ([256d58e](https://github.com/webmappsrl/wm-package/commit/256d58e9e61fa38bca8c8b5cb852b5e5aa302009))
* add pages ([69d9cd9](https://github.com/webmappsrl/wm-package/commit/69d9cd9e7a86330df4ca16a31d2e6ee90cada790))
* add User model import to OwnedByUserModel trait ([558861d](https://github.com/webmappsrl/wm-package/commit/558861dd72b8165f57e066cb64e5403917508178))
* app observer added try catch to handle poy_types missing ([3d978af](https://github.com/webmappsrl/wm-package/commit/3d978af6f0899a1fd8fc5c997927b55166ecec4a))
* app policy ([bb6a525](https://github.com/webmappsrl/wm-package/commit/bb6a525c61ce2d1bda4a4a6033d868a38b1df83e))
* **app-config:** 🐛 add missing title assignment ([454a814](https://github.com/webmappsrl/wm-package/commit/454a8141c10e082c1e0b983b8099554654c118e2))
* **app:** 🐛 handle local disk paths and file not found ([2063b06](https://github.com/webmappsrl/wm-package/commit/2063b061339b8938391943c041a3e0bf9a11c363))
* **app:** 🐛 improve title fallback logic ([549475a](https://github.com/webmappsrl/wm-package/commit/549475a85c53a9a5aeff04dae9bdba4f4ccc28ca))
* **AppAuthController:** handle token refresh and improve error handling OC:5625 ([68d6768](https://github.com/webmappsrl/wm-package/commit/68d6768d6420d7e6058c2336b680f933c26d8b99))
* **appconfigService:layer:** fix json result ([39f4e99](https://github.com/webmappsrl/wm-package/commit/39f4e99b6a1262ec1e10d2de3cfe70624b91fd0d))
* **AppFilter:** 🐛 correct pluck order for app filter ([51756dc](https://github.com/webmappsrl/wm-package/commit/51756dc95d73969f2ef53f00a08ec048ff93c62f))
* backup command ([8031b2e](https://github.com/webmappsrl/wm-package/commit/8031b2e89923f4cdde33c9bd0903c73474ed4c22))
* **config:** 🐛 update field mapping for geohub import ([ded3228](https://github.com/webmappsrl/wm-package/commit/ded3228643ea397b917ed8a9b3717a8aca81bc90))
* **config:** 🔧 update default model namespace for ec_track_model ([ff09c80](https://github.com/webmappsrl/wm-package/commit/ff09c80e465fea6d3f692fe83ff814e082f408fd))
* **controller:** 🐛 correct feature decoding logic ([2002854](https://github.com/webmappsrl/wm-package/commit/20028544ffbf0c660391e0f3ab58a92db95df424))
* **EcPoiService:** 🐛 handle null elevation and taxonomyWhere in updateDataChain ([148bd6d](https://github.com/webmappsrl/wm-package/commit/148bd6df07276417e4c99f91825166fc06acfa35))
* **EcTrack, Layer:** 🐛 handle missing data and exceptions gracefully ([144523d](https://github.com/webmappsrl/wm-package/commit/144523d577d6da5cf58dd3b5c573a4fca3561c92))
* **EcTrack:** 🐛 handle null app_id and app scenarios in getSearchables ([6d04ea1](https://github.com/webmappsrl/wm-package/commit/6d04ea16d4ab32d41192a1c9371cd42239634120))
* **EcTrackService:** update method call for taxonomy activities retrieval ([d910bfc](https://github.com/webmappsrl/wm-package/commit/d910bfc29462cfc557d7e2883f12327235abbe09))
* edit ugc ([0be2186](https://github.com/webmappsrl/wm-package/commit/0be218625f5c2e1ccd6efcd397c126d4379d5044))
* enhance UgcPoiControllerTest and GetUpdatedAtTrackTest with S3 configuration and mock setup improvements ([0b28f4a](https://github.com/webmappsrl/wm-package/commit/0b28f4aa914335421ea1d943bb21d69bafa3836f))
* enhance UpdateDataChainTest with additional job assertions and mock setups for geometry changes ([b8a9b57](https://github.com/webmappsrl/wm-package/commit/b8a9b57a56c3ca88abdc74697acfa781dec30d33))
* enhance UpdateDataChainTest with additional mock setup for queueable ID handling ([cf4f321](https://github.com/webmappsrl/wm-package/commit/cf4f321597c42dcc4a57f022ee8b67a9206353ba))
* enhance UpdateDataChainTest with additional mock setups for model serialization and direct property access ([fa03089](https://github.com/webmappsrl/wm-package/commit/fa030899d2362b501f3d0ce3f38b27cc135c4c75))
* enhance UpdateDataChainTest with additional mock setups for model serialization issues ([81558c7](https://github.com/webmappsrl/wm-package/commit/81558c7eafa0d8a15a23a705e313c653547f1c22))
* enhance UpdateDataChainTest with constructor mock setup for deserialization edge cases ([a105059](https://github.com/webmappsrl/wm-package/commit/a10505918a9d799c34e32b12b6b2d3a2e8afeb68))
* enhance UpdateDataChainTest with explicit property setting for queueable class in mock setup ([2784a3e](https://github.com/webmappsrl/wm-package/commit/2784a3e931ff90c148e88ee7dd481ab367b9521e))
* enhance UpdateDataChainTest with pure mock usage for improved property access and queue serialization ([32a87c3](https://github.com/webmappsrl/wm-package/commit/32a87c31bd0affc2012deef761491fcd591b359c))
* enhance UpdateDataChainTest with refined mock setups for property access and geometry change assertions ([70f3c91](https://github.com/webmappsrl/wm-package/commit/70f3c91b1048780f940810a556184da11d3b604e))
* enhance UpdateDataChainTest with refined mock setups for queue serialization and property access ([0c7162a](https://github.com/webmappsrl/wm-package/commit/0c7162a2cf3d607083b99dfc912fa64c0869cb11))
* enhance UpdateDataChainTest with refined mock strategies for property access and handling of isset and direct property access ([03f3e7a](https://github.com/webmappsrl/wm-package/commit/03f3e7a4868bd0ad423a28dbc4bff133b74b404a))
* ensure parent booted method is called in UgcTrack and set default name in UgcObserver ([c9b8ca3](https://github.com/webmappsrl/wm-package/commit/c9b8ca34855b146cac7a4d1799cd20262fa331e7))
* error on layer image update ([a8e9a6b](https://github.com/webmappsrl/wm-package/commit/a8e9a6bf6fbc4656ef4aa9dd4c1fdbca8e7fe4b2))
* favorites list ([73b9ffc](https://github.com/webmappsrl/wm-package/commit/73b9ffc6f90352633b6e017ff30e7430e2813df7))
* fields ([7831802](https://github.com/webmappsrl/wm-package/commit/7831802630acce1b1ea82805dc67060f159e62b9))
* **filters:** ✨ hide certain filter fields from index view ([#123](https://github.com/webmappsrl/wm-package/issues/123)) ([6f826d8](https://github.com/webmappsrl/wm-package/commit/6f826d8dff86021d0bca35a4fc58274779a9b41e))
* **geometry:** 🐛 change fit method from Contain to Crop ([e9570a1](https://github.com/webmappsrl/wm-package/commit/e9570a1ac52bba62d552f9d4aa78513479d5340e))
* **geometry:** 🐛 handle null bbox gracefully in database query ([6a79e17](https://github.com/webmappsrl/wm-package/commit/6a79e172bedfc899117af787c971f9adaa35ea67))
* handle invalid GeoJSON in features oc: 6572 ([#149](https://github.com/webmappsrl/wm-package/issues/149)) ([ece659c](https://github.com/webmappsrl/wm-package/commit/ece659c552398c42a1219516742a023fe3cba2ed))
* hide nova tab translatable ([0a9f597](https://github.com/webmappsrl/wm-package/commit/0a9f597e316ae32ca33eeb042583ab9cd9cabde0))
* **jobs:** 🐛 ensure correct model class usage and error handling ([32f794f](https://github.com/webmappsrl/wm-package/commit/32f794f308f951a735ef32c6a8fd67628f800610))
* **jobs:** 🐛 remove redundant variable in ImportEcTrackJob ([833c814](https://github.com/webmappsrl/wm-package/commit/833c814bb1043faa08f01cca426443202e4785e8))
* **layer:** 🐛 ensure app_id is set during model creation ([7680fd1](https://github.com/webmappsrl/wm-package/commit/7680fd1d17d821d05bbe79cb21c2261a73ef9c88))
* **LayerFeature:** 🐛 correct loading overlay class oc:6266 ([0ad9e50](https://github.com/webmappsrl/wm-package/commit/0ad9e50309e3f304399283323d784e8f6440830e))
* **logging:** 🐛 improve error message formatting in EcTrackService ([30bfa0f](https://github.com/webmappsrl/wm-package/commit/30bfa0fe63a4200460dc49f4239cb1414a4dadf7))
* make geometry fields required in MultiLinestringResourceTrait and PointResourceTrait ([4a381d2](https://github.com/webmappsrl/wm-package/commit/4a381d277084294c812e46230ed47975f97a1355))
* **media:** 🐛 specify relationship name in MorphTo field ([069386b](https://github.com/webmappsrl/wm-package/commit/069386b94853e28931d635c07337a3233e45576d))
* **model:** 🐛 call parent booted method in EcTrack ([7c7512b](https://github.com/webmappsrl/wm-package/commit/7c7512b98f5c7beb8a5c7c153531a889e12c5f2f))
* **model:** 🐛 correct layers property to use pluck on layers collection ([952786a](https://github.com/webmappsrl/wm-package/commit/952786a1963d3db17219e89eb09548089fab4b39))
* **models:** 🐛 remove redundant translation entry ([0f4bbbf](https://github.com/webmappsrl/wm-package/commit/0f4bbbf270457a4ec8ad0f722d78b01c95d9e181))
* name on layer custom field ([2b182d0](https://github.com/webmappsrl/wm-package/commit/2b182d006d8e526de4f06adb732508d7664257b2))
* nova name ([7f0bc21](https://github.com/webmappsrl/wm-package/commit/7f0bc2165894f8dedc65f4611cbd60cbec923118))
* **nova:** 🐛 correct casing in attribute names ([1349c23](https://github.com/webmappsrl/wm-package/commit/1349c2373eef91b4f8ecd275d4866a838afd741b))
* **nova:** 🐛 update relationship names to snake_case ([#151](https://github.com/webmappsrl/wm-package/issues/151)) ([ae243a3](https://github.com/webmappsrl/wm-package/commit/ae243a3a763b98bfeae334ce3e21a13ff80b109e))
* **observer:** 🐛 update command signature in EcPoiObserver ([6cd5e36](https://github.com/webmappsrl/wm-package/commit/6cd5e36c3e3140f97b3817825c6099b01f731bac))
* **observer:** 🐛 update taxonomy_where check in LayerObserver ([d3f0886](https://github.com/webmappsrl/wm-package/commit/d3f08861dd64926cc77efba285a860cef5690403))
* **observers:** 🐛 update PBF generation logic in observers ([8011b8c](https://github.com/webmappsrl/wm-package/commit/8011b8c41b67458c7a7151071e8c2a72667e5d8a))
* **observers:** 🐛 update PBF generation logic in observers ([618045e](https://github.com/webmappsrl/wm-package/commit/618045eb02f5974cbf75cb64b3a6b5c41208d40a))
* **policy:** 🐛 update namespace for User model import OC:6631 ([#156](https://github.com/webmappsrl/wm-package/issues/156)) ([cc7e2cc](https://github.com/webmappsrl/wm-package/commit/cc7e2cc4d10206ee01f7e031a71934f0bc90038c))
* **properties-panel:** 🐛 handle non-array options in field schema ([9ecaba5](https://github.com/webmappsrl/wm-package/commit/9ecaba53f18ff1becc81ec9d68e103bbd19c9e78))
* **properties-panel:** 🐛 prevent adding 'form' attribute with 'id' key ([dfa990d](https://github.com/webmappsrl/wm-package/commit/dfa990d50d66c9de518e5068adf5132142e2b364))
* **provider:** 🐛 fix namespace issues in WmPackageServiceProvider ([1503e95](https://github.com/webmappsrl/wm-package/commit/1503e9593c5e0a4cdce59d28bafd1b055b9ece4a))
* **queries:** correct join condition in query oc: 7063 ([b75e394](https://github.com/webmappsrl/wm-package/commit/b75e3943f000530c1975966280f9a6dfee3ff78a))
* **queries:** correct join condition in query oc: 7063 ([68f6563](https://github.com/webmappsrl/wm-package/commit/68f656341be9bdcd78a59f80af5e2d044176c0e5))
* refine UpdateDataChainTest with improved mock setups for property access and serialization handling ([241ec0a](https://github.com/webmappsrl/wm-package/commit/241ec0ade7e2145aea7a03f60058bd3f422f89a0))
* refine UpdateDataChainTest with pure mock usage and enhanced serialization setups for model attributes ([ee5db0c](https://github.com/webmappsrl/wm-package/commit/ee5db0c556ca47768dfef749470caabcc880d510))
* refine UpdateDataChainTest with updated mock strategies for property access and geometry change handling ([5c7a030](https://github.com/webmappsrl/wm-package/commit/5c7a030d168fdbf940bda4dcccbe23a326b44700))
* removed a.color from taxonomy_poi_types ([6c80c5d](https://github.com/webmappsrl/wm-package/commit/6c80c5d0df5529864ce3e3401ca64b72196d4795))
* **resource:** 🐛 filter ecPois by osmfeatures_id and convert to array ([#132](https://github.com/webmappsrl/wm-package/issues/132)) ([dd51607](https://github.com/webmappsrl/wm-package/commit/dd51607390a0ef3044e997254ee7c67e75220896))
* **resource:** 🐛 remove onlyOnDetail constraint from Images field ([a1fcfe6](https://github.com/webmappsrl/wm-package/commit/a1fcfe697068b80ef9dd3a8999e8a5f2b8bc42c9))
* **resource:** improve media handling oc: 6404 ([#134](https://github.com/webmappsrl/wm-package/issues/134)) ([36fb5f5](https://github.com/webmappsrl/wm-package/commit/36fb5f549c0f4cf74e1c0b3aa8df03a4f8ecaea8))
* **service-provider:** 🐛 update local environment URL for Kibana ([361e5dd](https://github.com/webmappsrl/wm-package/commit/361e5ddce9dcf3d2fa872a1e5b3155244a7f1599))
* **service:** 🐛 add comment for config_home handling ([0dac940](https://github.com/webmappsrl/wm-package/commit/0dac94060044810aebfcf8e0bee3499a2040551e))
* tests ([cb5a0b2](https://github.com/webmappsrl/wm-package/commit/cb5a0b2c86f81114e2dc3fcbff13936a9f7778f4))
* update AppFactory and tests for improved data handling and configuration consistency ([c4156b9](https://github.com/webmappsrl/wm-package/commit/c4156b9d387e2144960279326b220c21e9297dce))
* update field policy on ectrack ([987e147](https://github.com/webmappsrl/wm-package/commit/987e1475623114c6010848eec68f32c35b62b01c))
* update GetUpdatedAtTrackTest and UpdateDataChainTest with improved mock setups and data handling ([ef588df](https://github.com/webmappsrl/wm-package/commit/ef588df362ad3ddfe5049dd01c4365d21d8ac1e7))
* update Name field logic oc: 6625 ([#154](https://github.com/webmappsrl/wm-package/issues/154)) ([b567fc8](https://github.com/webmappsrl/wm-package/commit/b567fc838dd7bb4549369a8cd9f2af1be6a22669))
* update PointResourceTrait to hide geometry field from index view ([9256560](https://github.com/webmappsrl/wm-package/commit/92565602722de4aed0288dedd81a00402c4b09c0))
* update policies ([7e56e2e](https://github.com/webmappsrl/wm-package/commit/7e56e2e2000f6ccadc9d906b6839e630255ba87e))
* update UgcObserverTest to ensure model creation triggers observer methods and improve data handling in UpdateDataChainTest ([9e05cc6](https://github.com/webmappsrl/wm-package/commit/9e05cc6bcae8250725118f1302e5ecc1c830c88f))
* update UgcPoiControllerTest and UpdateDataChainTest for improved configuration and mock handling ([cbfd64a](https://github.com/webmappsrl/wm-package/commit/cbfd64a65039c3d5c684116755d0a831d5983448))
* update validation rules for app_id in Controller and improve handling in UgcController and MediaObserver ([3e557ae](https://github.com/webmappsrl/wm-package/commit/3e557ae2cfd1c5d37321bb89b471bf9fad9b20cb))
* updated horizon configuration to merge with the app's existing one, instead of override. ([4887733](https://github.com/webmappsrl/wm-package/commit/4887733cb1face593b02aa999ceeac35e89060c8))
* **url:** 🐛 correct URL generation in TopUgcCreators ([0f807d8](https://github.com/webmappsrl/wm-package/commit/0f807d8e097f7c8d25a7f10c853f7e0083f62054))
* **url:** 🐛 correct URL generation in TopUgcCreators ([b0b46ba](https://github.com/webmappsrl/wm-package/commit/b0b46ba8cfdb799536e5dec8e5bb643689e9c63f))
* **useGrid:** 🐛 correct resource URL path ([0ad9e50](https://github.com/webmappsrl/wm-package/commit/0ad9e50309e3f304399283323d784e8f6440830e))
* wm-package tests ([546f688](https://github.com/webmappsrl/wm-package/commit/546f688c9ad40935e20e4e58a5e647972affe1f6))
* **WmPackageServiceProvider:** 🐛 correggere l'autenticazione dell'utente per il menu di download DB ([7de067c](https://github.com/webmappsrl/wm-package/commit/7de067c74bc2fffa527e4ae35a0f37bcdecb4bb5))
* wrong middleware ([d423d6d](https://github.com/webmappsrl/wm-package/commit/d423d6dd0fdcdac09bfe930ad639b138e9135931))


### Miscellaneous Chores

* add force update option to app config oc: 6540 ([#144](https://github.com/webmappsrl/wm-package/issues/144)) ([096d7b4](https://github.com/webmappsrl/wm-package/commit/096d7b4b3cc10c813d76e58c09155cccc37a4e33))
* add release data tab ([493062a](https://github.com/webmappsrl/wm-package/commit/493062ab737b827d83ae7da08ff8a120007cb7b6))
* **debug:** 🔧 remove xdebug breakpoint from PropertiesPanel ([0f807d8](https://github.com/webmappsrl/wm-package/commit/0f807d8e097f7c8d25a7f10c853f7e0083f62054))
* **debug:** 🔧 remove xdebug breakpoint from PropertiesPanel ([b0b46ba](https://github.com/webmappsrl/wm-package/commit/b0b46ba8cfdb799536e5dec8e5bb643689e9c63f))
* **FormSchemaFilter:** ✨ aggiungi filtro per schema di modulo ([c902155](https://github.com/webmappsrl/wm-package/commit/c9021553ab9f15861cbc517621406bae579b7550))
* **geojson-service:** 🔧 add timestamps to GeoJSON properties ([a8e1827](https://github.com/webmappsrl/wm-package/commit/a8e182722c848f9208df8faa8d758196cdc280b8))
* **GeoJsonService:** 🧹 remove outdated TODO comment regarding Taxonomy whens ([1c5cb34](https://github.com/webmappsrl/wm-package/commit/1c5cb3465f7b10b6bb96ee34ed36fb3d1d896ae6))
* **import:** 🔧 comment out layer entity import ([847f439](https://github.com/webmappsrl/wm-package/commit/847f439e9dc85c56e54e30243e138051bf67c38b))
* **ImportAppJob:** 🔧 comment out unused entity imports ([b824bb0](https://github.com/webmappsrl/wm-package/commit/b824bb0561ec25893e38a070d6b113d7e0b8af98))
* init luoghi3 ([ebc506c](https://github.com/webmappsrl/wm-package/commit/ebc506c8a05ff4e04d052c01ccfe632b17f787d5))
* **observer:** 🔧 comment out unused user app ID assignment ([087aa58](https://github.com/webmappsrl/wm-package/commit/087aa58e2625c2e7e8160cad95a93429370f0887))
* **observers:** 🔧 specify connection and queue for PBF jobs ([519f11b](https://github.com/webmappsrl/wm-package/commit/519f11b146ef0398e9aff8fa5f98dd6b1473f783))
* **provider:** 🔧 add WmBuildAppPoisGeojsonCommand to service provider ([#120](https://github.com/webmappsrl/wm-package/issues/120)) ([c694e01](https://github.com/webmappsrl/wm-package/commit/c694e01b0247393cdde8fb32a985e30376db174f))
* **restore_db:** 🔧 update database container name to use APP_NAME ([c1d2f5d](https://github.com/webmappsrl/wm-package/commit/c1d2f5db334093fc4d09fdb6490eaf1fcb1cfe9a))
* **restore_db:** 🔧 update SQL dump paths for backup consistency ([8c3a4e4](https://github.com/webmappsrl/wm-package/commit/8c3a4e451e7c387ced2aa9c02c76836c5daf588e))
* **ScheduleServiceProvider:** 🔧 fix command formatting for backup scheduling ([f10ebe8](https://github.com/webmappsrl/wm-package/commit/f10ebe82835b224a74424ea5ce57df0cdb0bc114))
* **ScheduleServiceProvider:** 🔧 reorder backup command scheduling ([8301862](https://github.com/webmappsrl/wm-package/commit/830186262ae4f1ffbf601c778735e027fbfc4fd3))
* **wm-filesystems:** 🔧 update S3 configuration for dumps ([75be301](https://github.com/webmappsrl/wm-package/commit/75be30134ffc814bafd4177fe53d4179b2ba0b87))
* **WmDownloadDbBackupCommand:** 🔧 clean up code formatting and improve error messages ([eef66f7](https://github.com/webmappsrl/wm-package/commit/eef66f70cc30188a298eae447e52256ab885b9d1))
* **WmDownloadDbBackupCommand:** 🔧 improve code formatting and error messages ([c431141](https://github.com/webmappsrl/wm-package/commit/c4311414c5a51c61e143cf7060ff01f7166502ee))

## [1.3.1](https://github.com/webmappsrl/wm-package/compare/v1.3.0...v1.3.1) (2025-01-27)


### Miscellaneous Chores

* **deps:** bump dependabot/fetch-metadata from 1.6.0 to 2.3.0 ([06bf1ed](https://github.com/webmappsrl/wm-package/commit/06bf1eda43941433f713010ab16cbf72e0ee65d3))

## [1.3.0](https://github.com/webmappsrl/wm-package/compare/v1.2.6...v1.3.0) (2024-12-30)


### Features

* user model abstraction over wm-package oc: 4498 ([#46](https://github.com/webmappsrl/wm-package/issues/46)) ([79635db](https://github.com/webmappsrl/wm-package/commit/79635db2ad180d396eac1f8cc5f34940b1a5aafb))


### Bug Fixes

* add sku field to users due its usage in controller ([42d944f](https://github.com/webmappsrl/wm-package/commit/42d944fc64cfe5c770853244fcaa88dd2db44394))
* fix wp stan errors ([b37dfd0](https://github.com/webmappsrl/wm-package/commit/b37dfd010c043fa39be4ed5282ad1eff6f6fb670))

## [1.2.6](https://github.com/webmappsrl/wm-package/compare/v1.2.5...v1.2.6) (2024-12-18)


### Miscellaneous Chores

* tests reviewed ([b9359fc](https://github.com/webmappsrl/wm-package/commit/b9359fc197de8a3d04279ff3e9f949bbd3e411a2))

## [1.2.5](https://github.com/webmappsrl/wm-package/compare/v1.2.4...v1.2.5) (2024-12-09)


### Bug Fixes

* remove laravel actions oc: 4437 ([83a5d77](https://github.com/webmappsrl/wm-package/commit/83a5d771326df07c3e3d9254556b750c39317a8e))

## [1.2.4](https://github.com/webmappsrl/wm-package/compare/v1.2.3...v1.2.4) (2024-12-04)


### Bug Fixes

* php stan errors oc: 4377 ([c7819f6](https://github.com/webmappsrl/wm-package/commit/c7819f65ef44458edf11e9d18774657e9a28873f))
* php stan errors oc: 4377 ([73d7783](https://github.com/webmappsrl/wm-package/commit/73d7783271eaeccfa9719c4962a4a511bc8ef748))

## [1.2.3](https://github.com/webmappsrl/wm-package/compare/v1.2.2...v1.2.3) (2024-11-27)


### Miscellaneous Chores

* test another minor fix release change ([40acd07](https://github.com/webmappsrl/wm-package/commit/40acd07781c87e969dd6502383b11173376aaa1c))

## [1.2.2](https://github.com/webmappsrl/wm-package/compare/v1.2.1...v1.2.2) (2024-11-27)


### Miscellaneous Chores

* test another minor fix release change ([45de486](https://github.com/webmappsrl/wm-package/commit/45de486b8174feea8028e7fb3a9b10d1386d739c))
* test another minor fix release change ([490c048](https://github.com/webmappsrl/wm-package/commit/490c048a54d879c73c70d47734c2ed22c709fe41))

## [1.2.1](https://github.com/webmappsrl/wm-package/compare/v1.2.0...v1.2.1) (2024-11-27)


### Miscellaneous Chores

* test minor fix release change ([74627a1](https://github.com/webmappsrl/wm-package/commit/74627a14695e2ce8cf0dc91d3b25f731369d73dd))

## [1.2.0](https://github.com/webmappsrl/wm-package/compare/webmappsrl/wm-package-v1.1.0...webmappsrl/wm-package-v1.2.0) (2024-11-27)


### Features

* api endpoint for app communication implemented ([7eb3fb4](https://github.com/webmappsrl/wm-package/commit/7eb3fb43d8ac297a68cbafe180ac00ba62396c3b))
* Edit field Action OC:4184 ([#21](https://github.com/webmappsrl/wm-package/issues/21)) ([c3a0f83](https://github.com/webmappsrl/wm-package/commit/c3a0f8371ffbde5d8f5bad90e384d83536c2a3f2))
* re implemented basejob class ([746b49f](https://github.com/webmappsrl/wm-package/commit/746b49f33a5e5c8d2be8eafdc4f75ee39e936a32))
* wm package enhancement ([#20](https://github.com/webmappsrl/wm-package/issues/20)) ([f939960](https://github.com/webmappsrl/wm-package/commit/f9399602086433e3875ffd733c83efccd5ee47b4))


### Bug Fixes

* composer php versions mismatch between develop and main ([51d6a53](https://github.com/webmappsrl/wm-package/commit/51d6a5311503eba1d607da0671c033361ee32b2e))
* problems with composer packages versions ([90add2b](https://github.com/webmappsrl/wm-package/commit/90add2b88ccab34099a3e49027208368c3d26efd))
* update tests routines ([a463014](https://github.com/webmappsrl/wm-package/commit/a463014e7f46cb3bfd064c578160eb23dd67a832))
* wrong configuration of wmdumps filesystem ([4973202](https://github.com/webmappsrl/wm-package/commit/4973202d61f65d0b94438da16eaf43331d800e85))

## 1.0.0 (2024-11-27)


### Features

* api endpoint for app communication implemented ([7eb3fb4](https://github.com/webmappsrl/wm-package/commit/7eb3fb43d8ac297a68cbafe180ac00ba62396c3b))
* Edit field Action OC:4184 ([#21](https://github.com/webmappsrl/wm-package/issues/21)) ([c3a0f83](https://github.com/webmappsrl/wm-package/commit/c3a0f8371ffbde5d8f5bad90e384d83536c2a3f2))
* re implemented basejob class ([746b49f](https://github.com/webmappsrl/wm-package/commit/746b49f33a5e5c8d2be8eafdc4f75ee39e936a32))
* wm package enhancement ([#20](https://github.com/webmappsrl/wm-package/issues/20)) ([f939960](https://github.com/webmappsrl/wm-package/commit/f9399602086433e3875ffd733c83efccd5ee47b4))

## Changelog

All notable changes to `wm-package` will be documented in this file.
