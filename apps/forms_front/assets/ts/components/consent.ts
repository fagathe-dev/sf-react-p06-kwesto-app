import { StorageService } from 'core-ts';

type ConsentChoice = 'accepted' | 'refused';

const STORAGE_KEY = 'kwesto.consent';
const storage = new StorageService('local');

export const getConsent = (): ConsentChoice | null => {
  const value = storage.get(STORAGE_KEY);
  return value === 'accepted' || value === 'refused' ? value : null;
};

/**
 * Bandeau de consentement [data-consent] : affiché tant qu'aucun choix n'est mémorisé, rouvert par
 * tout bouton [data-consent-open]. Le choix est conservé dans le navigateur (StorageService de core-ts)
 * et diffusé par l'événement `kwesto:consent` pour les scripts de mesure d'audience éventuels.
 */
export const initConsent = (): void => {
  const banner = document.querySelector<HTMLElement>('[data-consent]');
  if (!banner) return;

  const show = (): void => {
    banner.hidden = false;
    banner.querySelector<HTMLButtonElement>('[data-consent-choice="accepted"]')?.focus();
  };

  if (getConsent() === null) {
    banner.hidden = false;
  }

  banner.addEventListener('click', (event) => {
    const button = (event.target as Element).closest<HTMLButtonElement>('[data-consent-choice]');
    if (!button) return;

    const choice = button.dataset.consentChoice as ConsentChoice;
    storage.set(STORAGE_KEY, choice);
    banner.hidden = true;
    document.dispatchEvent(new CustomEvent('kwesto:consent', { detail: choice }));
  });

  document.querySelectorAll<HTMLButtonElement>('[data-consent-open]').forEach((button) => {
    button.addEventListener('click', show);
  });
};
