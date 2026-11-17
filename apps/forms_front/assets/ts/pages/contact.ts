// =============================================================================
// contact.ts — validation à la saisie du formulaire de contact (core-ts).
// Le serveur revalide tout (ContactService de forms-core) : ce script n'évite qu'un aller-retour.
// =============================================================================

import { isEmail, isEmpty } from 'core-ts';

type Rule = (value: string) => string | null;

const RULES: Record<string, Rule> = {
  name: (v) => (isEmpty(v.trim()) ? 'Le nom est obligatoire.' : null),
  email: (v) => (isEmpty(v.trim()) ? 'L\'adresse e-mail est obligatoire.' : isEmail(v.trim()) ? null : 'L\'adresse e-mail est invalide.'),
  subject: (v) => (isEmpty(v) ? 'L\'objet est obligatoire.' : null),
  message: (v) =>
    v.trim().length < 10 ? 'Le message doit contenir au moins 10 caractères.' : v.length > 5000 ? 'Le message ne peut pas dépasser 5000 caractères.' : null,
};

const fieldName = (control: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement): string | null =>
  /\[(\w+)\]$/.exec(control.name)?.[1] ?? null;

const setError = (control: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, message: string | null): void => {
  const wrapper = control.closest('.field');
  const error = wrapper?.querySelector<HTMLElement>('[data-field-error]');
  wrapper?.classList.toggle('field--invalid', message !== null);
  control.setAttribute('aria-invalid', String(message !== null));
  if (error) error.textContent = message ?? '';
};

const form = document.querySelector<HTMLFormElement>('[data-contact-form]');

if (form) {
  const controls = Array.from(form.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('.field__control'));

  const validate = (control: (typeof controls)[number]): boolean => {
    const name = fieldName(control);
    const rule = name ? RULES[name] : undefined;
    const message = rule ? rule(control.value) : null;
    setError(control, message);
    return message === null;
  };

  controls.forEach((control) => {
    control.addEventListener('blur', () => validate(control));
    control.addEventListener('input', () => {
      if (control.getAttribute('aria-invalid') === 'true') validate(control);
    });
  });

  form.addEventListener('submit', (event) => {
    const invalid = controls.filter((control) => !validate(control));
    if (invalid.length > 0) {
      event.preventDefault();
      invalid[0]?.focus();
    }
  });
}
