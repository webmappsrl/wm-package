/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=script&lang=js":
/*!*****************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=script&lang=js ***!
  \*****************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
  data: function data() {
    return {
      iconSvg: null,
      iconsLoaded: false
    };
  },
  computed: {
    fieldValue: function fieldValue() {
      return this.field.displayedAs || this.field.value || '';
    }
  },
  mounted: function mounted() {
    this.loadIconSvg();
  },
  methods: {
    loadIconSvg: function loadIconSvg() {
      var _this = this;
      return _asyncToGenerator(/*#__PURE__*/_regenerator().m(function _callee() {
        var response, iconsData, icon, svgPaths, _t;
        return _regenerator().w(function (_context) {
          while (1) switch (_context.p = _context.n) {
            case 0:
              if (!(!_this.fieldValue || _this.iconsLoaded)) {
                _context.n = 1;
                break;
              }
              return _context.a(2);
            case 1:
              _context.p = 1;
              _context.n = 2;
              return Nova.request().get('/nova-vendor/wm/icon-select/icons');
            case 2:
              response = _context.v;
              iconsData = response.data;
              if (iconsData && iconsData.icons) {
                icon = iconsData.icons.find(function (i) {
                  return i.properties && i.properties.name === _this.fieldValue;
                });
                if (icon && icon.icon && icon.icon.paths) {
                  // Crea l'SVG dai paths
                  svgPaths = '';
                  icon.icon.paths.forEach(function (path) {
                    svgPaths += "<path d=\"".concat(path, "\"></path>");
                  });
                  _this.iconSvg = svgPaths;
                }
              }
              _this.iconsLoaded = true;
              _context.n = 4;
              break;
            case 3:
              _context.p = 3;
              _t = _context.v;
              console.warn('Errore nel caricamento icone:', _t);
              _this.iconsLoaded = true;
            case 4:
              return _context.a(2);
          }
        }, _callee, null, [[1, 3]]);
      }))();
    }
  }
});

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=script&lang=js":
/*!***************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=script&lang=js ***!
  \***************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var laravel_nova__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! laravel-nova */ "laravel-nova");
/* harmony import */ var laravel_nova__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(laravel_nova__WEBPACK_IMPORTED_MODULE_0__);

