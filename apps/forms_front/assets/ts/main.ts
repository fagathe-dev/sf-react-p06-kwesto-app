// =============================================================================
// main.ts — chargé sur toutes les pages : navigation mobile, consentement, presse-papiers.
// TypeScript + core-ts uniquement (aucun Stimulus, aucun framework de contrôleurs).
// =============================================================================

import { initConsent } from './components/consent';
import { initCopyButtons } from './components/copy';
import { initMobileNav } from './components/nav';

const init = (): void => {
  initMobileNav();
  initConsent();
  initCopyButtons();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
