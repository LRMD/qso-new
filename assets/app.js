/* app.js — QSO Awards map-first SPA (Vue 3 global build + MapLibre GL JS).
   No bundler: Vue and maplibregl are loaded as globals by the shell.
   All URLs are relative to <base href> so the app is location-independent. */
(function () {
  'use strict';
  var Vue = window.Vue, maplibregl = window.maplibregl;

  // ---- config ----------------------------------------------------------
  var MODES = ['wal', 'lhfa', 'lyff'];
  var DEFAULT_MODE = 'lhfa';
  var LT_CENTER = [23.9, 55.25], LT_ZOOM = 6.3;

  // Public, no-API-key basemaps that show natural features (parks, forests, water,
  // landuse, terrain) so hillforts/parks/squares have real geographic context.
  function rasterStyle(tiles, attribution, maxzoom) {
    return {
      version: 8,
      sources: { base: { type: 'raster', tiles: tiles, tileSize: 256, attribution: attribution, maxzoom: maxzoom || 19 } },
      layers: [{ id: 'base', type: 'raster', source: 'base' }]
    };
  }
  var BASEMAPS = {
    // OpenFreeMap — free vector tiles, no key/signup; rich landuse/parks/forests/water.
    streets: { label: 'Streets', style: 'https://tiles.openfreemap.org/styles/liberty' },
    bright:  { label: 'Bright',  style: 'https://tiles.openfreemap.org/styles/bright' },
    // OpenTopoMap — topographic raster: terrain + forests + protected areas (good for hillforts).
    topo:    { label: 'Topo',    style: rasterStyle(
      ['https://a.tile.opentopomap.org/{z}/{x}/{y}.png', 'https://b.tile.opentopomap.org/{z}/{x}/{y}.png', 'https://c.tile.opentopomap.org/{z}/{x}/{y}.png'],
      '© OpenStreetMap contributors, SRTM | © OpenTopoMap (CC-BY-SA)', 17) },
    // Plain OSM raster — everything rendered (parks, woods, water).
    osm:     { label: 'OSM',     style: rasterStyle(
      ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], '© OpenStreetMap contributors', 19) }
  };
  var DEFAULT_BASEMAP = 'streets';
  // RAG recency colouring, driven by `last_ts` (unix seconds of last activation):
  // ≤1 year → green, ~15 years → yellow, ≥30 years → red (smooth gradient);
  // never activated (no last_ts) → neutral gray. Age is computed live from "now".
  var NOW_SEC = Date.now() / 1000;
  var YEAR_SEC = 365.25 * 86400;
  var RAG_COLOR = ['case', ['has', 'last_ts'],
    ['interpolate', ['linear'],
      ['/', ['-', NOW_SEC, ['to-number', ['get', 'last_ts']]], YEAR_SEC],
      0, '#2fb344', 15, '#f4d03f', 30, '#e03131'],
    '#7b8794'];
  var ATTRIB = 'LYFF boundaries © OpenStreetMap contributors (ODbL)';

  // ---- i18n ------------------------------------------------------------
  var I18N = {
    lt: {
      title: 'Programos:', squares: 'skverai', hillforts: 'piliakalniai',
      parks: 'parkai, rezervatai', searchPh: 'Ieškoti aktyvatoriaus šaukinio…', search: 'Ieškoti',
      stats: 'Statistika', activators: 'aktyvatoriai', objects: 'objektai', coverage: 'Aktyvuota objektų',
      qsos: 'ryšiai (QSO)', hunters: 'medžiotojai', recent: 'Naujausi aktyvavimai', loadMore: 'Daugiau',
      breakdown: 'Pagal modą / diapazoną', byMode: 'Modos', byBand: 'Diapazonai', phone: 'PHONE', cw: 'CW', digi: 'DIGI',
      object: 'Objektas', activatedBy: 'Aktyvavo', date: 'Data', bands: 'Diapaz.', modes: 'Modos',
      first: 'Pirmas', last: 'Paskut.', nActivated: 'aktyvuota objektų', noResults: 'Nieko nerasta',
      lastUpdate: 'Atnaujinta', loading: 'Kraunama…', never: 'neaktyvuota', activated: 'aktyvuota',
      recentLeg: 'neseniai', total: 'iš viso', activations: 'aktyvavimai',
      recency: 'Paskutinis aktyvavimas', yr: 'm.'
    },
    en: {
      title: 'Amateur radio programmes', squares: 'squares', hillforts: 'hillforts',
      parks: 'parks, reserves', searchPh: 'Search activator callsign…', search: 'Search',
      stats: 'Statistics', activators: 'activators', objects: 'objects', coverage: 'Objects activated:',
      qsos: 'QSOs', hunters: 'hunters', recent: 'Recent activations', loadMore: 'Load more',
      breakdown: 'By mode / band', byMode: 'Modes', byBand: 'Bands', phone: 'PHONE', cw: 'CW', digi: 'DIGI',
      object: 'Object', activatedBy: 'Activated by', date: 'Date', bands: 'Bands', modes: 'Modes',
      first: 'First', last: 'Last', nActivated: 'objects activated', noResults: 'Nothing found',
      lastUpdate: 'Updated', loading: 'Loading…', never: 'not activated', activated: 'activated',
      recentLeg: 'recent', total: 'total', activations: 'activations',
      recency: 'Last activation', yr: 'y'
    }
  };

  // ---- helpers ---------------------------------------------------------
  var appBase = new URL(document.baseURI).pathname; // e.g. "/new/" or "/"

  function api(path) {
    return fetch('api/' + path, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); });
  }
  function modeName(t, mode) {
    return mode === 'wal' ? t('squares') : mode === 'lhfa' ? t('hillforts') : t('parks');
  }
  function bboxOf(geom) {
    var b = [Infinity, Infinity, -Infinity, -Infinity];
    (function walk(c) {
      if (typeof c[0] === 'number') {
        if (c[0] < b[0]) b[0] = c[0]; if (c[1] < b[1]) b[1] = c[1];
        if (c[0] > b[2]) b[2] = c[0]; if (c[1] > b[3]) b[3] = c[1];
      } else { c.forEach(walk); }
    })(geom.coordinates);
    return b;
  }

  var MODE_COLORS = { phone: '#4dabf7', cw: '#f59f00', digi: '#51cf66' };
  // Categorical palette for band slices (assigned by index).
  var PALETTE = ['#4dabf7', '#f59f00', '#51cf66', '#e8590c', '#9775fa', '#20c997',
    '#f06595', '#fab005', '#15aabf', '#7048e8', '#ff8787', '#82c91e', '#e64980',
    '#5c7cfa', '#fcc419', '#63e6be', '#a9e34b', '#ffa94d', '#748ffc'];
  // Pure-JS pie slices (32×32 viewBox, r=15). Returns [{d, color}]; empty if no data.
  function piePaths(parts) {
    var nz = parts.filter(function (p) { return p.value > 0; });
    var total = nz.reduce(function (s, p) { return s + p.value; }, 0);
    if (total <= 0) return [];
    if (nz.length === 1) {                                  // 100% — a single arc is degenerate
      return [{ d: 'M16,1 A15,15 0 1 0 16,31 A15,15 0 1 0 16,1 Z', color: nz[0].color }];
    }
    var a = -Math.PI / 2, out = [];
    nz.forEach(function (p) {
      var frac = p.value / total, a2 = a + frac * 2 * Math.PI, large = frac > 0.5 ? 1 : 0;
      out.push({ d: 'M16,16 L' + (16 + 15 * Math.cos(a)) + ',' + (16 + 15 * Math.sin(a)) +
        ' A15,15 0 ' + large + ' 1 ' + (16 + 15 * Math.cos(a2)) + ',' + (16 + 15 * Math.sin(a2)) + ' Z',
        color: p.color });
      a = a2;
    });
    return out;
  }

  var App = {
    setup: function () {
      var ref = Vue.ref, reactive = Vue.reactive, computed = Vue.computed;

      var mode = ref(DEFAULT_MODE);
      var lang = ref(localStorage.getItem('qso_lang') || document.documentElement.lang || 'lt');
      var stats = ref(null);
      var recent = reactive({ items: [], limit: 10, loading: false });
      var selected = ref(null);
      var search = reactive({ input: '', result: null, active: false });
      var lastUpdate = ref('');
      var mobileStats = ref(false);             // collapsed stats strip expanded? (mobile)
      var geojson = { features: [] };           // current mode's objects (non-reactive)
      var highlightCodes = ref([]);
      var basemap = ref(localStorage.getItem('qso_basemap') || DEFAULT_BASEMAP);
      if (!BASEMAPS[basemap.value]) basemap.value = DEFAULT_BASEMAP;

      var t = function (k) { return (I18N[lang.value] && I18N[lang.value][k]) || k; };
      var map = null, popup = null, currentGeomKind = 'polygon', initialized = false;

      // ---- routing ------------------------------------------------------
      function parseRoute() {
        var path = location.pathname;
        if (path.indexOf(appBase) === 0) path = path.slice(appBase.length);
        var seg = path.split('/').filter(Boolean);
        var m = MODES.indexOf(seg[0]) >= 0 ? seg[0] : DEFAULT_MODE;
        var code = seg[1] ? decodeURIComponent(seg[1]) : null;
        var op = new URLSearchParams(location.search).get('op');
        return { mode: m, code: code, op: op };
      }
      function pushUrl(replace) {
        var url = appBase + mode.value;
        if (selected.value) url += '/' + encodeURIComponent(selected.value.code);
        if (search.active && search.input) url += '?op=' + encodeURIComponent(search.input);
        url += location.hash || '';
        history[replace ? 'replaceState' : 'pushState']({}, '', url);
      }

      // ---- data ---------------------------------------------------------
      function loadStats() { api(mode.value + '/stats').then(function (d) { stats.value = d; }).catch(function () { stats.value = null; }); }
      function loadRecent() {
        recent.loading = true;
        api(mode.value + '/recent?limit=' + recent.limit).then(function (d) {
          recent.items = d.activations || []; recent.loading = false;
        }).catch(function () { recent.items = []; recent.loading = false; });
      }
      function loadObjects() {
        return api(mode.value + '/objects').then(function (fc) {
          geojson = fc && fc.features ? fc : { type: 'FeatureCollection', features: [] };
          setMapData();
        }).catch(function () { geojson = { type: 'FeatureCollection', features: [] }; setMapData(); });
      }
      function loadLastUpdate() { api('meta/last-update').then(function (d) { lastUpdate.value = d.last_update || ''; }).catch(function () {}); }

      function loadMode(m, opts) {
        opts = opts || {};
        mode.value = m;
        if (!opts.keepSelection) selected.value = null;
        if (!opts.keepSearch) { search.result = null; search.active = false; highlightCodes.value = []; }
        if (!opts.noPush) pushUrl(opts.replace);
        loadStats(); loadRecent();
        return loadObjects().then(function () {
          if (opts.code) selectObject(opts.code, { noPush: true, fit: true });
          else if (opts.op) { search.input = opts.op; doSearch(true); }
        });
      }

      function selectObject(code, opts) {
        opts = opts || {};
        return api(mode.value + '/object/' + encodeURIComponent(code)).then(function (d) {
          selected.value = d;
          updateHighlight();
          var f = geojson.features.find(function (x) { return x.properties.code === code; });
          if (f && map) flyToFeature(f, opts.fit);
          if (!opts.noPush) pushUrl();
        }).catch(function () {});
      }
      function closeDetail() { selected.value = null; updateHighlight(); pushUrl(); }

      function doSearch(noPush) {
        var call = (search.input || '').trim();
        if (!call) { clearSearch(); return; }
        api(mode.value + '/activator/' + encodeURIComponent(call)).then(function (d) {
          search.result = d; search.active = true;
          highlightCodes.value = (d.objects || []).map(function (o) { return o.code; });
          updateHighlight(); fitToHighlight();
          if (!noPush) pushUrl();
        }).catch(function () { search.result = { count: 0, objects: [], callsign: call }; search.active = true; });
      }
      function clearSearch() {
        search.input = ''; search.result = null; search.active = false;
        highlightCodes.value = []; updateHighlight(); pushUrl();
      }
      function searchInMode(m) { loadMode(m, { keepSearch: true }).then(function () { if (search.input) doSearch(); }); }

      // Run the activator search for a callsign (deep-links ?op=CALLSIGN).
      function openActivator(call) { selected.value = null; search.input = call; doSearch(); }
      // Real hrefs so the anchors are openable in a new tab / share-able.
      function objHref(code) { return appBase + mode.value + '/' + encodeURIComponent(code); }
      function actHref(call) { return appBase + mode.value + '?op=' + encodeURIComponent(call); }

      function setLang(l) { lang.value = l; localStorage.setItem('qso_lang', l); document.documentElement.lang = l; }

      // ---- map ----------------------------------------------------------
      var APP_LAYERS = ['obj-fill', 'obj-line', 'obj-pt', 'obj-hl'];
      function removeApp() {
        if (!map) return;
        APP_LAYERS.forEach(function (id) { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource('obj')) map.removeSource('obj');
      }
      function setMapData() {
        if (!map || !map.isStyleLoaded()) { if (map) map.once('idle', setMapData); return; }
        removeApp();
        currentGeomKind = (mode.value === 'lhfa') ? 'point' : 'polygon';
        if (currentGeomKind === 'point') {
          // Plain circles (native clustering drops these features on real data).
          map.addSource('obj', { type: 'geojson', data: geojson });
          map.addLayer({ id: 'obj-pt', type: 'circle', source: 'obj',
            paint: { 'circle-color': RAG_COLOR,
              'circle-radius': ['interpolate', ['linear'], ['zoom'], 6, 2.5, 10, 6, 14, 9],
              'circle-stroke-color': '#0b121a', 'circle-stroke-width': 0.6, 'circle-opacity': 0.9 } });
          map.addLayer({ id: 'obj-hl', type: 'circle', source: 'obj',
            filter: ['in', ['get', 'code'], ['literal', []]],
            paint: { 'circle-radius': 10, 'circle-color': 'rgba(0,0,0,0)', 'circle-stroke-color': '#ffd43b', 'circle-stroke-width': 3 } });
        } else {
          map.addSource('obj', { type: 'geojson', data: geojson });
          map.addLayer({ id: 'obj-fill', type: 'fill', source: 'obj',
            paint: { 'fill-color': RAG_COLOR, 'fill-opacity': 0.5 } });
          map.addLayer({ id: 'obj-line', type: 'line', source: 'obj',
            paint: { 'line-color': RAG_COLOR, 'line-width': 0.8 } });
          map.addLayer({ id: 'obj-hl', type: 'line', source: 'obj',
            filter: ['in', ['get', 'code'], ['literal', []]],
            paint: { 'line-color': '#ffd43b', 'line-width': 3 } });
        }
        updateHighlight();
      }
      function highlightSet() {
        var codes = highlightCodes.value.slice();
        if (selected.value && codes.indexOf(selected.value.code) < 0) codes.push(selected.value.code);
        return codes;
      }
      function updateHighlight() {
        if (!map || !map.getLayer('obj-hl')) return;
        var codes = highlightSet();
        map.setFilter('obj-hl', ['in', ['get', 'code'], ['literal', codes]]);
      }
      function flyToFeature(f, fit) {
        var b = bboxOf(f.geometry);
        if (f.geometry.type === 'Point' || !fit) {
          var c = f.geometry.type === 'Point' ? f.geometry.coordinates : [(b[0] + b[2]) / 2, (b[1] + b[3]) / 2];
          map.flyTo({ center: c, zoom: Math.max(map.getZoom(), 10), speed: 0.8 });
        } else {
          map.fitBounds([[b[0], b[1]], [b[2], b[3]]], { padding: 80, maxZoom: 12 });
        }
      }
      function fitToHighlight() {
        if (!map) return;
        var codes = highlightCodes.value, b = [Infinity, Infinity, -Infinity, -Infinity], any = false;
        geojson.features.forEach(function (f) {
          if (codes.indexOf(f.properties.code) < 0) return; any = true;
          var fb = bboxOf(f.geometry);
          if (fb[0] < b[0]) b[0] = fb[0]; if (fb[1] < b[1]) b[1] = fb[1];
          if (fb[2] > b[2]) b[2] = fb[2]; if (fb[3] > b[3]) b[3] = fb[3];
        });
        if (any) map.fitBounds([[b[0], b[1]], [b[2], b[3]]], { padding: 90, maxZoom: 11 });
      }

      function interactiveLayers() {
        return (currentGeomKind === 'point' ? ['obj-pt'] : ['obj-fill']).filter(function (id) { return map.getLayer(id); });
      }
      function onMove(e) {
        var fs = map.queryRenderedFeatures(e.point, { layers: interactiveLayers() });
        if (!fs.length) { map.getCanvas().style.cursor = ''; if (popup) { popup.remove(); popup = null; } return; }
        map.getCanvas().style.cursor = 'pointer';
        var f = fs[0];
        var p = f.properties;
        var html = '<div class="pc">' + p.code + '</div><div>' + (p.name || '') + '</div>' +
          '<div class="muted">' + (p.activators || 0) + ' ' + t('activators') + ' · ' + (p.qsos || 0) + ' QSO</div>' +
          (p.last_at ? '<div class="muted">' + t('last') + ': ' + p.last_at + '</div>' : '');
        if (!popup) popup = new maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 8 });
        popup.setLngLat(e.lngLat).setHTML(html).addTo(map);
      }
      function onClick(e) {
        var fs = map.queryRenderedFeatures(e.point, { layers: interactiveLayers() });
        if (!fs.length) return;
        selectObject(fs[0].properties.code);
      }

      function initMap() {
        map = new maplibregl.Map({ container: 'map', style: BASEMAPS[basemap.value].style, center: LT_CENTER, zoom: LT_ZOOM, attributionControl: false });
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        map.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: ATTRIB }));
        applyHashView();
        map.on('load', function () { initialized = true; loadMode(mode.value, { noPush: true, code: route0.code, op: route0.op }); });
        // After a basemap switch the style is replaced — re-add our overlay layers.
        map.on('style.load', function () { if (initialized) setMapData(); });
        map.on('mousemove', onMove);
        map.on('click', onClick);
        map.on('moveend', function () {
          var c = map.getCenter();
          location.hash = '#' + map.getZoom().toFixed(2) + '/' + c.lat.toFixed(4) + '/' + c.lng.toFixed(4);
        });
      }
      function setBasemap(key) {
        if (!BASEMAPS[key] || key === basemap.value || !map) return;
        basemap.value = key;
        localStorage.setItem('qso_basemap', key);
        map.setStyle(BASEMAPS[key].style, { diff: false }); // full replace → triggers style.load
      }
      function applyHashView() {
        var m = /#([\d.]+)\/(-?[\d.]+)\/(-?[\d.]+)/.exec(location.hash || '');
        if (m) { map.jumpTo({ zoom: +m[1], center: [+m[3], +m[2]] }); }
      }

      // ---- init ---------------------------------------------------------
      var route0 = parseRoute();
      mode.value = route0.mode;
      document.documentElement.lang = lang.value;

      Vue.onMounted(function () {
        initMap();
        window.addEventListener('popstate', function () {
          var r = parseRoute();
          if (r.mode !== mode.value) loadMode(r.mode, { noPush: true, code: r.code, op: r.op });
          else if (r.code && (!selected.value || selected.value.code !== r.code)) selectObject(r.code, { noPush: true, fit: true });
          else if (!r.code && selected.value) { selected.value = null; updateHighlight(); }
        });
        // Map box changes when the device rotates / the layout reflows — refit it.
        window.addEventListener('orientationchange', function () { setTimeout(function () { if (map) map.resize(); }, 300); });
      });
      loadLastUpdate();

      // ---- computed for template ---------------------------------------
      var coveragePct = computed(function () { return stats.value && stats.value.coverage != null ? Math.round(stats.value.coverage * 100) : null; });

      // --- stats charts (tabbed pies: by band / by mode) ---
      var statTab = ref('band');
      var modeParts = computed(function () {
        var bm = (stats.value && stats.value.by_mode) || { phone: 0, cw: 0, digi: 0 };
        return [
          { key: 'phone', label: t('phone'), value: bm.phone || 0, color: MODE_COLORS.phone },
          { key: 'cw',    label: t('cw'),    value: bm.cw    || 0, color: MODE_COLORS.cw },
          { key: 'digi',  label: t('digi'),  value: bm.digi  || 0, color: MODE_COLORS.digi }
        ];
      });
      var bandParts = computed(function () {
        return ((stats.value && stats.value.by_band) || []).map(function (b, i) {
          return { key: b.band, label: b.band, value: b.qsos || 0, color: PALETTE[i % PALETTE.length] };
        });
      });
      var modeSlices = computed(function () { return piePaths(modeParts.value); });
      var bandSlices = computed(function () { return piePaths(bandParts.value); });
      // Band legend shows the top 3 by QSO count; the rest appear on pie hover.
      var bandSorted = computed(function () {
        return bandParts.value.slice().sort(function (a, b) { return b.value - a.value; });
      });
      var bandTop3 = computed(function () { return bandSorted.value.slice(0, 3); });

      return {
        MODES: MODES, mode: mode, lang: lang, t: t, modeName: function (m) { return modeName(t, m); },
        stats: stats, recent: recent, selected: selected, search: search, lastUpdate: lastUpdate,
        coveragePct: coveragePct, mobileStats: mobileStats,
        statTab: statTab, modeParts: modeParts, modeSlices: modeSlices,
        bandSlices: bandSlices, bandTop3: bandTop3, bandSorted: bandSorted,
        BASEMAPS: BASEMAPS, basemap: basemap, setBasemap: setBasemap,
        switchMode: function (m) { if (m !== mode.value) loadMode(m); },
        setLang: setLang, doSearch: function () { doSearch(); }, clearSearch: clearSearch,
        selectObject: function (c) { selectObject(c); }, closeDetail: closeDetail, searchInMode: searchInMode,
        openActivator: openActivator, objHref: objHref, actHref: actHref,
        moreRecent: function () { recent.limit = Math.min(50, recent.limit + 10); loadRecent(); }
      };
    },
    template: [
      '<div class="layout">',
      '  <header class="topbar">',
      '    <div class="brand">QSO<small>{{ t("title") }}</small></div>',
      '    <nav class="modes">',
      '      <button v-for="m in MODES" :key="m" :class="{active: m===mode}" @click="switchMode(m)">{{ m.toUpperCase() }}</button>',
      '    </nav>',
      '    <form class="search" @submit.prevent="doSearch">',
      '      <input v-model="search.input" :placeholder="t(\'searchPh\')" autocomplete="off" spellcheck="false">',
      '      <button type="submit">{{ t("search") }}</button>',
      '    </form>',
      '    <div class="spacer"></div>',
      '    <select class="basemap" title="Map" :value="basemap" @change="setBasemap($event.target.value)">',
      '      <option v-for="(b,k) in BASEMAPS" :key="k" :value="k">{{ b.label }}</option>',
      '    </select>',
      '    <div class="lang">',
      '      <button :class="{active: lang===\'lt\'}" @click="setLang(\'lt\')">LT</button>',
      '      <button :class="{active: lang===\'en\'}" @click="setLang(\'en\')">EN</button>',
      '    </div>',
      '  </header>',
      '  <div class="stage">',
      '    <div id="map"></div>',
      '    <div class="rail">',
      '      <section class="card stats" :class="{open: mobileStats}">',
      '        <button class="stats-strip" v-if="stats" @click="mobileStats=!mobileStats">',
      '          <span><b>{{ stats.activators }}</b> {{ t("activators") }}</span>',
      '          <span><b>{{ stats.objects_activated }}</b>/{{ stats.objects_total }} {{ t("objects") }}</span>',
      '          <span class="chev">{{ mobileStats ? "▴" : "▾" }}</span>',
      '        </button>',
      '        <h3 class="stats-h3">{{ t("stats") }} — {{ mode.toUpperCase() }} {{ modeName(mode) }}</h3>',
      '        <div class="body" v-if="stats">',
      '          <div class="stats-grid">',
      '            <div class="stat"><div class="n">{{ stats.activators }}</div><div class="l">{{ t("activators") }}</div></div>',
      '            <div class="stat"><div class="n">{{ stats.objects_activated }}<span class="muted" style="font-size:13px">/{{ stats.objects_total }}</span></div><div class="l">{{ t("objects") }}</div></div>',
      '            <div class="stat"><div class="n">{{ stats.qsos }}</div><div class="l">{{ t("qsos") }}</div></div>',
      '            <div class="stat"><div class="n">{{ stats.hunters.ly }}<span class="muted">·</span>{{ stats.hunters.dx }}</div><div class="l">LY · DX {{ t("hunters") }}</div></div>',
      '          </div>',
      '          <div v-if="coveragePct!=null">',
      '            <div class="kv"><span class="muted">{{ t("coverage") }}</span><span>{{ coveragePct }}%</span></div>',
      '            <div class="coverage-bar"><i :style="{width: coveragePct+\'%\'}"></i></div>',
      '          </div>',
      '          <div class="charts">',
      '            <div class="tabs">',
      '              <button :class="{active: statTab===\'band\'}" @click="statTab=\'band\'">{{ t("byBand") }}</button>',
      '              <button :class="{active: statTab===\'mode\'}" @click="statTab=\'mode\'">{{ t("byMode") }}</button>',
      '            </div>',
      '            <div v-show="statTab===\'band\'" class="chart">',
      '              <div v-if="bandSlices.length" class="pie-wrap">',
      '                <svg viewBox="0 0 32 32" class="pie"><path v-for="s in bandSlices" :key="s.color" :d="s.d" :fill="s.color"/></svg>',
      '                <div class="pie-pop"><span v-for="p in bandSorted" :key="p.key"><i class="sw" :style="{background:p.color}"></i>{{ p.label }} · {{ p.value }}</span></div>',
      '              </div>',
      '              <div v-else class="empty">{{ t("noResults") }}</div>',
      '              <div class="pielegend"><span v-for="p in bandTop3" :key="p.key"><i class="sw" :style="{background:p.color}"></i>{{ p.label }} · {{ p.value }}</span></div>',
      '            </div>',
      '            <div v-show="statTab===\'mode\'" class="chart">',
      '              <svg v-if="modeSlices.length" viewBox="0 0 32 32" class="pie"><path v-for="s in modeSlices" :key="s.color" :d="s.d" :fill="s.color"/></svg>',
      '              <div v-else class="empty">{{ t("noResults") }}</div>',
      '              <div class="pielegend"><span v-for="p in modeParts" :key="p.key"><i class="sw" :style="{background:p.color}"></i>{{ p.label }} · {{ p.value }}</span></div>',
      '            </div>',
      '          </div>',
      '          <div class="recency-leg">',
      '            <div class="muted" style="font-size:11px">{{ t("recency") }}</div>',
      '            <div class="ragbar"></div>',
      '            <div class="raglabels"><span>&lt;1{{ t("yr") }}</span><span>15{{ t("yr") }}</span><span>30+{{ t("yr") }}</span></div><div><span><i class="dot never"></i>{{ t("never") }}</span></div>',
      '          </div>',
      '        </div>',
      '        <div class="spin" v-else>{{ t("loading") }}</div>',
      '      </section>',
      '      <section class="card scroll" style="flex:1; min-height:120px" v-if="!search.active">',
      '        <h3>{{ t("recent") }}</h3>',
      '        <div class="list">',
      '          <div class="item" v-for="(a,i) in recent.items" :key="i">',
      '            <div class="row1">',
      '              <a class="code" :href="objHref(a.code)" @click.prevent="selectObject(a.code)">{{ a.code }}</a>',
      '              <a class="who" :href="actHref(a.activator)" @click.prevent="openActivator(a.activator)">{{ a.activator }}</a>',
      '            </div>',
      '            <div class="meta"><span>{{ a.date }}</span><span class="chip">{{ a.qsos }} QSO</span><span v-for="b in a.bands" :key="b" class="chip">{{ b }}</span></div>',
      '          </div>',
      '          <div class="empty" v-if="!recent.items.length && !recent.loading">{{ t("noResults") }}</div>',
      '          <div class="spin" v-if="recent.loading">{{ t("loading") }}</div>',
      '        </div>',
      '        <div class="body" v-if="recent.items.length>=recent.limit"><button class="pill" @click="moreRecent">{{ t("loadMore") }}</button></div>',
      '      </section>',
      '      <section class="card scroll" style="flex:1; min-height:120px" v-else>',
      '        <h3>{{ search.result ? search.result.callsign : search.input }}',
      '          <span class="muted"> · {{ search.result ? search.result.count : 0 }} {{ t("nActivated") }}</span>',
      '          <button class="close" style="float:right" @click="clearSearch">×</button></h3>',
      '        <div class="body">',
      '          <div class="legend">',
      '            <button v-for="m in MODES" :key="m" class="pill" :class="{active:m===mode}" @click="searchInMode(m)">{{ m.toUpperCase() }}</button>',
      '          </div>',
      '        </div>',
      '        <div class="list" v-if="search.result && search.result.objects.length">',
      '          <button class="item" v-for="o in search.result.objects" :key="o.code" @click="selectObject(o.code)">',
      '            <div class="row1"><span class="code">{{ o.code }}</span><span class="chip">{{ o.qsos }} QSO</span></div>',
      '            <div class="meta"><span>{{ t("first") }}: {{ o.first }}</span><span>{{ t("last") }}: {{ o.last }}</span></div>',
      '          </button>',
      '        </div>',
      '        <div class="empty" v-else>{{ t("noResults") }}</div>',
      '      </section>',
      '    </div>',
      '    <section class="card detail" v-if="selected">',
      '      <div class="body">',
      '        <div class="head">',
      '          <div><div class="title">{{ selected.code }}</div><div class="sub">{{ selected.activators }} {{ t("activators") }} · {{ selected.qsos }} QSO</div></div>',
      '          <button class="close" @click="closeDetail">×</button>',
      '        </div>',
      '      </div>',
      '      <div class="scroll" style="max-height:48vh">',
      '        <table class="table">',
      '          <thead><tr><th>{{ t("activatedBy") }}</th><th>{{ t("date") }}</th><th>QSO</th><th>{{ t("bands") }}</th></tr></thead>',
      '          <tbody>',
      '            <tr v-for="(a,i) in selected.activations" :key="i">',
      '              <td><a class="who" :href="actHref(a.activator)" @click.prevent="openActivator(a.activator)">{{ a.activator }}</a></td><td>{{ a.date }}</td><td>{{ a.qsos }}</td><td>{{ a.bands.join(", ") }}</td>',
      '            </tr>',
      '          </tbody>',
      '        </table>',
      '        <div class="empty" v-if="!selected.activations.length">{{ t("noResults") }}</div>',
      '      </div>',
      '    </section>',
      '    <div class="footer" v-if="lastUpdate">{{ t("lastUpdate") }}: {{ lastUpdate }}</div>',
      '  </div>',
      '</div>'
    ].join('\n')
  };

  Vue.createApp(App).mount('#app');
})();