/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  mixins: [laravel_nova__WEBPACK_IMPORTED_MODULE_0__.FormField, laravel_nova__WEBPACK_IMPORTED_MODULE_0__.HandlesValidationErrors],
  props: ['resourceName', 'resourceId', 'field'],
  data: function data() {
    return {
      searchQuery: '',
      showDropdown: false,
      highlightedIndex: -1,
      selectedItems: [],
      filteredOptions: []
    };
  },
  computed: {
    options: function options() {
      return this.field.options || [];
    },
    searchPlaceholder: function searchPlaceholder() {
      return this.field.searchPlaceholder || 'Cerca un\'icona...';
    },
    isMultiple: function isMultiple() {
      return this.field.multiple || false;
    },
    maxItems: function maxItems() {
      return this.field.maxItems || null;
    },
    iconClass: function iconClass() {
      return this.field.iconClass || 'fas';
    },
    formValue: function formValue() {
      if (this.isMultiple) {
        return this.selectedItems.map(function (item) {
          return item.value;
        }).join(',');
      }
      return this.selectedItems.length > 0 ? this.selectedItems[0].value : '';
    }
  },
  mounted: function mounted() {
    this.filteredOptions = this.options;
    document.addEventListener('click', this.handleClickOutside);
  },
  beforeUnmount: function beforeUnmount() {
    document.removeEventListener('click', this.handleClickOutside);
  },
  methods: {
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue: function setInitialValue() {
      var value = this.field.value || '';
      if (value) {
        if (this.isMultiple) {
          var values = value.split(',');
          this.selectedItems = this.options.filter(function (option) {
            return values.includes(option.value);
          });
        } else {
          var selectedOption = this.options.find(function (option) {
            return option.value === value;
          });
          if (selectedOption) {
            this.selectedItems = [selectedOption];
          }
        }
      }
      this.value = value;
    },
    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill: function fill(formData) {
      formData.append(this.fieldAttribute, this.formValue);
    },
    filterOptions: function filterOptions() {
      var _this = this;
      if (!this.searchQuery) {
        this.filteredOptions = this.options;
      } else {
        this.filteredOptions = this.options.filter(function (option) {
          return option.label.toLowerCase().includes(_this.searchQuery.toLowerCase()) || option.value.toLowerCase().includes(_this.searchQuery.toLowerCase());
        });
      }
      this.highlightedIndex = -1;
    },
    selectOption: function selectOption(option) {
      if (this.isMultiple) {
        // Check if already selected
        if (!this.selectedItems.find(function (item) {
          return item.value === option.value;
        })) {
          // Check max items limit
          if (!this.maxItems || this.selectedItems.length < this.maxItems) {
            this.selectedItems.push(option);
          }
        }
      } else {
        this.selectedItems = [option];
        this.showDropdown = false;
      }
      this.searchQuery = '';
      this.filteredOptions = this.options;
      this.value = this.formValue;
    },
    removeItem: function removeItem(item) {
      this.selectedItems = this.selectedItems.filter(function (selected) {
        return selected.value !== item.value;
      });
      this.value = this.formValue;
    },
    handleKeydown: function handleKeydown(event) {
      if (!this.showDropdown) return;
      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();
          this.highlightedIndex = Math.min(this.highlightedIndex + 1, this.filteredOptions.length - 1);
          break;
        case 'ArrowUp':
          event.preventDefault();
          this.highlightedIndex = Math.max(this.highlightedIndex - 1, -1);
          break;
        case 'Enter':
          event.preventDefault();
          if (this.highlightedIndex >= 0 && this.filteredOptions[this.highlightedIndex]) {
            this.selectOption(this.filteredOptions[this.highlightedIndex]);
          }
          break;
        case 'Escape':
          this.showDropdown = false;
          this.searchQuery = '';
          this.filteredOptions = this.options;
          break;
      }
    },
    handleClickOutside: function handleClickOutside(event) {
      if (!this.$el.contains(event.target)) {
        this.showDropdown = false;
      }
    }
  }
});

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=script&lang=js":
/*!****************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=script&lang=js ***!
  \****************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  props: ['resourceName', 'field'],
  computed: {
    fieldValue: function fieldValue() {
      return this.field.displayedAs || this.field.value || '';
    },
    options: function options() {
      return this.field.options || [];
    },
    iconClass: function iconClass() {
      return this.field.iconClass || 'fas';
    },
    selectedIcons: function selectedIcons() {
      var _this = this;
      if (!this.fieldValue) return [];
      var isMultiple = this.field.multiple || false;
      if (isMultiple) {
        var values = this.fieldValue.split(',').filter(function (v) {
          return v;
        });
        return this.options.filter(function (option) {
          return values.includes(option.value);
        });
      } else {
        var selectedOption = this.options.find(function (option) {
          return option.value === _this.fieldValue;
        });
        return selectedOption ? [selectedOption] : [];
      }
    }
  }
});

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/PreviewField.vue?vue&type=script&lang=js":
/*!******************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/PreviewField.vue?vue&type=script&lang=js ***!
  \******************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _DetailField__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./DetailField */ "./resources/js/components/DetailField.vue");

