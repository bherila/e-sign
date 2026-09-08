import { createRoot } from 'react-dom/client';

import Navbar from '@/components/navbar';

const mount = document.getElementById('navbar');
if (mount) {
  const authenticated = (mount.getAttribute('data-authenticated') || 'false') === 'true';
  const isAdmin = (mount.getAttribute('data-is-admin') || 'false') === 'true';
  createRoot(mount).render(<Navbar authenticated={authenticated} isAdmin={isAdmin} />);
}
