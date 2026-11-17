/**
 * Accordéons de FAQ [data-accordion] : boutons [data-accordion-trigger] reliés à leur panneau par
 * aria-controls. Sans JavaScript, tous les panneaux restent ouverts et lisibles.
 */
export const initAccordions = (): void => {
  document.querySelectorAll<HTMLElement>('[data-accordion]').forEach((accordion) => {
    const triggers = Array.from(accordion.querySelectorAll<HTMLButtonElement>('[data-accordion-trigger]'));

    const setOpen = (trigger: HTMLButtonElement, open: boolean): void => {
      const panel = document.getElementById(trigger.getAttribute('aria-controls') ?? '');
      trigger.setAttribute('aria-expanded', String(open));
      if (panel) panel.hidden = !open;
    };

    accordion.classList.add('is-enhanced');
    triggers.forEach((trigger) => setOpen(trigger, false));

    accordion.addEventListener('click', (event) => {
      const trigger = (event.target as Element).closest<HTMLButtonElement>('[data-accordion-trigger]');
      if (!trigger) return;

      const opening = trigger.getAttribute('aria-expanded') !== 'true';
      // Une seule réponse ouverte à la fois
      triggers.forEach((other) => setOpen(other, other === trigger && opening));
    });

    // Navigation clavier entre les questions : flèches, Début, Fin
    accordion.addEventListener('keydown', (event) => {
      const index = triggers.indexOf(document.activeElement as HTMLButtonElement);
      if (index === -1) return;

      const targets: Record<string, number> = {
        ArrowDown: (index + 1) % triggers.length,
        ArrowUp: (index - 1 + triggers.length) % triggers.length,
        Home: 0,
        End: triggers.length - 1,
      };

      if (event.key in targets) {
        event.preventDefault();
        triggers[targets[event.key]!]?.focus();
      }
    });
  });
};
