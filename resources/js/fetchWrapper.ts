
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) {
    return meta.getAttribute('content');
  }
  return null;
}

export const fetchWrapper = {
  get,
  post,
  put,
  delete: _delete,
}

function get(url: string) {
  const requestOptions = {
    method: 'GET',
    credentials: 'include' as RequestCredentials,
  }
  return fetch(url, requestOptions).then(handleResponse)
}

function post(url: string, body: any) {
  const isFormData = body instanceof FormData;
  const requestOptions: RequestInit = {
    method: 'POST',
    headers: isFormData ? { 'X-CSRF-TOKEN': getCsrfToken() || '' } : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
    body: isFormData ? body : JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

function put(url: string, body: any) {
  const requestOptions: RequestInit = {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
    body: JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

// prefixed with underscored because delete is a reserved word in javascript
function _delete(url: string, body: any) {
  const requestOptions: RequestInit = {
    method: 'DELETE',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': getCsrfToken() || ''
    },
    credentials: 'include',
    body: JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

// helper functions
function handleResponse(response: Response) {
  return response.text().then((text) => {
    let data: any = null
    if (text) {
      try {
        data = JSON.parse(text)
      } catch (e) {
        // response wasn't JSON (could be an HTML redirect to login), keep raw text
        data = text
      }
    }

    if (!response.ok) {
      const error = (data && data.message) || response.statusText
      return Promise.reject(error)
    }

    return data
  })
}
