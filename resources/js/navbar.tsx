import { createRoot } from 'react-dom/client';

import Navbar from '@/components/navbar';

const mount = document.getElementById('navbar');
if (mount) {
  createRoot(mount).render(<Navbar />);
}
