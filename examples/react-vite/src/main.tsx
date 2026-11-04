import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { LeihoBridge } from 'leiho/react';
import Page from './Page';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Page slug="home" />} />
        <Route path="/:slug" element={<Page />} />
      </Routes>
    </BrowserRouter>
    {/* Renders nothing. Downloads the bridge only inside Leiho's editor. */}
    <LeihoBridge wpOrigin={import.meta.env.VITE_WORDPRESS_URL} />
  </StrictMode>
);