/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ({
  "extends": _DetailField__WEBPACK_IMPORTED_MODULE_0__["default"]
});

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=template&id=0224618e":
/*!*********************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=template&id=0224618e ***!
  \*********************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "vue");
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(vue__WEBPACK_IMPORTED_MODULE_0__);
function _toConsumableArray(r) { return _arrayWithoutHoles(r) || _iterableToArray(r) || _unsupportedIterableToArray(r) || _nonIterableSpread(); }
function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }
function _unsupportedIterableToArray(r, a) { if (r) { if ("string" == typeof r) return _arrayLikeToArray(r, a); var t = {}.toString.call(r).slice(8, -1); return "Object" === t && r.constructor && (t = r.constructor.name), "Map" === t || "Set" === t ? Array.from(r) : "Arguments" === t || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t) ? _arrayLikeToArray(r, a) : void 0; } }
function _iterableToArray(r) { if ("undefined" != typeof Symbol && null != r[Symbol.iterator] || null != r["@@iterator"]) return Array.from(r); }
function _arrayWithoutHoles(r) { if (Array.isArray(r)) return _arrayLikeToArray(r); }
function _arrayLikeToArray(r, a) { (null == a || a > r.length) && (a = r.length); for (var e = 0, n = Array(a); e < a; e++) n[e] = r[e]; return n; }

var _hoisted_1 = ["dusk"];
var _hoisted_2 = {
  "class": "md:w-1/4 @sm/peekable:w-1/4 @md/modal:w-1/4 md:py-3 @sm/peekable:py-3 @md/modal:py-3"
};
var _hoisted_3 = {
  "class": "font-normal @sm/peekable:break-all"
};
var _hoisted_4 = {
  "class": "break-all md:w-3/4 @sm/peekable:w-3/4 @md/modal:w-3/4 md:py-3 @sm/peekable:py-3 md/modal:py-3 lg:break-words @md/peekable:break-words @lg/modal:break-words"
};
var _hoisted_5 = {
  key: 0,
  "class": "flex items-center"
};
var _hoisted_6 = {
  key: 0,
  "class": "w-5 h-5 mr-2 flex-shrink-0",
  viewBox: "0 0 1024 1024",
  fill: "currentColor"
};
var _hoisted_7 = ["innerHTML"];
var _hoisted_8 = {
  key: 1,
  "class": "flex items-center"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
    "class": "flex flex-col -mx-6 px-6 py-2 space-y-2 md:flex-row @sm/peekable:flex-row @md/modal:flex-row md:py-0 @sm/peekable:py-0 @md/modal:py-0 md:space-y-0 @sm/peekable:space-y-0 @md/modal:space-y-0",
    dusk: $props.field.attribute
  }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Label a sinistra "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_2, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("h4", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($props.field.name), 1 /* TEXT */)])]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Valore a destra "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_4, [$options.fieldValue ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_5, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Icona SVG "), $data.iconSvg ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_6, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("g", {
    innerHTML: $data.iconSvg
  }, null, 8 /* PROPS */, _hoisted_7)])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Nome dell'icona "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)($options.fieldValue), 1 /* TEXT */)])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("p", _hoisted_8, _toConsumableArray(_cache[0] || (_cache[0] = [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", {
    "class": "text-gray-400"
  }, "—", -1 /* CACHED */)]))))])], 8 /* PROPS */, _hoisted_1);
}

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=template&id=c023248a":
/*!*******************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=template&id=c023248a ***!
  \*******************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "vue");
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(vue__WEBPACK_IMPORTED_MODULE_0__);

var _hoisted_1 = {
  "class": "relative"
};
var _hoisted_2 = ["id", "placeholder"];
var _hoisted_3 = {
  key: 0,
  "class": "mt-2 flex flex-wrap gap-2"
};
var _hoisted_4 = {
  key: 0,
  "class": "w-4 h-4 mr-1",
  viewBox: "0 0 1024 1024",
  fill: "currentColor"
};
var _hoisted_5 = ["innerHTML"];
var _hoisted_6 = ["onClick"];
var _hoisted_7 = {
  "class": "absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg",
  style: {
    "max-height": "240px",
    "overflow": "hidden"
  }
};
var _hoisted_8 = {
  "class": "overflow-y-auto",
  style: {
    "max-height": "240px",
    "-webkit-overflow-scrolling": "touch"
  }
};
var _hoisted_9 = ["onClick"];
var _hoisted_10 = {
  key: 0,
  "class": "w-5 h-5 mr-2 flex-shrink-0",
  viewBox: "0 0 1024 1024",
  fill: "currentColor"
};
var _hoisted_11 = ["innerHTML"];
var _hoisted_12 = ["id", "name", "value"];
function render(_ctx, _cache, $props, $setup, $data, $options) {
  var _component_DefaultField = (0,vue__WEBPACK_IMPORTED_MODULE_0__.resolveComponent)("DefaultField");
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createBlock)(_component_DefaultField, {
    field: $props.field,
    errors: _ctx.errors,
    "show-help-text": _ctx.showHelpText,
    "full-width-content": _ctx.fullWidthContent
  }, {
    field: (0,vue__WEBPACK_IMPORTED_MODULE_0__.withCtx)(function () {
      return [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_1, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Search Input "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
        id: $props.field.attribute + '_search',
        ref: "searchInput",
        type: "text",
        "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(["w-full form-control form-input form-control-bordered", _ctx.errorClasses]),
        placeholder: $options.searchPlaceholder,
        "onUpdate:modelValue": _cache[0] || (_cache[0] = function ($event) {
          return $data.searchQuery = $event;
        }),
        onFocus: _cache[1] || (_cache[1] = function ($event) {
          return $data.showDropdown = true;
        }),
        onInput: _cache[2] || (_cache[2] = function () {
          return $options.filterOptions && $options.filterOptions.apply($options, arguments);
        }),
        onKeydown: _cache[3] || (_cache[3] = function () {
          return $options.handleKeydown && $options.handleKeydown.apply($options, arguments);
        }),
        autocomplete: "off"
      }, null, 42 /* CLASS, PROPS, NEED_HYDRATION */, _hoisted_2), [[vue__WEBPACK_IMPORTED_MODULE_0__.vModelText, $data.searchQuery]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Selected Icons Display "), $data.selectedItems.length > 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_3, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.selectedItems, function (item) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
          key: item.value,
          "class": "inline-flex items-center px-2 py-1 bg-primary-100 text-primary-800 text-sm rounded-md"
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" SVG Icon "), item.svg ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_4, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("g", {
          innerHTML: item.svg
        }, null, 8 /* PROPS */, _hoisted_5)])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
          key: 1
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Font Awesome Icon (fallback) "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([[$options.iconClass, item.icon], "mr-1"])
        }, null, 2 /* CLASS */)], 2112 /* STABLE_FRAGMENT, DEV_ROOT_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(item.label), 1 /* TEXT */), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("button", {
          type: "button",
          onClick: function onClick($event) {
            return $options.removeItem(item);
          },
          "class": "ml-1 text-primary-600 hover:text-primary-800"
        }, " × ", 8 /* PROPS */, _hoisted_6)]);
      }), 128 /* KEYED_FRAGMENT */))])) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Dropdown "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.withDirectives)((0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_7, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("div", _hoisted_8, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($data.filteredOptions, function (option, index) {
        return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
          key: option.value,
          onClick: function onClick($event) {
            return $options.selectOption(option);
          },
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)(['px-3 py-2 cursor-pointer flex items-center', index === $data.highlightedIndex ? 'bg-primary-100' : 'hover:bg-gray-100'])
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" SVG Icon "), option.svg ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_10, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("g", {
          innerHTML: option.svg
        }, null, 8 /* PROPS */, _hoisted_11)])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
          key: 1
        }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Font Awesome Icon (fallback) "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
          "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([[$options.iconClass, option.icon], "mr-2"])
        }, null, 2 /* CLASS */)], 2112 /* STABLE_FRAGMENT, DEV_ROOT_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(option.label), 1 /* TEXT */)], 10 /* CLASS, PROPS */, _hoisted_9);
      }), 128 /* KEYED_FRAGMENT */))])], 512 /* NEED_PATCH */), [[vue__WEBPACK_IMPORTED_MODULE_0__.vShow, $data.showDropdown && $data.filteredOptions.length > 0]]), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Hidden input for form submission "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("input", {
        id: $props.field.attribute,
        type: "hidden",
        name: $props.field.attribute,
        value: $options.formValue
      }, null, 8 /* PROPS */, _hoisted_12)])];
    }),
    _: 1 /* STABLE */
  }, 8 /* PROPS */, ["field", "errors", "show-help-text", "full-width-content"]);
}

