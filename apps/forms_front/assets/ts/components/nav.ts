/**
 * Navigation mobile : le bouton [data-nav-toggle] ouvre et ferme le menu [data-nav].
 * Fermeture par Échap, par un clic sur un lien et au passage en affichage large.
 */
export const initMobileNav = (): void => {
  const toggle = document.querySelector<HTMLButtonElement>('[data-nav-toggle]');
  const nav = document.querySelector<HTMLElement>('[data-nav]');
  if (!toggle || !nav) return;

  const label = toggle.querySelector('.visually-hidden');

  const setOpen = (open: boolean): void => {
    toggle.setAttribute('aria-expanded', String(open));
    nav.classList.toggle('is-open', open);
    document.body.classList.toggle('has-open-nav', open);
    if (label) label.textContent = open ? 'Fermer le menu' : 'Ouvrir le menu';
  };

  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));

  nav.addEventListener('click', (event) => {
    if ((event.target as Element).closest('a')) setOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && nav.classList.contains('is-open')) {
      setOpen(false);
      toggle.focus();
    }
  });

  window.matchMedia('(min-width: 960px)').addEventListener('change', (event) => {
    if (event.matches) setOpen(false);
  });
};
