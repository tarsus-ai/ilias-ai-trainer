/**
 * Tarsus for ILIAS - Requests to the plugin's endpoints.
 *
 * @author  Tarsus GmbH
 * @license GPL-3.0-or-later
 */

/**
 * POST a JSON payload to one of the plugin's endpoints.
 *
 * @return {Promise<Object>}
 */
export function post(url, payload, strings) {
  const body = new URLSearchParams();
  body.set('payload', JSON.stringify(payload || {}));
  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
    body: body.toString(),
  }).then((response) => readJson(response, strings));
}

/**
 * POST a multipart form (a recording) to one of the plugin's endpoints.
 *
 * @return {Promise<Object>}
 */
export function postForm(url, form, strings) {
  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    body: form,
  }).then((response) => readJson(response, strings));
}

function readJson(response, strings) {
  return response.text().then((text) => {
    let data = null;
    try {
      data = JSON.parse(text);
    } catch (e) {
      data = null;
    }
    if (!data || typeof data !== 'object') {
      // A login page instead of JSON: the session is gone.
      throw new Error(strings.session_lost || 'Session expired');
    }
    return data;
  });
}
