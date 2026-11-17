/**
 * Onglets de démonstration [data-tabs] (motif ARIA « tabs ») : clic, flèches gauche/droite, Début, Fin.
 * Sans JavaScript, tous les panneaux sont affichés.
 */
export const initTabs = (): void => {
  document.querySelectorAll<HTMLElement>('[data-tabs]').forEach((container) => {
    const tabs = Array.from(container.querySelectorAll<HTMLButtonElement>('[role="tab"]'));

    const select = (tab: HTMLButtonElement, focus = false): void => {
      tabs.forEach((other) => {
        const selected = other === tab;
        other.setAttribute('aria-selected', String(selected));
        other.tabIndex = selected ? 0 : -1;
        const panel = document.getElementById(other.getAttribute('aria-controls') ?? '');
        if (panel) panel.hidden = !selected;
      });
      if (focus) tab.focus();
    };

    container.classList.add('is-enhanced');
    select(tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') ?? tabs[0]!);

    container.addEventListener('click', (event) => {
      const tab = (event.target as Element).closest<HTMLButtonElement>('[role="tab"]');
      if (tab) select(tab);
    });

    container.addEventListener('keydown', (event) => {
      const index = tabs.indexOf(document.activeElement as HTMLButtonElement);
      if (index === -1) return;

      const targets: Record<string, number> = {
        ArrowRight: (index + 1) % tabs.length,
        ArrowLeft: (index - 1 + tabs.length) % tabs.length,
        Home: 0,
        End: tabs.length - 1,
      };

      if (event.key in targets) {
        event.preventDefault();
        select(tabs[targets[event.key]!]!, true);
      }
    });
  });
};