/***/ }),

/***/ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a":
/*!********************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** ./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a ***!
  \********************************************************************************************************************************************************************************************************************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* binding */ render)
/* harmony export */ });
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! vue */ "vue");
/* harmony import */ var vue__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(vue__WEBPACK_IMPORTED_MODULE_0__);

var _hoisted_1 = {
  "class": "flex flex-wrap gap-1"
};
var _hoisted_2 = ["title"];
var _hoisted_3 = {
  key: 0,
  "class": "w-4 h-4 mr-1 flex-shrink-0",
  viewBox: "0 0 1024 1024",
  fill: "currentColor"
};
var _hoisted_4 = ["innerHTML"];
var _hoisted_5 = {
  "class": "truncate max-w-20"
};
var _hoisted_6 = {
  key: 0,
  "class": "text-gray-400 text-sm"
};
function render(_ctx, _cache, $props, $setup, $data, $options) {
  return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", _hoisted_1, [((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(true), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, null, (0,vue__WEBPACK_IMPORTED_MODULE_0__.renderList)($options.selectedIcons, function (icon) {
    return (0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("div", {
      key: icon.value,
      "class": "inline-flex items-center px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded",
      title: icon.label
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" SVG Icon "), icon.svg ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("svg", _hoisted_3, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("g", {
      innerHTML: icon.svg
    }, null, 8 /* PROPS */, _hoisted_4)])) : ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)(vue__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
      key: 1
    }, [(0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)(" Font Awesome Icon (fallback) "), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("i", {
      "class": (0,vue__WEBPACK_IMPORTED_MODULE_0__.normalizeClass)([[$options.iconClass, icon.icon], "mr-1"])
    }, null, 2 /* CLASS */)], 2112 /* STABLE_FRAGMENT, DEV_ROOT_FRAGMENT */)), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementVNode)("span", _hoisted_5, (0,vue__WEBPACK_IMPORTED_MODULE_0__.toDisplayString)(icon.label), 1 /* TEXT */)], 8 /* PROPS */, _hoisted_2);
  }), 128 /* KEYED_FRAGMENT */)), $options.selectedIcons.length === 0 ? ((0,vue__WEBPACK_IMPORTED_MODULE_0__.openBlock)(), (0,vue__WEBPACK_IMPORTED_MODULE_0__.createElementBlock)("span", _hoisted_6, " Nessuna icona selezionata ")) : (0,vue__WEBPACK_IMPORTED_MODULE_0__.createCommentVNode)("v-if", true)]);
}

