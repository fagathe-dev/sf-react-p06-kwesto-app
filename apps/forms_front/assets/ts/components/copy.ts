import { copyWithButtonFeedback, isClipboardSupported } from 'core-ts';

/**
 * Boutons [data-copy="texte"] : copie dans le presse-papiers avec retour visuel (core-ts).
 * Sans API Clipboard, les boutons sont masqués.
 */
export const initCopyButtons = (): void => {
  const buttons = document.querySelectorAll<HTMLButtonElement>('[data-copy]');

  buttons.forEach((button) => {
    if (!isClipboardSupported()) {
      button.hidden = true;
      return;
    }

    button.addEventListener('click', () => {
      void copyWithButtonFeedback(button, button.dataset.copy ?? '', {
        successMessage: 'Copié !',
        errorMessage: 'Copie impossible',
      });
    });
  });
};
