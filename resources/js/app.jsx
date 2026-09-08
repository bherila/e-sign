import './bootstrap';

import { createRoot } from 'react-dom/client';

import { Button } from '@/components/ui/button';

function Demo() {
  return (
    <div className="max-w-7xl mx-auto px-4 py-6">
      <div className="space-x-2">
        <Button>Button</Button>
        <Button variant="destructive">Destructive</Button>
        <Button variant="outline">Outline</Button>
      </div>
    </div>
  );
}

const el = document.getElementById('app');
if (el) {
  createRoot(el).render(<Demo />);
}
