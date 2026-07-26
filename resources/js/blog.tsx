import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';

import MainTitle from '@/components/MainTitle';

function Blog() {
  return (
    <div className="max-w-6xl mx-auto px-4 py-8">
      <div className="mb-8">
        <MainTitle>Blog</MainTitle>
        <p className="text-muted-foreground mt-2 max-w-2xl">Coming soon.</p>
      </div>
    </div>
  );
}

const blogElement = document.getElementById('blog');
if (blogElement) {
  createRoot(blogElement).render(<Blog />);
}
