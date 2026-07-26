import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';

import MainTitle from '@/components/MainTitle';

function Home() {
  return (
    <div className="max-w-6xl mx-auto px-4 py-8">
      <div className="mb-8">
        <MainTitle>Katherine Herila</MainTitle>
        <p className="text-muted-foreground mt-2 max-w-2xl">
          This site is just getting started. It will grow into a photo and video blog — check
          back soon.
        </p>
      </div>
    </div>
  );
}

const homeElement = document.getElementById('home');
if (homeElement) {
  createRoot(homeElement).render(<Home />);
}