/***/ }),

/***/ "./node_modules/vue-loader/dist/exportHelper.js":
/*!******************************************************!*\
  !*** ./node_modules/vue-loader/dist/exportHelper.js ***!
  \******************************************************/
/***/ ((__unused_webpack_module, exports) => {


Object.defineProperty(exports, "__esModule", ({ value: true }));
// runtime helper for setting properties on components
// in a tree-shakable way
exports["default"] = (sfc, props) => {
    const target = sfc.__vccOpts || sfc;
    for (const [key, val] of props) {
        target[key] = val;
    }
    return target;
};


/***/ }),

/***/ "./resources/css/field.css":
/*!*********************************!*\
  !*** ./resources/css/field.css ***!
  \*********************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ }),

/***/ "./resources/js/components/DetailField.vue":
/*!*************************************************!*\
  !*** ./resources/js/components/DetailField.vue ***!
  \*************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _DetailField_vue_vue_type_template_id_0224618e__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./DetailField.vue?vue&type=template&id=0224618e */ "./resources/js/components/DetailField.vue?vue&type=template&id=0224618e");
/* harmony import */ var _DetailField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./DetailField.vue?vue&type=script&lang=js */ "./resources/js/components/DetailField.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_DetailField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_DetailField_vue_vue_type_template_id_0224618e__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/DetailField.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/components/DetailField.vue?vue&type=script&lang=js":
/*!*************************************************************************!*\
  !*** ./resources/js/components/DetailField.vue?vue&type=script&lang=js ***!
  \*************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_DetailField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_DetailField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./DetailField.vue?vue&type=script&lang=js */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/DetailField.vue?vue&type=template&id=0224618e":
