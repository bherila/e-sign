import './bootstrap';

import { createRoot } from 'react-dom/client';

import type { SignInMode } from '@/components/auth/SignInPanel';
import SignInPanel from '@/components/auth/SignInPanel';

const mount = document.getElementById('sign-in');

if (mount) {
  const mode: SignInMode = mount.getAttribute('data-mode') === 'sso' ? 'sso' : 'local';

  createRoot(mount).render(
    <SignInPanel
      mode={mode}
      ssoUrl={mount.getAttribute('data-sso-url') ?? ''}
      loginUrl={mount.getAttribute('data-login-url') ?? ''}
      csrfToken={mount.getAttribute('data-csrf-token') ?? ''}
      email={mount.getAttribute('data-email') ?? ''}
      error={mount.getAttribute('data-error') ?? ''}
      status={mount.getAttribute('data-status') ?? ''}
    />,
  );
}
