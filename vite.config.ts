import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import laravel from 'laravel-vite-plugin';
import { defineConfig, type Plugin } from 'vite';

import { PDFJS_PUBLIC_PATH } from './resources/js/components/editor/pdfjsAssets';

const projectRoot = path.dirname(fileURLToPath(import.meta.url));

/**
 * PDF.js runtime resources, served from this origin.
 *
 * The worker itself is a normal `?url` import and is emitted into `public/build` with every
 * other asset (see resources/js/components/editor/pdfjs.ts). These four directories are
 * different: PDF.js fetches them *by path at runtime*, one file at a time, and only for the
 * documents that need them, so there is no import for a bundler to follow. They are copied to
 * a stable path under `public/` instead of being bundled.
 *
 * They are not optional decoration. Without `standard_fonts` a PDF that references Helvetica
 * without embedding it renders in a substituted face at different metrics, which moves the
 * text a sender is placing a signature box against; `cmaps` is the same problem for CJK
 * encodings, and `wasm`/`iccs` cover JPEG2000/JBIG2 images and ICC colour. Leaving them out
 * would be a preview that quietly disagrees with the page.
 *
 * The alternative — pdfjs' own CDN defaults — is forbidden outright: signing and preparation
 * pages load nothing from a third-party origin (AGENTS.md).
 */
const PDFJS_RUNTIME_DIRECTORIES = ['cmaps', 'standard_fonts', 'wasm', 'iccs'] as const;

function pdfjsRuntimeAssets(): Plugin {
  return {
    name: 'esign-pdfjs-runtime-assets',
    // Runs for `vite dev` and `vite build` alike: the files are served by the web server from
    // public/, not by the dev server, so both need them on disk.
    buildStart(): void {
      const source = path.resolve(projectRoot, 'node_modules/pdfjs-dist');
      const target = path.resolve(projectRoot, 'public', PDFJS_PUBLIC_PATH);

      for (const directory of PDFJS_RUNTIME_DIRECTORIES) {
        const from = path.join(source, directory);

        if (!fs.existsSync(from)) {
          // A pdfjs-dist that stopped shipping one of these is a real change worth noticing,
          // but it must not break a build that does not use it.
          this.warn(`pdfjs-dist has no ${directory}/ directory; skipping.`);
          continue;
        }

        fs.rmSync(path.join(target, directory), { recursive: true, force: true });
        fs.mkdirSync(path.join(target, directory), { recursive: true });
        fs.cpSync(from, path.join(target, directory), { recursive: true });
      }

      // Apache-2.0 requires the licence to travel with the distribution, and the copies above
      // are distributed (they are served from public/). THIRD_PARTY_NOTICES.md says this file
      // is there; this is what puts it there.
      const license = path.join(source, 'LICENSE');

      if (fs.existsSync(license)) {
        fs.copyFileSync(license, path.join(target, 'LICENSE'));
      }
    },
  };
}

export default defineConfig({
  plugins: [
    laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.jsx',
                'resources/js/home.tsx',
                'resources/js/navbar.tsx',
                'resources/js/sign-in.tsx',
                'resources/js/dashboard.tsx',
                'resources/js/editor.tsx',
                'resources/js/signing.tsx',
            ],
      refresh: true,
    }),
    react(),
    tailwindcss(),
    pdfjsRuntimeAssets(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(projectRoot, 'resources/js'),
      'react': path.resolve(projectRoot, 'node_modules/react'),
      'react-dom': path.resolve(projectRoot, 'node_modules/react-dom'),
    },
  },
  build: {
    rollupOptions: {
      external: (id) => /\.test\.[tj]sx?$/.test(id) || id.includes('/__tests__/'),
    }
  }
});
