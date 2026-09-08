import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';

import MainTitle from '@/components/MainTitle';

function Home() {
  return (
    <div className="max-w-6xl mx-auto px-4 py-8">
      <div className="mb-8">
        <MainTitle>BWH eSign</MainTitle>
        <p className="text-muted-foreground mt-2 max-w-2xl">
          Scaffold only. Nothing signs a document yet; see the repository README and docs/HANDOFF.md for the build order.
        </p>
      </div>
    </div>
  );
}

const homeElement = document.getElementById('home');
if (homeElement) {
  createRoot(homeElement).render(<Home />);
}