/*!*******************************************************************************!*\
  !*** ./resources/js/components/DetailField.vue?vue&type=template&id=0224618e ***!
  \*******************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_DetailField_vue_vue_type_template_id_0224618e__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_DetailField_vue_vue_type_template_id_0224618e__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./DetailField.vue?vue&type=template&id=0224618e */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/DetailField.vue?vue&type=template&id=0224618e");


/***/ }),

/***/ "./resources/js/components/FormField.vue":
/*!***********************************************!*\
  !*** ./resources/js/components/FormField.vue ***!
  \***********************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _FormField_vue_vue_type_template_id_c023248a__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./FormField.vue?vue&type=template&id=c023248a */ "./resources/js/components/FormField.vue?vue&type=template&id=c023248a");
/* harmony import */ var _FormField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./FormField.vue?vue&type=script&lang=js */ "./resources/js/components/FormField.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_FormField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_FormField_vue_vue_type_template_id_c023248a__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/FormField.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/components/FormField.vue?vue&type=script&lang=js":
/*!***********************************************************************!*\
  !*** ./resources/js/components/FormField.vue?vue&type=script&lang=js ***!
  \***********************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_FormField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_FormField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./FormField.vue?vue&type=script&lang=js */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/FormField.vue?vue&type=template&id=c023248a":
/*!*****************************************************************************!*\
  !*** ./resources/js/components/FormField.vue?vue&type=template&id=c023248a ***!
  \*****************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_FormField_vue_vue_type_template_id_c023248a__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_FormField_vue_vue_type_template_id_c023248a__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./FormField.vue?vue&type=template&id=c023248a */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/FormField.vue?vue&type=template&id=c023248a");


/***/ }),

/***/ "./resources/js/components/IndexField.vue":
/*!************************************************!*\
  !*** ./resources/js/components/IndexField.vue ***!
  \************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _IndexField_vue_vue_type_template_id_9e63f81a__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./IndexField.vue?vue&type=template&id=9e63f81a */ "./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a");
/* harmony import */ var _IndexField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./IndexField.vue?vue&type=script&lang=js */ "./resources/js/components/IndexField.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");




;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_2__["default"])(_IndexField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_1__["default"], [['render',_IndexField_vue_vue_type_template_id_9e63f81a__WEBPACK_IMPORTED_MODULE_0__.render],['__file',"resources/js/components/IndexField.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/components/IndexField.vue?vue&type=script&lang=js":
/*!************************************************************************!*\
  !*** ./resources/js/components/IndexField.vue?vue&type=script&lang=js ***!
  \************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IndexField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IndexField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IndexField.vue?vue&type=script&lang=js */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a":
/*!******************************************************************************!*\
  !*** ./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a ***!
  \******************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   render: () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IndexField_vue_vue_type_template_id_9e63f81a__WEBPACK_IMPORTED_MODULE_0__.render)
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_templateLoader_js_ruleSet_1_rules_2_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_IndexField_vue_vue_type_template_id_9e63f81a__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./IndexField.vue?vue&type=template&id=9e63f81a */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/templateLoader.js??ruleSet[1].rules[2]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/IndexField.vue?vue&type=template&id=9e63f81a");


/***/ }),

/***/ "./resources/js/components/PreviewField.vue":
/*!**************************************************!*\
  !*** ./resources/js/components/PreviewField.vue ***!
  \**************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _PreviewField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./PreviewField.vue?vue&type=script&lang=js */ "./resources/js/components/PreviewField.vue?vue&type=script&lang=js");
