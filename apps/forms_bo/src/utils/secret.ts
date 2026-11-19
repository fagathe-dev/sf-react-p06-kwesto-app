/**
 * Mot de passe envoyé à l'API : encodé en base64 (octets UTF-8), règle commune à toute route qui reçoit un
 * mot de passe (décodé côté API par App\EventListener\Base64PasswordListener).
 * btoa seul refuse les caractères hors Latin-1 (« é » passe, « € » non) : on encode d'abord en UTF-8.
 */
export const encodePassword = (password: string): string => {
  const bytes = new TextEncoder().encode(password);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return btoa(binary);
};
