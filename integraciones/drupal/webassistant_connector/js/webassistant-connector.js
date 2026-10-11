/**
 * @file
 * Loads the WebAssistant widget and bridges it with Drupal (design section 6).
 *
 * - Injects one loader <script> per widget instance (drupalSettings
 *   webassistantConnector.instances); re-attaching never duplicates it.
 * - Registers a token provider per instance that asks Drupal for a widget
 *   token (the token is never embedded in the HTML).
 * - Registers the host actions allowed to the user; each one is executed by
 *   Drupal through a same-origin POST.
 * - Optionally publishes widget events in window.dataLayer, without
 *   parameters or personal data.
 */

((Drupal, drupalSettings, once) => {
  /**
   * sessionStorage key that remembers an authenticated widget session.
   */
  const AUTH_FLAG = 'webassistant_connector.auth';

  /**
   * Prefix of the keys the widget stores in the browser.
   */
  const WIDGET_KEY_PREFIX = 'wa:';

  /**
   * Seconds a cached token must still be valid to be reused.
   */
  const TOKEN_MARGIN_MS = 60000;

  /**
   * Fallback delay before following a host action redirect.
   */
  const REDIRECT_FALLBACK_MS = 1500;

  const state = {
    config: null,
    csrfToken: null,
    token: null,
    tokenPromise: null,
    redirect: null,
    redirectTimer: null,
  };

  /**
   * Calls the widget API with the queue pattern.
   *
   * Works before the loader runs (window.WebAssistant is an array of calls)
   * and after it (window.WebAssistant is the API object).
   *
   * @param {string} method
   *   The API method: setToken, registerHostAction, on, open...
   * @param {...*} args
   *   The arguments.
   */
  function call(method, ...args) {
    window.WebAssistant = window.WebAssistant || [];
    const api = window.WebAssistant;
    if (Array.isArray(api)) {
      api.push([method, ...args]);
    } else if (typeof api[method] === 'function') {
      api[method](...args);
    } else if (typeof api.push === 'function') {
      api.push([method, ...args]);
    }
  }

  /**
   * Computes the loader instance ID (same formula as the loader).
   *
   * @param {string} siteId
   *   The site ID.
   * @param {object} instance
   *   The instance settings.
   *
   * @return {string}
   *   The instance ID.
   */
  function instanceId(siteId, instance) {
    return `${siteId}:${instance.assistantId || 'default'}:${instance.resultTarget || 'panel'}`;
  }

  /**
   * Returns a short hash usable in a once() ID.
   *
   * @param {string} value
   *   The value.
   *
   * @return {string}
   *   A hexadecimal hash.
   */
  function hash(value) {
    let result = 5381;
    for (let i = 0; i < value.length; i++) {
      result = ((result << 5) + result + value.charCodeAt(i)) >>> 0;
    }
    return result.toString(16);
  }

  /**
   * Removes the widget keys (wa:*) from a browser storage.
   *
   * @param {Storage} storage
   *   localStorage or sessionStorage.
   */
  function clearWidgetKeys(storage) {
    const keys = [];
    for (let i = 0; i < storage.length; i++) {
      const key = storage.key(i);
      if (key && key.indexOf(WIDGET_KEY_PREFIX) === 0) {
        keys.push(key);
      }
    }
    keys.forEach((key) => storage.removeItem(key));
  }

  /**
   * Forgets the previous user's widget data after a logout.
   *
   * @param {object} config
   *   The drupalSettings.webassistantConnector object.
   */
  function syncAuthState(config) {
    try {
      if (config.tokenUrl) {
        window.sessionStorage.setItem(AUTH_FLAG, '1');
      } else if (window.sessionStorage.getItem(AUTH_FLAG)) {
        clearWidgetKeys(window.sessionStorage);
        clearWidgetKeys(window.localStorage);
        window.sessionStorage.removeItem(AUTH_FLAG);
      }
    } catch {
      // Storage is not available (privacy mode): nothing to clean.
    }
  }

  /**
   * Returns the Drupal CSRF token, cached in memory.
   *
   * @param {boolean} refresh
   *   TRUE to ignore the cached token.
   *
   * @return {Promise<string>}
   *   The token.
   */
  function csrfToken(refresh = false) {
    if (!state.csrfToken || refresh) {
      state.csrfToken = fetch(state.config.csrfTokenUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`CSRF token request failed: HTTP ${response.status}`);
          }
          return response.text();
        })
        .catch((error) => {
          state.csrfToken = null;
          throw error;
        });
    }
    return state.csrfToken;
  }

  /**
   * Requests a widget token from Drupal.
   *
   * @param {boolean} retry
   *   Whether a 403 may be retried once with a fresh CSRF token.
   *
   * @return {Promise<string>}
   *   The token, or '' when Drupal refuses it (401/403): the widget then
   *   falls back to an anonymous session. Rejects on 429, 5xx and network
   *   errors.
   */
  async function requestToken(retry = true) {
    const response = await fetch(state.config.tokenUrl, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-CSRF-Token': await csrfToken(),
      },
    });
    if (response.status === 403 && retry) {
      // The CSRF token may belong to a previous session.
      await csrfToken(true);
      return requestToken(false);
    }
    if (response.status === 401 || response.status === 403) {
      state.token = null;
      return '';
    }
    if (response.status === 429 || response.status >= 500) {
      throw new Error(`WebAssistant token request failed: HTTP ${response.status}`);
    }
    if (!response.ok) {
      return '';
    }
    const data = await response.json();
    if (!data || typeof data.token !== 'string' || data.token === '') {
      throw new Error('WebAssistant token response is invalid.');
    }
    const expiresAt = Date.parse(data.expires_at);
    state.token = {
      value: data.token,
      expiresAt: Number.isNaN(expiresAt) ? Date.now() + 5 * 60000 : expiresAt,
    };
    return data.token;
  }

  /**
   * Token provider registered with setToken() for every instance.
   *
   * @return {Promise<string>}
   *   The widget token.
   */
  function tokenProvider() {
    if (state.token && state.token.expiresAt - Date.now() > TOKEN_MARGIN_MS) {
      return Promise.resolve(state.token.value);
    }
    if (!state.tokenPromise) {
      state.tokenPromise = requestToken().finally(() => {
        state.tokenPromise = null;
      });
    }
    return state.tokenPromise;
  }

  /**
   * Navigates to a pending host action redirect (same origin only).
   */
  function followRedirect() {
    const url = state.redirect;
    state.redirect = null;
    if (state.redirectTimer) {
      window.clearTimeout(state.redirectTimer);
      state.redirectTimer = null;
    }
    if (url) {
      window.location.assign(url);
    }
  }

  /**
   * Schedules a redirect returned by a host action.
   *
   * The navigation happens once the widget has reported the result to the
   * platform (action_result event), or after a short delay.
   *
   * @param {string} redirect
   *   The URL returned by Drupal.
   */
  function scheduleRedirect(redirect) {
    let url;
    try {
      url = new URL(redirect, window.location.href);
    } catch {
      return;
    }
    if (url.origin !== window.location.origin) {
      return;
    }
    state.redirect = url.href;
    if (state.redirectTimer) {
      window.clearTimeout(state.redirectTimer);
    }
    state.redirectTimer = window.setTimeout(followRedirect, REDIRECT_FALLBACK_MS);
  }

  /**
   * Builds the handler of a host action.
   *
   * @param {string} name
   *   The host action name.
   *
   * @return {function(object): Promise<object>}
   *   The handler: returns {status, data?, message?}.
   */
  function hostActionHandler(name) {
    const failed = () => ({
      status: 'failed',
      message: Drupal.t('The action could not be completed.'),
    });
    return async (action) => {
      let response;
      try {
        response = await fetch(state.config.hostActionUrl + encodeURIComponent(name), {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': await csrfToken(),
            'X-WebAssistant-Request': '1',
          },
          body: JSON.stringify(action || {}),
        });
      } catch {
        return failed();
      }
      if (!response.ok) {
        return failed();
      }
      let body;
      try {
        body = await response.json();
      } catch {
        return failed();
      }
      if (!body || typeof body !== 'object') {
        return failed();
      }
      const result = {
        status: body.status === 'failed' ? 'failed' : 'succeeded',
      };
      if (body.data && typeof body.data === 'object') {
        result.data = body.data;
      }
      if (typeof body.message === 'string' && body.message !== '') {
        result.message = body.message;
      }
      if (result.status === 'succeeded' && typeof body.redirect === 'string') {
        scheduleRedirect(body.redirect);
      }
      return result;
    };
  }

  /**
   * Publishes a widget event in window.dataLayer (no parameters, no PII).
   *
   * @param {object} event
   *   The widget event.
   */
  function pushDataLayer(event) {
    if (!event || typeof event.type !== 'string') {
      return;
    }
    const payload = event.payload || {};
    const data = { type: event.type };
    if (typeof event.instance_id === 'string') {
      data.instance_id = event.instance_id;
    }
    if (typeof payload.skill === 'string') {
      data.skill = payload.skill;
    }
    if (typeof payload.action === 'string') {
      data.action = payload.action;
    } else if (event.type === 'host_action' && typeof payload.name === 'string') {
      data.action = payload.name;
    }
    if (typeof payload.status === 'string') {
      data.status = payload.status;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: `webassistant_${event.type}`, webassistant: data });
  }

  /**
   * Page-level setup, done once.
   *
   * @param {object} config
   *   The drupalSettings.webassistantConnector object.
   */
  function setup(config) {
    syncAuthState(config);
    (config.hostActions || []).forEach((name) => {
      if (typeof name === 'string' && name) {
        call('registerHostAction', name, hostActionHandler(name));
      }
    });
    call('on', 'action_result', () => {
      if (state.redirect) {
        followRedirect();
      }
    });
    if (config.dataLayer) {
      call('on', '*', pushDataLayer);
    }
  }

  /**
   * Injects the loader of one widget instance.
   *
   * @param {object} config
   *   The drupalSettings.webassistantConnector object.
   * @param {string} id
   *   The instance ID.
   * @param {object} instance
   *   The instance settings.
   */
  function injectLoader(config, id, instance) {
    const loaded = Array.prototype.some.call(
      document.querySelectorAll('script[data-webassistant-instance]'),
      (script) => script.getAttribute('data-webassistant-instance') === id,
    );
    if (loaded) {
      return;
    }
    if (config.tokenUrl) {
      call('setToken', tokenProvider, id);
    }
    const script = document.createElement('script');
    script.async = true;
    script.src = config.loaderUrl;
    script.setAttribute('data-site-id', config.siteId);
    script.setAttribute('data-api-base', config.apiBase);
    script.setAttribute('data-webassistant-instance', id);
    script.setAttribute('data-open', instance.open ? 'true' : 'false');
    if (instance.assistantId) {
      script.setAttribute('data-assistant-id', instance.assistantId);
    }
    if (instance.locale) {
      script.setAttribute('data-locale', instance.locale);
    }
    if (instance.resultTarget) {
      script.setAttribute('data-result-target', instance.resultTarget);
    }
    if (instance.position) {
      script.setAttribute('data-position', instance.position);
    }
    document.body.appendChild(script);
  }

  /**
   * Loads the WebAssistant widget instances of the page.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Injects the loader of every instance not loaded yet.
   */
  Drupal.behaviors.webassistantConnector = {
    attach(context, settings) {
      const config = (settings || drupalSettings).webassistantConnector;
      if (!config || !config.loaderUrl || !config.siteId || !config.instances) {
        return;
      }
      state.config = config;
      if (once('webassistant-connector', 'html').length) {
        setup(config);
      }
      Object.keys(config.instances).forEach((key) => {
        const instance = config.instances[key] || {};
        const id = instanceId(config.siteId, instance);
        if (once(`webassistant-connector-${hash(id)}`, 'html').length) {
          injectLoader(config, id, instance);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