/* harmony import */ var _node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../../node_modules/vue-loader/dist/exportHelper.js */ "./node_modules/vue-loader/dist/exportHelper.js");



;
const __exports__ = /*#__PURE__*/(0,_node_modules_vue_loader_dist_exportHelper_js__WEBPACK_IMPORTED_MODULE_1__["default"])(_PreviewField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"], [['__file',"resources/js/components/PreviewField.vue"]])
/* hot reload */
if (false) // removed by dead control flow
{}


/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (__exports__);

/***/ }),

/***/ "./resources/js/components/PreviewField.vue?vue&type=script&lang=js":
/*!**************************************************************************!*\
  !*** ./resources/js/components/PreviewField.vue?vue&type=script&lang=js ***!
  \**************************************************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* reexport safe */ _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreviewField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__["default"])
/* harmony export */ });
/* harmony import */ var _node_modules_babel_loader_lib_index_js_clonedRuleSet_5_use_0_node_modules_vue_loader_dist_index_js_ruleSet_0_use_0_PreviewField_vue_vue_type_script_lang_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! -!../../../node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!../../../node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./PreviewField.vue?vue&type=script&lang=js */ "./node_modules/babel-loader/lib/index.js??clonedRuleSet-5.use[0]!./node_modules/vue-loader/dist/index.js??ruleSet[0].use[0]!./resources/js/components/PreviewField.vue?vue&type=script&lang=js");
 

/***/ }),

/***/ "./resources/js/field.js":
/*!*******************************!*\
  !*** ./resources/js/field.js ***!
  \*******************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _components_IndexField__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./components/IndexField */ "./resources/js/components/IndexField.vue");
/* harmony import */ var _components_DetailField__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./components/DetailField */ "./resources/js/components/DetailField.vue");
/* harmony import */ var _components_FormField__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./components/FormField */ "./resources/js/components/FormField.vue");
/* harmony import */ var _components_PreviewField__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/PreviewField */ "./resources/js/components/PreviewField.vue");




Nova.booting(function (app, store) {
  app.component('index-icon-select', _components_IndexField__WEBPACK_IMPORTED_MODULE_0__["default"]);
  app.component('detail-icon-select', _components_DetailField__WEBPACK_IMPORTED_MODULE_1__["default"]);
  app.component('form-icon-select', _components_FormField__WEBPACK_IMPORTED_MODULE_2__["default"]);
  // app.component('preview-icon-select', PreviewField)
});

/***/ }),

/***/ "laravel-nova":
/*!******************************!*\
  !*** external "LaravelNova" ***!
  \******************************/
/***/ ((module) => {

module.exports = LaravelNova;

/***/ }),

/***/ "vue":
/*!**********************!*\
  !*** external "Vue" ***!
  \**********************/
