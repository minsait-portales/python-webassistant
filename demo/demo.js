/*
 * Portal anfitrión de demostración (CG-16.3).
 *
 * Muestra cómo integra una web cualquiera el widget de WebAssistant:
 *  - registra las host actions que el asistente puede pedir (carrito, formularios, perfil, informes);
 *  - escucha los eventos del widget;
 *  - simula el login del portal para obtener un token temporal con identidad y permisos.
 *
 * Se carga antes que loader.js: las llamadas se encolan en `window.WebAssistant` hasta que el
 * loader se inicializa. Todo el DOM se construye con textContent (sin innerHTML).
 */
(function () {
  'use strict';

  var CART_KEY = 'demo-cart';
  var PROFILE_KEY = 'demo-profile';
  var catalog = null;

  // --- API pública del widget con cola --------------------------------------------------
  window.WebAssistant = window.WebAssistant || [];
  function wa(method) {
    var args = Array.prototype.slice.call(arguments, 1);
    var global = window.WebAssistant;
    if (Array.isArray(global)) global.push([method].concat(args));
    else global[method].apply(global, args);
  }

  // --- Utilidades --------------------------------------------------------------------------
  function el(tag, attrs) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'text') node.textContent = attrs[key];
      else if (attrs[key] !== null && attrs[key] !== undefined) node.setAttribute(key, attrs[key]);
    });
    for (var i = 2; i < arguments.length; i++) {
      var child = arguments[i];
      if (child === null || child === undefined) continue;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }
    return node;
  }

  function money(amount) {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(amount || 0);
  }

  function absolute(url) {
    return url ? new URL(url, window.location.origin).href : undefined;
  }

  function loadCatalog() {
    if (!catalog) {
      catalog = fetch('/demo/catalog.json', { credentials: 'same-origin' }).then(function (r) {
        return r.json();
      });
    }
    return catalog;
  }

  function findProduct(products, id) {
    return products.find(function (p) {
      return p.id === id;
    });
  }

  function toast(message) {
    var previous = document.querySelector('.toast');
    if (previous) previous.remove();
    var node = el('div', { class: 'toast', role: 'status' }, message);
    document.body.appendChild(node);
    window.setTimeout(function () {
      node.remove();
    }, 5000);
  }

  // --- Carrito del portal (simulado con localStorage) --------------------------------------
  function readCart() {
    try {
      return JSON.parse(window.localStorage.getItem(CART_KEY) || '[]');
    } catch {
      return [];
    }
  }

  function writeCart(items) {
    try {
      window.localStorage.setItem(CART_KEY, JSON.stringify(items));
    } catch {
      /* almacenamiento no disponible */
    }
    renderCartCount();
    document.dispatchEvent(new CustomEvent('demo:cart-changed'));
  }

  function addToCart(products, productId, quantity) {
    var product = findProduct(products, productId);
    if (!product) return null;
    var items = readCart();
    var line = items.find(function (i) {
      return i.product_id === productId;
    });
    var qty = Math.max(1, Math.min(99, Number(quantity) || 1));
    if (line) line.quantity = Math.min(99, line.quantity + qty);
    else items.push({ product_id: product.id, variation_id: product.variation_id, quantity: qty });
    writeCart(items);
    return product;
  }

  function cartView(products) {
    var total = 0;
    var items = readCart()
      .map(function (line) {
        var product = findProduct(products, line.product_id);
        if (!product) return null;
        total += (product.price || 0) * line.quantity;
        return {
          product_id: product.id,
          variation_id: product.variation_id,
          title: product.title,
          url: absolute(product.url),
          image_url: absolute(product.image),
          image_alt: product.title,
          price: money(product.price),
          quantity: line.quantity,
          category: product.category,
        };
      })
      .filter(Boolean);
    return { items: items, total: money(total), items_count: items.length };
  }

  function renderCartCount() {
    var count = readCart().reduce(function (sum, line) {
      return sum + line.quantity;
    }, 0);
    document.querySelectorAll('[data-cart-count]').forEach(function (node) {
      node.textContent = String(count);
    });
  }

  // --- Host actions ------------------------------------------------------------------------
  wa('registerHostAction', 'cart.get', function () {
    return loadCatalog().then(function (products) {
      return { status: 'succeeded', data: cartView(products) };
    });
  });

  wa('registerHostAction', 'cart.add', function (action) {
    return loadCatalog().then(function (products) {
      var product = addToCart(products, action.params.product_id, action.params.quantity);
      if (!product) return { status: 'failed', message: 'El producto ya no está disponible.' };
      toast('Añadido al carrito: ' + product.title);
      return {
        status: 'succeeded',
        data: cartView(products),
        message: 'Añadido al carrito: ' + product.title,
      };
    });
  });

  wa('registerHostAction', 'cart.add_many', function (action) {
    return loadCatalog().then(function (products) {
      var added = (action.params.items || []).filter(function (item) {
        return addToCart(products, item.product_id, item.quantity);
      });
      toast(added.length + ' productos añadidos al carrito.');
      return {
        status: 'succeeded',
        data: cartView(products),
        message: added.length + ' productos añadidos al carrito.',
      };
    });
  });

  wa('registerHostAction', 'cart.update', function (action) {
    return loadCatalog().then(function (products) {
      var items = readCart();
      var line = items.find(function (i) {
        return i.product_id === action.params.product_id;
      });
      if (!line) return { status: 'failed', message: 'El producto no está en el carrito.' };
      line.quantity = Math.max(1, Math.min(99, Number(action.params.quantity) || 1));
      writeCart(items);
      return { status: 'succeeded', data: cartView(products), message: 'Cantidad actualizada.' };
    });
  });

  wa('registerHostAction', 'cart.remove', function (action) {
    return loadCatalog().then(function (products) {
      writeCart(
        readCart().filter(function (i) {
          return i.product_id !== action.params.product_id;
        }),
      );
      return { status: 'succeeded', data: cartView(products), message: 'Producto eliminado del carrito.' };
    });
  });

  wa('registerHostAction', 'cart.checkout', function () {
    toast('Pago simulado: en un portal real se abriría el checkout.');
    return { status: 'succeeded', message: 'Te llevo al pago (simulado en la demo).' };
  });

  wa('registerHostAction', 'content.create', function (action) {
    var type = action.params.content_type || 'page';
    toast('Formulario de creación abierto (' + type + '): ' + (action.params.content_title || 'sin título'));
    return { status: 'succeeded', data: { form: 'create', content_type: type } };
  });

  wa('registerHostAction', 'content.edit', function (action) {
    toast('Formulario de edición abierto: ' + (action.params.content_title || ''));
    return { status: 'succeeded', data: { form: 'edit', content_title: action.params.content_title || '' } };
  });

  wa('registerHostAction', 'user.profile', function () {
    var profile = sessionProfile();
    toast(profile ? 'Abriendo el perfil de ' + profile : 'Inicia sesión para ver tu perfil.');
    return profile
      ? { status: 'succeeded', data: { profile: profile } }
      : { status: 'failed', message: 'Inicia sesión para ver tu perfil.' };
  });

  wa('registerHostAction', 'analytics.sales_report', function (action) {
    var range = action.params.range || 'last_30_days';
    toast('Informe de ventas (' + range + ') generado en el portal.');
    return { status: 'succeeded', data: { range: range, orders: 128, revenue: money(5340.5) } };
  });

  // --- Eventos del widget ------------------------------------------------------------------
  wa('on', '*', function (event) {
    var log = document.querySelector('[data-event-log]');
    if (!log) return;
    var line = el('li', {
      text:
        event.timestamp.slice(11, 19) +
        '  ' +
        event.type +
        '  ' +
        JSON.stringify(event.payload).slice(0, 160),
    });
    log.insertBefore(line, log.firstChild);
    while (log.children.length > 40) log.removeChild(log.lastChild);
  });

  // --- Login simulado del portal ---------------------------------------------------------------
  function sessionProfile() {
    try {
      return window.sessionStorage.getItem(PROFILE_KEY) || '';
    } catch {
      return '';
    }
  }

  function portalToken(profile) {
    // En un portal real este endpoint pertenece al backend del portal, que usa sus
    // credenciales de cliente API (POST /v1/token) sin exponerlas al navegador.
    return function () {
      return fetch('/demo/api/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile: profile }),
      })
        .then(function (r) {
          if (!r.ok) throw new Error('login');
          return r.json();
        })
        .then(function (data) {
          return data.token;
        });
    };
  }

  var initialProfile = sessionProfile();
  if (initialProfile) wa('setToken', portalToken(initialProfile));

  // --- Páginas -----------------------------------------------------------------------------
  function productCard(product) {
    var button = el(
      'button',
      { type: 'button', 'aria-label': 'Añadir al carrito: ' + product.title },
      'Añadir al carrito',
    );
    button.addEventListener('click', function () {
      loadCatalog().then(function (products) {
        addToCart(products, product.id, 1);
        toast('Añadido al carrito: ' + product.title);
      });
    });
    return el(
      'li',
      { class: 'product' },
      el('img', { src: product.image, alt: '' }),
      el(
        'div',
        {},
        el('h3', {}, el('a', { href: product.url, text: product.title })),
        el('p', { text: (product.brand || '') + ' · ' + (product.category || '') }),
        el('p', { class: 'price', text: money(product.price) }),
        button,
      ),
    );
  }

  function renderProducts(container, products) {
    var limit = Number(container.getAttribute('data-limit')) || products.length;
    products.slice(0, limit).forEach(function (product) {
      container.appendChild(productCard(product));
    });
  }

  function renderDetail(container, products) {
    var id = new URLSearchParams(window.location.search).get('id');
    var product = findProduct(products, id);
    if (!product) {
      container.appendChild(el('p', { text: 'Producto no encontrado.' }));
      return;
    }
    document.title = product.title + ' · Tienda de demostración';
    var button = el('button', { type: 'button', class: 'button' }, 'Añadir al carrito');
    button.addEventListener('click', function () {
      addToCart(products, product.id, 1);
      toast('Añadido al carrito: ' + product.title);
    });
    container.appendChild(
      el(
        'div',
        { class: 'detail' },
        el('img', { src: product.image, alt: product.title }),
        el(
          'div',
          {},
          el('h1', { text: product.title }),
          el('p', { text: product.description }),
          el('p', {
            text: 'Marca: ' + (product.brand || '-') + ' · Categoría: ' + (product.category || '-'),
          }),
          el('p', { text: product.availability || '' }),
          el('p', { class: 'price', text: money(product.price) }),
          button,
        ),
      ),
    );
  }

  function renderCart(container, products) {
    function paint() {
      while (container.firstChild) container.removeChild(container.firstChild);
      var view = cartView(products);
      if (!view.items.length) {
        container.appendChild(el('p', { text: 'Tu carrito está vacío.' }));
        return;
      }
      var body = el('tbody');
      view.items.forEach(function (item) {
        var remove = el(
          'button',
          { type: 'button', class: 'button secondary', 'aria-label': 'Eliminar ' + item.title },
          'Eliminar',
        );
        remove.addEventListener('click', function () {
          writeCart(
            readCart().filter(function (l) {
              return l.product_id !== item.product_id;
            }),
          );
        });
        body.appendChild(
          el(
            'tr',
            {},
            el('td', { text: item.title }),
            el('td', { text: String(item.quantity) }),
            el('td', { text: item.price }),
            el('td', {}, remove),
          ),
        );
      });
      container.appendChild(
        el(
          'table',
          { class: 'cart-table' },
          el('caption', { class: 'sr-only', text: 'Productos del carrito' }),
          el(
            'thead',
            {},
            el(
              'tr',
              {},
              el('th', { scope: 'col', text: 'Producto' }),
              el('th', { scope: 'col', text: 'Cantidad' }),
              el('th', { scope: 'col', text: 'Precio' }),
              el('th', { scope: 'col', text: 'Acciones' }),
            ),
          ),
          body,
        ),
      );
      container.appendChild(el('p', { class: 'price', text: 'Total: ' + view.total }));
    }
    paint();
    document.addEventListener('demo:cart-changed', paint);
  }

  function renderGuides(container) {
    fetch('/demo/guides.json')
      .then(function (r) {
        return r.json();
      })
      .then(function (guides) {
        guides.forEach(function (guide) {
          var section = el(
            'section',
            { class: 'guide', id: guide.id, 'aria-labelledby': guide.id + '-title' },
            el('h2', { id: guide.id + '-title', text: guide.title }),
          );
          guide.sections.forEach(function (part) {
            section.appendChild(el('h3', { text: part.heading }));
            section.appendChild(el('p', { text: part.body }));
          });
          container.appendChild(section);
        });
        var target = window.location.hash && document.getElementById(window.location.hash.slice(1));
        if (target) target.scrollIntoView();
      });
  }

  function setupSession() {
    var select = document.getElementById('demo-profile');
    if (!select) return;
    select.value = sessionProfile();
    select.addEventListener('change', function () {
      var profile = select.value;
      try {
        if (profile) window.sessionStorage.setItem(PROFILE_KEY, profile);
        else window.sessionStorage.removeItem(PROFILE_KEY);
      } catch {
        /* sin almacenamiento */
      }
      // Cambiar de identidad recarga la página, como haría un login real del portal.
      window.location.reload();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    renderCartCount();
    setupSession();
    var needsCatalog = document.querySelector('[data-products], [data-product-detail], [data-cart]');
    if (needsCatalog) {
      loadCatalog().then(function (products) {
        document.querySelectorAll('[data-products]').forEach(function (c) {
          renderProducts(c, products);
        });
        document.querySelectorAll('[data-product-detail]').forEach(function (c) {
          renderDetail(c, products);
        });
        document.querySelectorAll('[data-cart]').forEach(function (c) {
          renderCart(c, products);
        });
      });
    }
    document.querySelectorAll('[data-guides]').forEach(renderGuides);
    document.querySelectorAll('[data-open-assistant]').forEach(function (button) {
      button.addEventListener('click', function () {
        wa('open');
      });
    });
  });
})();
