import babel from '@rolldown/plugin-babel';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vite';

/**
 * Le back-office n'est jamais indexable (specs_tech §6) : X-Robots-Tag sur toutes les réponses
 * du serveur de développement et de prévisualisation. En production, le même en-tête doit être
 * posé par le serveur web (voir docs/forms-bo.md).
 */
const noIndexHeaders = { 'X-Robots-Tag': 'noindex, nofollow' };

export default defineConfig({
  plugins: [react(), babel({ presets: [reactCompilerPreset()] })],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
    },
  },
  server: { port: 5173, headers: noIndexHeaders },
  preview: { port: 4173, headers: noIndexHeaders },
});