/***/ ((module) => {

module.exports = Vue;

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = __webpack_modules__;
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/chunk loaded */
/******/ 	(() => {
/******/ 		var deferred = [];
/******/ 		__webpack_require__.O = (result, chunkIds, fn, priority) => {
/******/ 			if(chunkIds) {
/******/ 				priority = priority || 0;
/******/ 				for(var i = deferred.length; i > 0 && deferred[i - 1][2] > priority; i--) deferred[i] = deferred[i - 1];
/******/ 				deferred[i] = [chunkIds, fn, priority];
/******/ 				return;
/******/ 			}
/******/ 			var notFulfilled = Infinity;
/******/ 			for (var i = 0; i < deferred.length; i++) {
/******/ 				var [chunkIds, fn, priority] = deferred[i];
/******/ 				var fulfilled = true;
/******/ 				for (var j = 0; j < chunkIds.length; j++) {
/******/ 					if ((priority & 1 === 0 || notFulfilled >= priority) && Object.keys(__webpack_require__.O).every((key) => (__webpack_require__.O[key](chunkIds[j])))) {
/******/ 						chunkIds.splice(j--, 1);
/******/ 					} else {
/******/ 						fulfilled = false;
/******/ 						if(priority < notFulfilled) notFulfilled = priority;
/******/ 					}
/******/ 				}
/******/ 				if(fulfilled) {
/******/ 					deferred.splice(i--, 1)
/******/ 					var r = fn();
/******/ 					if (r !== undefined) result = r;
/******/ 				}
/******/ 			}
/******/ 			return result;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/jsonp chunk loading */
/******/ 	(() => {
/******/ 		// no baseURI
/******/ 		
/******/ 		// object to store loaded and loading chunks
/******/ 		// undefined = chunk not loaded, null = chunk preloaded/prefetched
/******/ 		// [resolve, reject, Promise] = chunk loading, 0 = chunk loaded
/******/ 		var installedChunks = {
/******/ 			"/js/field": 0,
/******/ 			"css/field": 0
/******/ 		};
/******/ 		
/******/ 		// no chunk on demand loading
/******/ 		
/******/ 		// no prefetching
/******/ 		
/******/ 		// no preloaded
/******/ 		
/******/ 		// no HMR
/******/ 		
/******/ 		// no HMR manifest
/******/ 		
/******/ 		__webpack_require__.O.j = (chunkId) => (installedChunks[chunkId] === 0);
/******/ 		
/******/ 		// install a JSONP callback for chunk loading
/******/ 		var webpackJsonpCallback = (parentChunkLoadingFunction, data) => {
/******/ 			var [chunkIds, moreModules, runtime] = data;
/******/ 			// add "moreModules" to the modules object,
/******/ 			// then flag all "chunkIds" as loaded and fire callback
/******/ 			var moduleId, chunkId, i = 0;
/******/ 			if(chunkIds.some((id) => (installedChunks[id] !== 0))) {
/******/ 				for(moduleId in moreModules) {
/******/ 					if(__webpack_require__.o(moreModules, moduleId)) {
/******/ 						__webpack_require__.m[moduleId] = moreModules[moduleId];
/******/ 					}
/******/ 				}
/******/ 				if(runtime) var result = runtime(__webpack_require__);
/******/ 			}
/******/ 			if(parentChunkLoadingFunction) parentChunkLoadingFunction(data);
/******/ 			for(;i < chunkIds.length; i++) {
/******/ 				chunkId = chunkIds[i];
/******/ 				if(__webpack_require__.o(installedChunks, chunkId) && installedChunks[chunkId]) {
/******/ 					installedChunks[chunkId][0]();
/******/ 				}
/******/ 				installedChunks[chunkId] = 0;
/******/ 			}
/******/ 			return __webpack_require__.O(result);
/******/ 		}
/******/ 		
/******/ 		var chunkLoadingGlobal = self["webpackChunkwebmapp_icon_select"] = self["webpackChunkwebmapp_icon_select"] || [];
/******/ 		chunkLoadingGlobal.forEach(webpackJsonpCallback.bind(null, 0));
/******/ 		chunkLoadingGlobal.push = webpackJsonpCallback.bind(null, chunkLoadingGlobal.push.bind(chunkLoadingGlobal));
/******/ 	})();
/******/ 	
/************************************************************************/
/******/ 	
/******/ 	// startup
/******/ 	// Load entry module and return exports
/******/ 	// This entry module depends on other loaded chunks and execution need to be delayed
/******/ 	__webpack_require__.O(undefined, ["css/field"], () => (__webpack_require__("./resources/js/field.js")))
/******/ 	var __webpack_exports__ = __webpack_require__.O(undefined, ["css/field"], () => (__webpack_require__("./resources/css/field.css")))
/******/ 	__webpack_exports__ = __webpack_require__.O(__webpack_exports__);
/******/ 	
/******/ })()
;